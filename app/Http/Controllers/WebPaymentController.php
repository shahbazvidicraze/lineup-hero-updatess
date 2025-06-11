<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;    // Import User model
use App\Models\Settings;
use http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Customer as StripeCustomer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse; // For return type hint

class WebPaymentController extends Controller
{
    public function __construct()
    {
        // NO AUTH MIDDLEWARE HERE as it's accessed via signed URL
        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2024-04-10'); // Use your desired API version
    }

    /**
     * Show payment page for a User to create and subscribe a NEW Organization.
     * {user} is injected via route model binding from the signed URL.
     * Route: GET /pay-for-new-organization/{user} (Web, Signed, named 'organization.payment.initiate.new')
     */
    public function showNewOrganizationSubscriptionPage(Request $request, User $user): View
    {
        // $user is the one paying and will become the creator. Validated by 'signed' middleware.
        // Optional: Check if this user can create more orgs

        $settings = Settings::instance();
        $amountInDollars = (float) $settings->unlock_price_amount;
        $currency = $settings->unlock_currency;
        $amountInCents = (int) round($amountInDollars * 100);

        // Validate amount and currency before creating PI
        if (strtolower($currency) === 'usd' && $amountInCents < 50) {
            Log::error("Web Flow: PI creation failed for User ID {$user->id}: Amount {$amountInCents} cents < minimum.");
            return view('payments.failed', ['title'=>"Subscription failed!",'message' => 'Subscription amount is below the minimum allowed.', 'amount'=>$amountInDollars, 'currency'=>$currency]);
        }
        if (empty($currency)) {
            Log::error("Web Flow: PI creation failed: Currency not set in settings for User ID {$user->id}.");
            return view('payments.failed', ['message' => 'Payment configuration error (currency).', 'amount'=>$amountInDollars, 'currency'=>$currency]);
        }

        try {
            if (!$user->stripe_customer_id) {
                $customer = StripeCustomer::create(['email' => $user->email, 'name' => $user->full_name, 'metadata' => ['app_user_id' => $user->id]]);
                $user->stripe_customer_id = $customer->id;
                $user->saveQuietly();
            }

            $paymentIntent = PaymentIntent::create([
                'amount' => $amountInCents, 'currency' => $currency,
                'customer' => $user->stripe_customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "New Organization Subscription by {$user->email}",
                'metadata' => [
                    'creator_user_id' => $user->id, 'creator_user_email' => $user->email,
                    'action' => 'create_new_organization_subscription'
                ],
            ]);
            Log::info("Web Flow: New Org PI {$paymentIntent->id} for User ID {$user->id}");

            return view('payments.subscribe_page', [
                'stripeKey' => config('services.stripe.key'),
                'clientSecret' => $paymentIntent->client_secret,
                'paymentTitle' => 'Create Your Organization',
                'paymentDescription' => "Complete your payment to create and activate your new Organization.",
                'user' => $user, // User paying
                'displayAmount' => number_format($amountInDollars,2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displayCurrencySymbolPosition' => $settings->unlock_currency_symbol_position,
                'currency' => $currency,
                'returnUrl' => route('payment.return.general'), // General return URL
            ]);
        } catch (ApiErrorException $e) {
            Log::error("Web Flow: Stripe New Org PI API error for User ID {$user->id}: " . $e->getMessage());
            return view('payments.failed', ['pageTitle' => 'Payment Initiation Failed', 'message' => 'Failed to initiate subscription: ' . ($e->getError()?->message ?: 'Stripe API error.'), 'amount'=>$amountInDollars, 'currency'=>$currency]);
        } catch (\Exception $e) {
            Log::error("Web Flow: Generic error initiating New Org subscription for User ID {$user->id}: " . $e->getMessage());
            return view('payments.failed', ['pageTitle' => 'Payment Error', 'message' => 'An unexpected error occurred while initiating payment.', 'amount'=>$amountInDollars, 'currency'=>$currency]);
        }
    }

    /**
     * Show payment page for an Organization Admin to RENEW their Organization's subscription.
     * {organization} is injected via route model binding from the signed URL.
     * Route: GET /pay-for-organization-renewal/{organization} (Web, Signed, named 'organization.payment.initiate.renewal')
     */
    public function showOrganizationRenewalPage(Request $request, Organization $organization): View
    {
        // The creator (Org Admin) is implicitly the one who would have gotten the renewal link.
        $creatorUser = $organization->creator; // Fetch the creator
        if (!$creatorUser) {
            Log::error("Web Flow Renew: Org ID {$organization->id} has no creator_user_id set.");
            return view('payments.failed', ['pageTitle' => 'Error', 'messageBody' => 'Organization details incomplete. Cannot renew.']);
        }

        $settings = Settings::instance();
        $amountInDollars = (float) $settings->unlock_price_amount;
        $currency = $settings->unlock_currency;
        $amountInCents = (int) round($amountInDollars * 100);
        // Optional: Check if renewal is appropriate
        if ($organization->hasActiveSubscription() && $organization->subscription_expires_at->gt(now()->addMonths(11))) {
            return view('payments.pre-activated', [ // Or a different view
                'pageTitle' => 'Subscription Already Active',
                'message' => "Organization '{$organization->name}' already has an active subscription. Renewal can be done closer to the expiry date (" . $organization->subscription_expires_at->toFormattedDayDateString() . ").",
                'displayAmount' => number_format($amountInDollars,2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displayCurrencySymbolPosition' => $settings->unlock_currency_symbol_position,
                'currency' => $currency,
            ]);
        }

        // Validate amount and currency before creating PI
        if (strtolower($currency) === 'usd' && $amountInCents < 50) {
            Log::error("Web Flow: PI creation failed for Org ID {$organization->id}: Amount {$amountInCents} cents < minimum.");
            return view('payments.failed', ['message' => 'Subscription amount is below the minimum allowed.']);
        }
        if (empty($currency)) {
            Log::error("Web Flow: PI creation failed: Currency not set in settings.");
            return view('payments.failed', ['message' => 'Payment configuration error (currency).']);
        }

        try {
            // Ensure the paying user (creator) has a Stripe Customer ID
            if (!$creatorUser->stripe_customer_id) {
                $customer = StripeCustomer::create(['email' => $creatorUser->email, 'name' => $creatorUser->full_name, 'metadata' => ['app_user_id' => $creatorUser->id]]);
                $creatorUser->stripe_customer_id = $customer->id;
                $creatorUser->saveQuietly();
            }
            $stripeCustomerIdForPayment = $creatorUser->stripe_customer_id;

            $paymentIntent = PaymentIntent::create([
                'amount' => $amountInCents, 'currency' => $currency,
                'customer' => $stripeCustomerIdForPayment,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "Renewal Subscription for Organization: {$organization->name} ({$organization->organization_code})",
                'metadata' => [
                    'organization_id' => $organization->id,
                    'organization_code' => $organization->organization_code,
                    'paying_user_id' => $creatorUser->id, // User who initiated this renewal payment
                    'action' => 'renew_organization_subscription'
                ],
            ]);
            Log::info("Web Flow: Org Renewal PI {$paymentIntent->id} for Org ID {$organization->id}");

            return view('payments.subscribe_page', [
                'stripeKey' => config('services.stripe.key'),
                'clientSecret' => $paymentIntent->client_secret,
                'paymentTitle' => "Renew Subscription for {$organization->name}",
                'paymentDescription' => "You are renewing the annual subscription for your Organization.",
                'user' => $creatorUser, // Show the org creator's name
                'displayAmount' => number_format($amountInDollars,2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displayCurrencySymbolPosition' => $settings->unlock_currency_symbol_position,
                'currency' => $currency,
                'returnUrl' => route('payment.return.general'),
            ]);
        } catch (ApiErrorException $e) {
            Log::error("Web Flow: Stripe Org Renewal PI API error for Org ID {$organization->id}: " . $e->getMessage());
            return view('payments.failed', ['pageTitle' => 'Renewal Failed', 'message' => 'Failed to initiate renewal: ' . ($e->getError()?->message ?: 'Stripe API error.')]);
        } catch (\Exception $e) {
            Log::error("Web Flow: Generic error initiating Org Renewal for Org ID {$organization->id}: " . $e->getMessage());
            return view('payments.failed', ['pageTitle' => 'Renewal Error', 'message' => 'An unexpected error occurred.']);
        }
    }

    /**
     * Generic handle return URL from Stripe for both new org and renewal.
     */
    public function handleGenericReturn(Request $request): View
    {
        $paymentIntentId = $request->query('payment_intent');
        $settings = Settings::instance();
        $amountInDollars = (float) $settings->unlock_price_amount;
        $currency = $settings->unlock_currency;
        $amountInCents = (int) round($amountInDollars * 100);

        if (!$paymentIntentId) {
            return view('payments.failed', ['title'=>'Error', 'message'=>'Payment details missing.']);
        }
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            Log::info("Generic Payment Return: Handling PI {$paymentIntent->id}, Status: {$paymentIntent->status}");

            if ($paymentIntent->status === 'succeeded') {
                $action = $paymentIntent->metadata->action ?? 'payment';
                $message = 'Your payment was successful. ';
                if ($action === 'create_new_organization_subscription') {
                    $message .= 'Your new Organization is being created and activated. You will receive an email shortly with your Organization Code and login details.';
                } elseif ($action === 'renew_organization_subscription') {
                    $message .= 'Your Organization\'s subscription renewal is being processed and will be updated shortly.';
                } else {
                    $message .= 'Your account or service status will be updated shortly.';
                }
                $userId = $paymentIntent->metadata->creator_user_id ?? null; // Could get user to personalize
                $user = User::find($userId);

                return view('payments.success', [
                    'user' => $user, // For displaying user info if needed
                    'displayAmount' => number_format($amountInDollars, 2),
                    'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                    'displayCurrencySymbolPosition' => $settings->unlock_currency_symbol_position,
                    'currency' => $currency
                    ,'title' => 'Payment Successful!',
                    'message' => $message,
                ]);
//                return view('payments.success', ['title' => 'Payment Successful!', 'message' => $message, ]);
            } elseif ($paymentIntent->status === 'processing') {
                return view('payments.processing', ['title'=>'Payment Processing', 'message'=>'Your payment is processing. We will notify you once confirmed.']);
            } else {
                $failureReason = $paymentIntent->last_payment_error->message ?? 'Payment not successful.';
                return view('payments.failed', ['title'=>'Payment Issue', 'message'=> $failureReason]);
            }
        }  catch (ApiErrorException $e) {
            Log::error("Payment Return: Error retrieving PaymentIntent {$paymentIntentId}: " . $e->getMessage());
            return view('payments.failed', ['title' => 'Payment Verification Error', 'message' => 'Could not verify payment status. Please check your account or contact support.']);
        } catch (\Exception $e) {
            Log::error("Payment Return: Generic error handling return for PI {$paymentIntentId}: " . $e->getMessage());
            return view('payments.failed', ['title' => 'Unexpected Error', 'message' => 'An unexpected error occurred while verifying your payment.']);
        }
    }
}