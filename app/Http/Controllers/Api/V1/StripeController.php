<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\UserPaymentFailedMail;
use App\Mail\UserPaymentSuccessMail;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Settings; // Import Settings
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\ApiErrorException; // For Stripe API errors
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;
use Illuminate\Http\Response; // For status codesuse Illuminate\Support\Facades\Mail; // For sending emails
use App\Mail\UserSubscriptionSuccessMail; // New Mailable
use Carbon\Carbon; // For expiry calculation

class StripeController extends Controller
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2024-04-10');
    }

    /**
     * Generates a secure, temporary signed URL for the web-based subscription payment page.
     * The user accessing this API endpoint must be authenticated via JWT.
     * Route: GET /user/subscription/generate-payment-link
     */
    public function generateWebPaymentLink(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user(); // Get authenticated user from JWT

        if ($user->hasActiveSubscription()) {
            return $this->errorResponse(
                'You already have an active subscription. It expires on ' . $user->subscription_expires_at?->toFormattedDayDateString() . '.',
                Response::HTTP_CONFLICT,
                ['access_code' => $user->organization_access_code] // Optionally return existing code
            );
        }

        // Generate a URL that is valid for a limited time (e.g., 15-30 minutes)
        // It passes the User model ID. Laravel's route model binding will handle fetching the User
        // in the WebPaymentController, and the 'signed' middleware verifies integrity.
        try {
            $signedUrl = URL::temporarySignedRoute(
                'subscription.initiate.web',     // Name of the web route in routes/web.php
                now()->addMinutes(30),           // Link expiry time
                ['user' => $user->id]            // Pass user ID as a parameter to the route
            );

            return $this->successResponse(
                ['payment_url' => $signedUrl],
                'Secure payment link generated successfully. Please open this URL in a browser to complete your subscription.'
            );
        } catch (\Exception $e) {
            Log::error("Failed to generate signed payment URL for user {$user->id}: " . $e->getMessage());
            return $this->errorResponse(
                'Could not generate payment link. Please try again.',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create a Stripe Payment Intent for user's annual subscription.
     * Called by Flutter app or WebPaymentController.
     * Route: POST /user/subscription/create-payment-intent (New API Route)
     */
    public function createUserSubscriptionPaymentIntent(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user(); // Assumes 'auth:api_user' middleware

        if ($user->hasActiveSubscription()) {
            return $this->errorResponse(
                'You already have an active subscription. It expires on ' . $user->subscription_expires_at->toFormattedDayDateString() . '.',
                Response::HTTP_CONFLICT
            );
        }

        $settings = Settings::instance();
        $amountInDollars = (float) $settings->unlock_price_amount; // Assumes settings stores DOLLARS
        $currency = $settings->unlock_currency;
        $amountInCents = (int) round($amountInDollars * 100);

        if (strtolower($currency) === 'usd' && $amountInCents < 50) {
            Log::error("Stripe PI creation failed for User ID {$user->id}: Amount {$amountInCents} cents < minimum.");
            return $this->errorResponse('Subscription amount is below the minimum allowed.', Response::HTTP_BAD_REQUEST);
        }
        if (empty($currency)) { return $this->errorResponse('Currency error.', Response::HTTP_BAD_REQUEST); }

        try {
            // Create Stripe Customer if not exists
            if (!$user->stripe_customer_id) {
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'name' => $user->full_name, // Assumes full_name accessor
                    'metadata' => ['user_id' => $user->id]
                ]);
                $user->stripe_customer_id = $customer->id;
                $user->save();
            }

            $paymentIntent = PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $currency,
                'customer' => $user->stripe_customer_id, // Associate with Stripe Customer
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "Annual Subscription for {$user->email} - " . config('app.name'),
                'metadata' => [ // Store relevant info for webhook processing
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'product_name' => 'Annual Subscription - ' . config('app.name'), // Example
                ],
            ]);

            Log::info("Created Subscription PaymentIntent {$paymentIntent->id} for User ID {$user->id}");

            return $this->successResponse([
                'clientSecret' => $paymentIntent->client_secret,
                'amount' => $amountInCents,
                'currency' => $currency,
                'displayAmount' => number_format($amountInDollars, 2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displaySymbolPosition' => $settings->unlock_currency_symbol_position,
                'publishableKey' => config('services.stripe.key') // Send publishable key for client
            ], 'Payment Intent created successfully.');

        } catch (ApiErrorException $e) {
            Log::error("Stripe Subscription PI API error for User ID {$user->id}: " . $e->getMessage(), ['stripe_error' => $e->getError()?->message]);
            return $this->errorResponse('Failed to initiate subscription: ' . ($e->getError()?->message ?: 'Stripe API error.'), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            Log::error("Stripe Subscription PI creation failed for User ID {$user->id}: " . $e->getMessage());
            return $this->errorResponse('Failed to initiate subscription process.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Create a Stripe Payment Intent for unlocking a specific team.
     */
    public function createTeamPaymentIntent(Request $request, Team $team)
    {
        $user = $request->user();
        if ($user->id !== $team->user_id) {
            return $this->forbiddenResponse('You do not own this team.');
        }
        if ($team->hasActiveAccess()) {
            return $this->errorResponse('This team already has active access.', Response::HTTP_CONFLICT);
        }

        $settings = Settings::instance();
        $amount = ($settings->unlock_price_amount*100);
        $currency = $settings->unlock_currency;

        if (!$amount || !$currency) {
            Log::error("Stripe PI creation failed: Missing unlock price/currency in settings for Team ID {$team->id}.");
            return $this->errorResponse('Payment configuration error.', Response::HTTP_INTERNAL_SERVER_ERROR, 'Unlock price or currency not set.');
        }

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "Access unlock for Team: {$team->name} (ID: {$team->id})",
                'metadata' => [
                    'team_id' => $team->id, 'team_name' => $team->name,
                    'user_id' => $user->id, 'user_email' => $user->email,
                ],
            ]);
            Log::info("Created PaymentIntent {$paymentIntent->id} for Team ID {$team->id}");

            return $this->successResponse([
                'clientSecret' => $paymentIntent->client_secret,
                'amount' => $amount, // Amount in cents
                'currency' => $currency,
                // For display in Flutter, convert to dollars if needed
                'displayAmount' => number_format($amount / 100, 2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displaySymbolPosition' => $settings->unlock_currency_symbol_position,
            ], 'Payment Intent created successfully.');

        } catch (ApiErrorException $e) { // Catch Stripe specific API errors
            Log::error("Stripe PaymentIntent creation API error for Team ID {$team->id}: " . $e->getMessage(), ['stripe_error' => $e->getError()?->message]);
            return $this->errorResponse('Failed to initiate payment: ' . ($e->getError()?->message ?: 'Stripe API error.'), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            Log::error("Stripe PaymentIntent creation failed for Team ID {$team->id}: " . $e->getMessage());
            return $this->errorResponse('Failed to initiate payment process.', Response::HTTP_INTERNAL_SERVER_ERROR);
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
        $event = null;

        if (!$webhookSecret) {
            Log::critical('Stripe webhook secret is NOT configured.');
            return $this->errorResponse('Webhook secret not configured.', Response::HTTP_INTERNAL_SERVER_ERROR, 'Server configuration error.');
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException | SignatureVerificationException $e) {
            Log::warning('Stripe Webhook Error: Invalid payload or signature.', ['exception' => $e->getMessage()]);
            return $this->errorResponse('Invalid webhook payload or signature.', Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('Stripe Webhook Error: Generic construction error.', ['exception' => $e->getMessage()]);
            return $this->errorResponse('Webhook processing error.', Response::HTTP_BAD_REQUEST);
        }

        Log::info('Stripe Webhook Received:', ['type' => $event->type, 'id' => $event->id]);

//        switch ($event->type) {
//            case 'payment_intent.succeeded':
//                $this->handlePaymentIntentSucceeded($event->data->object);
//                break;
//            case 'payment_intent.payment_failed':
//                $this->handlePaymentIntentFailed($event->data->object);
//                break;
//            default:
//                Log::info('Received unhandled Stripe event type: ' . $event->type);
//        }
//        // Always return 200 to Stripe for acknowledged webhooks
//        return $this->successResponse(null, 'Webhook handled.');

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                // Check if this PI is for a user subscription based on metadata or description
                if (str_contains(strtolower($paymentIntent->description ?? ''), 'subscription')) {
                    $this->handleUserSubscriptionPaymentSucceeded($paymentIntent);
                } else {
                    // Potentially handle other types of one-time payments if you add them later
                    Log::info("Received PI succeeded for non-subscription: {$paymentIntent->id}");
                }
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                if (str_contains(strtolower($paymentIntent->description ?? ''), 'subscription')) {
                    $this->handleUserSubscriptionPaymentFailed($paymentIntent);
                }
                break;
            default:
                Log::info('Received unhandled Stripe event type: ' . $event->type);
        }
        return $this->successResponse(null, 'Webhook handled.');
    }

    protected function handleUserSubscriptionPaymentSucceeded(PaymentIntent $paymentIntent): void
    {
        Log::info("Handling User Subscription payment_intent.succeeded: {$paymentIntent->id}");
        $userId = $paymentIntent->metadata->user_id ?? null;
        $userEmail = $paymentIntent->metadata->user_email ?? null; // Fallback if user_id is missing

        if (!$userId && $userEmail) { // Try to find user by email if ID missing from metadata
            $tempUser = User::where('email', $userEmail)->first();
            if ($tempUser) $userId = $tempUser->id;
        }

        if (!$userId) {
            Log::error("Webhook Subscription Succeeded Error: Missing user_id in PI metadata {$paymentIntent->id}");
            return;
        }

        $user = User::find($userId);
        if (!$user) {
            Log::error("Webhook Subscription Succeeded Error: User not found for user_id {$userId} from PI {$paymentIntent->id}");
            return;
        }

        if (Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) {
            Log::info("Webhook Sub Succeeded: PI {$paymentIntent->id} already processed.");
            return;
        }

        // --- Grant subscription access to the user FIRST ---
        // This will generate/assign the organization_access_code
        $settings = Settings::instance();
        $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;
        $expiryDate = Carbon::now()->addDays($durationDays);
        $user->grantSubscriptionAccess($expiryDate, $paymentIntent->id); // Pass PI ID as sub ID for one-time

        // --- Now record the payment, including the generated access code ---
        $payment = Payment::create([
            'user_id' => $userId,
            // 'team_id' => null, // Column removed
            'stripe_payment_intent_id' => $paymentIntent->id,
            'user_organization_access_code' => $user->organization_access_code, // <-- STORE THE CODE
            'amount' => $paymentIntent->amount_received,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'paid_at' => now(),
        ]);

        Log::info("Subscription access granted for User ID {$userId} via PI {$paymentIntent->id}. Expires: {$expiryDate->toIso8601String()}. Access Code: {$user->organization_access_code}");

        // Send User Payment Success Notification
        if ($user->email && $user->receive_payment_notifications) {
            try {
                Mail::to($user->email)->send(new UserPaymentSuccessMail($user, $payment));
                Log::info("User subscription success notification sent to {$user->email} for PI {$paymentIntent->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send user payment success notification for PI {$paymentIntent->id}: " . $e->getMessage());
            }
        }

        // Send Admin Notification Email (if enabled)
        $adminSettings = Settings::instance(); // Re-fetch or pass if needed
        if ($adminSettings->notify_admin_on_payment && !empty($adminSettings->admin_notification_email)) {
            try {
                // AdminPaymentReceivedMail might need slight adjustment if team is null
                Mail::to($adminSettings->admin_notification_email)->send(new AdminPaymentReceivedMail($payment));
                Log::info("Admin payment notification sent for user subscription PI {$paymentIntent->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send admin payment receipt notification for PI {$paymentIntent->id}: " . $e->getMessage());
            }
        }
    }

    protected function handleUserSubscriptionPaymentFailed(PaymentIntent $paymentIntent): void
    {
        Log::warning("Handling User Subscription payment_intent.payment_failed: {$paymentIntent->id}", ['metadata' => $paymentIntent->metadata]);
        $userId = $paymentIntent->metadata->user_id ?? null;
        $user = $userId ? User::find($userId) : null;

        if ($user && $user->email && $user->receive_payment_notifications) {
            try {
                // UserPaymentFailedMail might need adjustment to not require a Team model
                Mail::to($user->email)->send(new UserPaymentFailedMail($user, null, $paymentIntent)); // Pass null for team
                Log::info("User subscription payment failed notification sent to {$user->email} for PI {$paymentIntent->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send user payment failed notification for PI {$paymentIntent->id}: " . $e->getMessage());
            }
        }
        // Optionally record failed attempt
    }

    protected function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): void
    {
        Log::info("Handling payment_intent.succeeded: {$paymentIntent->id}");
        $teamId = $paymentIntent->metadata->team_id ?? null;
        $userId = $paymentIntent->metadata->user_id ?? null;

        if (!$teamId || !$userId) {
            Log::error("Webhook Succeeded Error: Missing team_id or user_id in PI metadata {$paymentIntent->id}");
            return;
        }
        if (Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) {
            Log::info("Webhook Succeeded Info: PaymentIntent {$paymentIntent->id} already processed.");
            return;
        }
        $team = Team::find($teamId);
        $user = User::find($userId);

        if (!$team) {
            Log::error("Webhook Succeeded Error: Team not found for team_id {$teamId} from PI {$paymentIntent->id}");
            return;
        }

        $payment = Payment::create([
            'user_id' => $userId, 'team_id' => $teamId,
            'stripe_payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount_received,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'paid_at' => now(),
        ]);

        $accessExpiryDate = $team->grantPaidAccess(); // This now returns the Carbon expiry date
        $settings = Settings::instance(); // Get settings to read duration for notification
        $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;
        $durationString = $this->getHumanReadableDuration($durationDays); // Use helper

        Log::info("Access granted for Team ID {$teamId} via PI {$paymentIntent->id} for {$durationString}. Expires: {$accessExpiryDate->toIso8601String()}");


        // --- Send User Payment Success Notification (Check Preference) ---
        if ($user->email && $user->receive_payment_notifications) { // <-- CHECK PREFERENCE
            try {
                Mail::to($user->email)->send(new UserPaymentSuccessMail($payment));
                Log::info("User payment success notification sent to {$user->email} for PI {$paymentIntent->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send user payment success notification for PI {$paymentIntent->id}: " . $e->getMessage());
            }
        } elseif ($user->email && !$user->receive_payment_notifications) {
            Log::info("User {$user->email} has opted out of payment success notifications for PI {$paymentIntent->id}.");
        }
        // --- End Send User Notification ---

        // --- Send Admin Notification Email ---
        $settings = Settings::instance();
        if ($settings->notify_admin_on_payment && !empty($settings->admin_notification_email)) {
            try {
                // Pass the newly created Payment model instance to the mailable
                Mail::to($settings->admin_notification_email)->send(new AdminPaymentReceivedMail($payment));
                Log::info("Admin payment notification sent to {$settings->admin_notification_email} for PaymentIntent {$paymentIntent->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send admin payment notification for PI {$paymentIntent->id}: " . $e->getMessage(), ['exception' => $e]);
            }
        } elseif (!$settings->notify_admin_on_payment) {
            Log::info("Admin payment notification is disabled in settings for PI {$paymentIntent->id}.");
        } elseif (empty($settings->admin_notification_email)) {
            Log::warning("Admin payment notification enabled, but no admin_notification_email is set in settings for PI {$paymentIntent->id}.");
        }
        // --- End Send Admin Notification Email ---
    }

    /**
     * Display the authenticated user's payment history.
     * Route: GET /payments/history
     */
    public function userPaymentHistory(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user(); // Get the currently authenticated user

        if (!$user) {
            // Should be caught by auth:api_user middleware, but good failsafe
            return $this->unauthorizedResponse('User not authenticated.');
        }


        $payments = Payment::where('user_id', $user->id)
            ->orderBy('paid_at', 'desc') // Show most recent first
            ->select([ // Select specific columns for the response
                'id', 'stripe_payment_intent_id', 'user_organization_access_code',
                'amount', // Accessor in Payment model will convert to dollars
                'currency', 'status', 'paid_at', 'created_at'
            ])
            ->paginate($request->input('per_page', 15)); // Paginate results

        // The Payment model's 'amount' accessor should handle conversion to dollars.
        // If you added 'display_amount_dollars' to the Payment model using $appends,
        // it would also be included automatically.
        // For consistency, let's ensure a display amount if not relying on the accessor alone for this specific output.
        // However, if the 'amount()' accessor in Payment.php is working correctly, this transform is redundant.
        // $payments->getCollection()->transform(function ($payment) {
        //     $payment->display_amount_dollars = round($payment->getRawOriginal('amount') / 100, 2);
        //     return $payment;
        // });

        return $this->successResponse($payments, 'Payment history retrieved successfully.');
    }

    protected function handlePaymentIntentFailed(PaymentIntent $paymentIntent): void
    {
        Log::warning("Handling payment_intent.payment_failed: {$paymentIntent->id}", ['metadata' => $paymentIntent->metadata]);

        $teamId = $paymentIntent->metadata->team_id ?? null;
        $userId = $paymentIntent->metadata->user_id ?? null;

        if ($teamId && $userId) {
            $team = Team::find($teamId);
            $user = User::find($userId);

            // --- Send User Payment Failed Notification (Check Preference) ---
            if ($user && $team && $user->email && $user->receive_payment_notifications) { // <-- CHECK PREFERENCE
                try {
                    Mail::to($user->email)->send(new UserPaymentFailedMail($user, $team, $paymentIntent));
                    Log::info("User payment failed notification sent to {$user->email} for PI {$paymentIntent->id}");
                } catch (\Exception $e) {
                    Log::error("Failed to send user payment failed notification for PI {$paymentIntent->id}: " . $e->getMessage());
                }
            } elseif ($user && $user->email && !$user->receive_payment_notifications) {
                Log::info("User {$user->email} has opted out of payment failed notifications for PI {$paymentIntent->id}.");
            } elseif (!$user || !$user->email) {
                Log::warning("Could not send payment failed notification: User or User email missing for PI {$paymentIntent->id}");
            }
            // --- End Send User Notification ---
        } else {
            Log::warning("Could not send payment failed notification: TeamID or UserID missing in metadata for PI {$paymentIntent->id}");
        }

        // Optionally: Record failed payment attempt in 'payments' table with 'failed' status
        if ($userId && $teamId && !Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) {
            Payment::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount, // Amount attempted
                'currency' => $paymentIntent->currency,
                'status' => 'failed', // Mark as failed
                'paid_at' => null, // Not paid
            ]);
            Log::info("Recorded failed payment attempt for PI {$paymentIntent->id}");
        }
    }

    public function createSubscriptionCheckoutSession(Request $request)
    {
        $user = $request->user();
        // Ensure user is a Stripe customer
        // ... (create if not, as shown above) ...

        $priceId = config('services.stripe.annual_price_id'); // Store Price ID in .env/config

        $checkout_session = \Stripe\Checkout\Session::create([
            'customer' => $user->stripe_customer_id,
            'payment_method_types' => ['card'],
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'mode' => 'subscription',
            'success_url' => route('subscription.success') . '?session_id={CHECKOUT_SESSION_ID}', // Your web success URL
            'cancel_url' => route('subscription.cancel'), // Your web cancel URL
            'subscription_data' => [
                'metadata' => ['user_id' => $user->id] // Pass user_id for webhook
            ],
        ]);
        // Return session ID or URL for Flutter to redirect/use
        return $this->successResponse(['checkout_session_url' => $checkout_session->url]);
    }
}
