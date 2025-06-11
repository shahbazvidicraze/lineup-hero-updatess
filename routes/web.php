<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebPaymentController;

// --- Basic Laravel Welcome Route ---
Route::get('/', function () {
    return view('welcome');
});

// --- Payment Web Flow Routes (NO AUTHENTICATION) ---
// For a User creating a NEW Organization subscription
Route::get('/pay-for-new-organization/{user}', [WebPaymentController::class, 'showNewOrganizationSubscriptionPage'])
    ->name('organization.payment.initiate.new') // New specific name
    ->middleware('signed');

// For an Organization Admin RENEWING their Organization's subscription
Route::get('/pay-for-organization-renewal/{organization}', [WebPaymentController::class, 'showOrganizationRenewalPage'])
    ->name('organization.payment.initiate.renewal') // New specific name
    ->middleware('signed');

// Generic Return URL from Stripe (can be used by both flows)
Route::get('/payment/return', [WebPaymentController::class, 'handleGenericReturn'])
    ->name('payment.return.general');

// --- Stripe Webhook Route (Still needs to be public) ---
// Defined in routes/api.php or routes/web.php - ensure it's accessible by Stripe
// Route::post('/stripe/webhook', [\App\Http\Controllers\Api\V1\StripeController::class, 'handleWebhook'])->name('stripe.webhook');
