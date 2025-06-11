<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\OrganizationCreatedMail;
use App\Mail\UserPaymentFailedMail;   // Make sure this mailable handles nullable Team
use App\Mail\UserSubscriptionSuccessMail;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Settings;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;


class StripeController extends Controller
{
    use ApiResponseTrait;

    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2024-04-10'); // Or your preferred API version
    }

    /**
     * Generates a secure, temporary signed URL for the web-based new organization subscription payment page.
     * Route: GET /user/subscription/generate-payment-link
     */
    public function generateWebPaymentLink(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Optional: Business rule to limit number of orgs a user can initiate payment for
        // if ($user->administeredOrganizations()->where('subscription_status', 'active')->count() >= SOME_LIMIT) {
        //     return $this->errorResponse('You have reached the limit for creating new organizations.', HttpResponse::HTTP_FORBIDDEN);
        // }

        try {
            $signedUrl = URL::temporarySignedRoute(
                'organization.payment.initiate.new', // Name of the web route in routes/web.php
                now()->addMinutes(30),               // Link expiry time
                ['user' => $user->id]                // Pass user ID (who will be the creator)
            );

            return $this->successResponse(
                ['payment_url' => $signedUrl],
                'Secure payment link for new organization generated.'
            );
        } catch (\Exception $e) {
            Log::error("Failed to generate signed payment URL for user {$user->id}: " . $e->getMessage());
            return $this->errorResponse(
                'Could not generate payment link. Please try again.',
                HttpResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }


    /**
     * Create a Stripe Payment Intent for a user to pay for creating a new Organization.
     * Route: POST /organization/create-subscription-intent (API, User Auth)
     */
    public function createOrganizationSubscriptionIntent(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Ensure user has a Stripe Customer ID, create if not
        if (!$user->stripe_customer_id) {
            try {
                $customer = StripeCustomer::create([
                    'email' => $user->email,
                    'name' => $user->full_name,
                    'metadata' => ['app_user_id' => $user->id]
                ]);
                $user->stripe_customer_id = $customer->id;
                $user->saveQuietly(); // Save without events if that's desired
            } catch (\Exception $e) {
                Log::error("Failed to create Stripe Customer for User ID {$user->id}: " . $e->getMessage());
                return $this->errorResponse('Failed to prepare payment information.', HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        $settings = Settings::instance();
        $amountInDollars = (float) $settings->unlock_price_amount;
        $currency = $settings->unlock_currency;
        $amountInCents = (int) round($amountInDollars * 100);

        if (strtolower($currency) === 'usd' && $amountInCents < 50) {
            Log::error("Stripe PI for New Org Failed: User ID {$user->id}: Amount {$amountInCents} cents < minimum.");
            return $this->errorResponse('Subscription amount is below the minimum allowed.', HttpResponse::HTTP_BAD_REQUEST);
        }
        if (empty($currency)) {
            Log::error("Stripe PI for New Org Failed: User ID {$user->id}: Currency not set.");
            return $this->errorResponse('Payment configuration error (currency).', HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $currency,
                'customer' => $user->stripe_customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "New Organization Subscription by {$user->email} - " . config('app.name'),
                'metadata' => [
                    'creator_user_id' => $user->id,
                    'creator_user_email' => $user->email,
                    'action' => 'create_new_organization_subscription', // For webhook
                ],
            ]);
            Log::info("Created New Org Subscription PI {$paymentIntent->id} initiated by User ID {$user->id}");

            return $this->successResponse([
                'clientSecret' => $paymentIntent->client_secret,
                'amount' => $amountInCents,
                'currency' => $currency,
                'displayAmount' => number_format($amountInDollars, 2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displaySymbolPosition' => $settings->unlock_currency_symbol_position,
                'publishableKey' => config('services.stripe.key')
            ], 'Payment Intent for new organization created successfully.');

        } catch (ApiErrorException $e) {
            Log::error("Stripe New Org PI API error for User ID {$user->id}: " . $e->getMessage(), ['stripe_error' => $e->getError()?->message]);
            return $this->errorResponse('Failed to initiate subscription: ' . ($e->getError()?->message ?: 'Stripe API error.'), HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            Log::error("Stripe New Org PI creation failed for User ID {$user->id}: " . $e->getMessage());
            return $this->errorResponse('Failed to initiate subscription process.', HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Handle incoming Stripe webhooks.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->server('HTTP_STRIPE_SIGNATURE');
        $webhookSecret = config('services.stripe.webhook_secret');
        if (!$webhookSecret) {
            Log::critical('Stripe webhook secret is NOT configured.');
            return $this->errorResponse('Webhook secret not configured.', HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
        $event = null;
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException | SignatureVerificationException $e) {
            Log::warning('Stripe Webhook Error: Invalid payload or signature.', ['exception' => $e->getMessage()]);
            return $this->errorResponse('Invalid webhook payload or signature.', HttpResponse::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('Stripe Webhook Error: Generic construction error.', ['exception' => $e->getMessage()]);
            return $this->errorResponse('Webhook processing error.', HttpResponse::HTTP_BAD_REQUEST);
        }

        Log::info('Stripe Webhook Received:', ['type' => $event->type, 'id' => $event->id]);

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $action = $paymentIntent->metadata->action ?? null;
                if ($action === 'create_new_organization_subscription') {
                    $this->handleNewOrganizationSubscriptionSucceeded($paymentIntent);
                } elseif ($action === 'renew_organization_subscription') {
                    $this->handleRenewOrganizationSubscriptionSucceeded($paymentIntent);
                } else {
                    Log::warning("Webhook PI Succeeded: Unknown action or missing metadata for PI {$paymentIntent->id}", ['metadata' => $paymentIntent->metadata]);
                }
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                $action = $paymentIntent->metadata->action ?? null;
                if (str_contains((string)$action, 'organization_subscription')) { // Check if it's any org sub action
                    $this->handleOrganizationSubscriptionFailed($paymentIntent);
                }
                break;
            // Consider handling 'customer.subscription.deleted', 'customer.subscription.updated' (e.g. for status changes like 'past_due')
            default:
                Log::info('Received unhandled Stripe event type: ' . $event->type);
        }
        return $this->successResponse(null, 'Webhook handled.');
    }

    protected function handleNewOrganizationSubscriptionSucceeded(PaymentIntent $paymentIntent): void
    {
        Log::info("Handling New Organization Subscription Succeeded: PI {$paymentIntent->id}");
        $creatorUserId = $paymentIntent->metadata->creator_user_id ?? null;
        if (!$creatorUserId) { Log::error("Webhook New Org Sub Error: Missing creator_user_id for PI {$paymentIntent->id}"); return; }
        if (Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) { Log::info("Webhook New Org Sub Info: PI {$paymentIntent->id} already processed."); return; }

        $creatorUser = User::find($creatorUserId);
        if (!$creatorUser) { Log::error("Webhook New Org Sub Error: Creator User ID {$creatorUserId} not found from PI {$paymentIntent->id}"); return; }

        $settings = Settings::instance();
        $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;
        $expiryDate = Carbon::now()->addDays($durationDays);
        $rawPasswordForOrg = Str::random(12);
        $orgCode = ''; // Will be set by grantSubscriptionAccess

        do { $orgCode = 'ORG-' . strtoupper(Str::random(8)); } // Generate unique org code
        while (Organization::where('organization_code', $orgCode)->exists());

        $organizationName = $paymentIntent->metadata->organization_name_suggestion ?? $creatorUser->last_name . "'s Organization (". ($creatorUser->administeredOrganizations()->count() + 1).")";;

        // Ensure organization name is unique if required by DB constraint
        // If `name` is unique in `organizations` table, add a check or suffix:
        // if (Organization::where('name', $organizationName)->exists()) {
        //     $organizationName .= ' (' . Str::random(3) . ')';
        // }

        DB::beginTransaction();
        try {
            $organization = Organization::create([
                'name' => $organizationName,
                'email' => $paymentIntent->metadata->organization_email_suggestion ?? $creatorUser->email, // Org's own email
                'organization_code' => $orgCode,
                'password' => Hash::make($rawPasswordForOrg), // Hash the password for the organization
                'creator_user_id' => $creatorUser->id,
                'subscription_status' => 'active',
                'subscription_expires_at' => $expiryDate,
                'stripe_subscription_id' => $paymentIntent->id, // Store PI ID as reference for this activation
                // 'stripe_customer_id' is NOT on org model, it's on the User who paid
            ]);

            Payment::create([
                'user_id' => $creatorUser->id,          // User who made the payment
                'organization_id' => $organization->id, // The Organization created/activated
                'stripe_payment_intent_id' => $paymentIntent->id,
                // user_organization_access_code removed from payments table
                'amount' => $paymentIntent->amount_received,
                'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status,
                'paid_at' => now(),
            ]);
            DB::commit();
            Log::info("New Org ID {$organization->id} ('{$organization->name}') created & activated by User ID {$creatorUserId}. Org Code: {$organization->organization_code}");

            if ($creatorUser->email && $creatorUser->receive_payment_notifications) {
                try { Mail::to($creatorUser->email)->send(new OrganizationCreatedMail($organization, $rawPasswordForOrg, $creatorUser)); }
                catch (\Exception $e) { Log::error("Mail Error (OrgCreated to User): {$e->getMessage()}");}
            }
            if ($settings->notify_admin_on_payment && !empty($settings->admin_notification_email)) {
                $paymentRecord = Payment::where('stripe_payment_intent_id', $paymentIntent->id)->first();
                if($paymentRecord) {
                    try { Mail::to($settings->admin_notification_email)->send(new AdminPaymentReceivedMail($paymentRecord)); }
                    catch (\Exception $e) { Log::error("Mail Error (AdminPayment New Org): {$e->getMessage()}");}
                }
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::critical("CRITICAL: Failed to create organization or payment record after PI Succeeded: PI {$paymentIntent->id}, User {$creatorUserId}. Error: " . $e->getMessage(), ['exception' => $e]);
        }
    }

    protected function handleRenewOrganizationSubscriptionSucceeded(PaymentIntent $paymentIntent): void
    {
        Log::info("Webhook: Handling Organization Subscription Renewal Succeeded: PI {$paymentIntent->id}");
        $organizationId = $paymentIntent->metadata->organization_id ?? null;
        if (!$organizationId) { Log::error("Webhook Renew Error: Missing organization_id for PI {$paymentIntent->id}"); return; }
        if (Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) { Log::info("Webhook Renew Info: PI {$paymentIntent->id} already processed."); return; }

        $organization = Organization::find($organizationId);
        if (!$organization) { Log::error("Webhook Renew Error: Organization ID {$organizationId} not found from PI {$paymentIntent->id}"); return; }

        $payingUserId = $paymentIntent->metadata->paying_user_id ?? $organization->creator_user_id; // User who initiated renewal
        $payingUser = User::find($payingUserId);
        if (!$payingUser) {
            Log::error("Webhook Renew Error: Paying User ID {$payingUserId} not found for Org ID {$organizationId}, PI {$paymentIntent->id}");
            // Continue processing the renewal for the org, but can't notify specific paying user if not found
        }


        DB::beginTransaction();
        try {
            $payment = Payment::create([
                'user_id' => $payingUserId,
                'organization_id' => $organization->id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                // 'user_organization_access_code' => $organization->organization_code, // Not needed on payment record
                'amount' => $paymentIntent->amount_received, 'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status, 'paid_at' => now(),
            ]);

            $settings = Settings::instance();
            $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;
            $newExpiryDate = $organization->subscription_expires_at && $organization->subscription_expires_at->isFuture()
                ? $organization->subscription_expires_at->addDays($durationDays)
                : Carbon::now()->addDays($durationDays);

            $organization->subscription_status = 'active';
            $organization->subscription_expires_at = $newExpiryDate;
            $organization->stripe_subscription_id = $paymentIntent->id; // Update to latest activating PI/Sub ID
            $organization->save();
            DB::commit();
            Log::info("Org ID {$organization->id} subscription renewed by User ID {$payingUserId} via PI {$paymentIntent->id}. New Expiry: {$newExpiryDate->toIso8601String()}");

            // --- Send Organization Renewal Success Email to Org Admin/Creator ---
            $recipientForOrgNotification = $organization->creator ?? $payingUser; // Prefer creator, fallback to payer
            if ($recipientForOrgNotification && $recipientForOrgNotification->email && $recipientForOrgNotification->receive_payment_notifications) {
                try {
                    Mail::to($recipientForOrgNotification->email)->send(new OrganizationSubscriptionRenewedMail($organization, $payingUser ?? $recipientForOrgNotification, $payment));
                    Log::info("Organization subscription renewal success email sent to {$recipientForOrgNotification->email} for Org ID {$organization->id}");
                } catch (\Exception $e) {
                    Log::error("Mail Error (OrgSubRenewed to OrgAdmin): {$e->getMessage()}");
                }
            }

            // --- Send Admin Notification Email (if enabled) ---
            if ($settings->notify_admin_on_payment && !empty($settings->admin_notification_email)) {
                try {
                    Mail::to($settings->admin_notification_email)->send(new AdminPaymentReceivedMail($payment)); // Pass the $payment object
                    Log::info("Admin payment notification sent for org renewal PI {$paymentIntent->id}");
                } catch (\Exception $e) {
                    Log::error("Mail Error (AdminPayment OrgRenewal): {$e->getMessage()}");
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::critical("CRITICAL: Failed to renew organization subscription or payment record: PI {$paymentIntent->id}, Org {$organizationId}. Error: " . $e->getMessage(), ['exception' => $e]);
        }
    }

    protected function handleOrganizationSubscriptionFailed(PaymentIntent $paymentIntent): void {
        Log::warning("Org Subscription payment_intent.payment_failed: PI {$paymentIntent->id}", ['metadata' => $paymentIntent->metadata]);
        $creatorUserId = $paymentIntent->metadata->creator_user_id ?? ($paymentIntent->metadata->paying_user_id ?? null);
        $user = $creatorUserId ? User::find($creatorUserId) : null;

        if ($user && $user->email && $user->receive_payment_notifications) {
            try {
                // UserPaymentFailedMail's $team parameter should be nullable
                Mail::to($user->email)->send(new UserPaymentFailedMail($user, null, $paymentIntent));
            } catch (\Exception $e) { Log::error("Mail Error (UserPaymentFailed): {$e->getMessage()}"); }
        }
    }

    /**
     * Display the authenticated user's payment history.
     * Route: GET /payments/history
     */
    public function userPaymentHistory(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        if (!$user) return $this->unauthorizedResponse('User not authenticated.');

        $payments = Payment::where('user_id', $user->id)
            ->with('organization:id,name,organization_code') // <-- EAGER LOAD ORGANIZATION
            ->orderBy('paid_at', 'desc')
            ->select([
                'id', 'organization_id', 'stripe_payment_intent_id', // organization_id now on payment
                'amount', // Accessor in Payment model converts to dollars
                'currency', 'status', 'paid_at', 'created_at'
            ])
            ->paginate($request->input('per_page', 15));

        return $this->successResponse($payments, 'Payment history retrieved successfully.');
    }

}