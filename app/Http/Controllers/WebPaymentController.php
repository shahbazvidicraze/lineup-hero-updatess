<?php

namespace App\Http\Controllers;

use App\Models\User;    // Import User model
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
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
     * Show the subscription payment initiation page.
     * The {user} is injected via route model binding from the signed URL.
     * The 'signed' middleware on the route validates the URL's integrity.
     */
    public function showSubscriptionPage(Request $request, User $user): View|RedirectResponse
    {
        $settings = Settings::instance();
        $amountInDollars = (float) $settings->unlock_price_amount;
        $currency = $settings->unlock_currency;
        $amountInCents = (int) round($amountInDollars * 100);
        // The $user model instance is trusted because the URL was signed by your app.
        if ($user->hasActiveSubscription()) {
            // If they somehow reach this page while already subscribed, redirect them
            // or show an "already subscribed" message.
            // Using a generic success view for simplicity if payment.return handles messages.
            return view('payments.pre-activated', [
                'user' => $user, // For displaying user info if needed
                'displayAmount' => number_format($amountInDollars, 2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displayCurrencySymbolPosition' => $settings->unlock_currency_symbol_position,
                'currency' => $currency,
                'title' => 'Already Subscribed!',
                'message' => 'Your account already has an active subscription until ' . $user->subscription_expires_at?->toFormattedDayDateString() . '.'
            ]);
        }

        // Validate amount and currency before creating PI
        if (strtolower($currency) === 'usd' && $amountInCents < 50) {
            Log::error("Web Flow: PI creation failed for User ID {$user->id}: Amount {$amountInCents} cents < minimum.");
            return view('payments.failed', ['message' => 'Subscription amount is below the minimum allowed.']);
        }
        if (empty($currency)) {
            Log::error("Web Flow: PI creation failed: Currency not set in settings for User ID {$user->id}.");
            return view('payments.failed', ['message' => 'Payment configuration error (currency).']);
        }

        try {
            // Ensure user is a Stripe Customer
            if (!$user->stripe_customer_id) {
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'name' => $user->full_name, // Assumes full_name accessor on User model
                    'metadata' => ['user_id' => $user->id, 'app_user_id' => $user->id] // Ensure app_user_id for your reference
                ]);
                $user->stripe_customer_id = $customer->id;
                $user->saveQuietly(); // Save without triggering events if not needed
            }

            $paymentIntent = PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $currency,
                'customer' => $user->stripe_customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "Annual Subscription for {$user->email} - " . config('app.name'),
                'metadata' => [
                    'user_id' => $user->id, // For webhook to identify the user
                    'user_email' => $user->email,
                    'product_name' => 'Annual Subscription - ' . config('app.name'),
                    'trigger_source' => 'web_payment_flow',
                ],
            ]);

            Log::info("Web Flow: Created Subscription PI {$paymentIntent->id} for User ID {$user->id}");

            return view('payments.initiate', [ // Your Blade view for the payment form
                'stripeKey' => config('services.stripe.key'), // Publishable key
                'clientSecret' => $paymentIntent->client_secret,
                'user' => $user, // For displaying user info if needed
                'displayAmount' => number_format($amountInDollars, 2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displayCurrencySymbolPosition' => $settings->unlock_currency_symbol_position,
                'currency' => $currency,
                'returnUrl' => route('payment.return'), // Laravel web named route
            ]);

        } catch (ApiErrorException $e) {
            Log::error("Web Flow: Stripe PI API error for User ID {$user->id}: " . $e->getMessage(), ['stripe_error' => $e->getError()?->message]);
            return view('payments.failed', ['message' => 'Failed to initiate subscription: ' . ($e->getError()?->message ?: 'Stripe API error.')]);
        } catch (\Exception $e) {
            Log::error("Web Flow: Generic error initiating subscription for User ID {$user->id}: " . $e->getMessage());
            return view('payments.failed', ['message' => 'An unexpected error occurred while initiating payment.']);
        }
    }

    /**
     * Handle the return URL redirect from Stripe after payment attempt.
     * Displays success or failure message based on Payment Intent status.
     * Relies on webhook for actual access granting.
     */
    public function handleReturn(Request $request): View
    {
        $paymentIntentId = $request->query('payment_intent');
        // $clientSecret = $request->query('payment_intent_client_secret'); // Not always needed for status check
        // $redirectStatus = $request->query('redirect_status');

        $settings = Settings::instance();
        $amountInDollars = (float) $settings->unlock_price_amount;
        $currency = $settings->unlock_currency;
        $amountInCents = (int) round($amountInDollars * 100);

        if (!$paymentIntentId) {
            Log::warning("Payment Return: Missing payment_intent ID in return URL.");
            return view('payments.failed', ['messageTitle' => 'Payment Error', 'messageBody' => 'Payment details missing. Please check your account or contact support.']);
        }

        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            Log::info("Payment Return: Handling PI {$paymentIntent->id} with status {$paymentIntent->status}");

            if ($paymentIntent->status === 'succeeded') {
                $userId = $paymentIntent->metadata->user_id ?? null; // Could get user to personalize
                $user = User::find($userId);

                return view('payments.success', [
                    'user' => $user, // For displaying user info if needed
                    'displayAmount' => number_format($amountInDollars, 2),
                    'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                    'displayCurrencySymbolPosition' => $settings->unlock_currency_symbol_position,
                    'currency' => $currency
                    ,'title' => 'Payment Successful!',
                    'message' => 'Your payment was successful. Your subscription will be activated shortly🎉.'
                ]);
            } elseif ($paymentIntent->status === 'processing') {
                return view('payments.processing', ['messageTitle' => 'Payment Processing', 'messageBody' => 'Your payment is processing. We will notify you once confirmed.']);
            } else { // requires_payment_method, requires_action, canceled, etc.
                Log::warning("Payment Return: PaymentIntent {$paymentIntent->id} status is {$paymentIntent->status}");
                $failureReason = $paymentIntent->last_payment_error->message ?? 'Payment was not successful. Please try again or use a different payment method.';
                return view('payments.failed', ['messageTitle' => 'Payment Issue', 'messageBody' => $failureReason]);
            }
        } catch (ApiErrorException $e) {
            Log::error("Payment Return: Error retrieving PaymentIntent {$paymentIntentId}: " . $e->getMessage());
            return view('payments.failed', ['messageTitle' => 'Payment Verification Error', 'messageBody' => 'Could not verify payment status. Please check your account or contact support.']);
        } catch (\Exception $e) {
            Log::error("Payment Return: Generic error handling return for PI {$paymentIntentId}: " . $e->getMessage());
            return view('payments.failed', ['messageTitle' => 'Unexpected Error', 'messageBody' => 'An unexpected error occurred while verifying your payment.']);
        }
    }
}