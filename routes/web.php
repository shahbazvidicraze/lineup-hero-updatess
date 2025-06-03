<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebPaymentController;

// --- Basic Laravel Welcome Route ---
Route::get('/', function () {
    return view('welcome');
});

// --- Payment Web Flow Routes (NO AUTHENTICATION) ---
// This route is now public, but receives a temporary signed token
Route::get('/pay-for-subscription/{user}', [WebPaymentController::class, 'showSubscriptionPage'])
    ->name('subscription.initiate.web') // Give it a name
    ->middleware('signed'); // <-- CRITICAL: Use Laravel's Signed URL Middleware

// Return URL remains public
Route::get('/payment/return', [WebPaymentController::class, 'handleReturn'])->name('payment.return');


// --- Stripe Webhook Route (Still needs to be public) ---
// Defined in routes/api.php or routes/web.php - ensure it's accessible by Stripe
// Route::post('/stripe/webhook', [\App\Http\Controllers\Api\V1\StripeController::class, 'handleWebhook'])->name('stripe.webhook');
