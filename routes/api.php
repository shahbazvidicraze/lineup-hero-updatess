<?php

use App\Http\Controllers\Api\V1\Admin\AdminController; // Correct Controller for User management
use App\Http\Controllers\Api\V1\Admin\AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\AdminPromoCodeController; // Import Admin controller
use App\Http\Controllers\Api\V1\Admin\AdminSettingsController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\UserAuthController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\PlayerPreferenceController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\PromoCodeController as UserPromoCodeController; // Keep if PromoCodes are implemented
use App\Http\Controllers\Api\V1\StripeController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\AdminUtilityController;
use App\Http\Controllers\Api\V1\Auth\UniversalLoginController;



/*
|--------------------------------------------------------------------------
| API Routes V1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Universal Login
    Route::post('auth/login', [UniversalLoginController::class, 'login']);

    // --- Public Authentication ---
    Route::prefix('user/auth')->controller(UserAuthController::class)->group(function () {
        Route::post('register', 'register');
        Route::post('login', 'login');
        // Forgot/Reset Password (Public)
        Route::post('forgot-password', 'forgotPassword');
        Route::post('reset-password', 'resetPassword');
        Route::post('logout', 'logout')->middleware('auth:api_user');
        Route::post('refresh', 'refresh')->middleware('auth:api_user');
        Route::get('profile', 'profile')->middleware('auth:api_user');
        Route::put('profile', 'updateProfile')->middleware('auth:api_user');
        Route::post('change-password', 'changePassword')->middleware('auth:api_user');
    });

    Route::prefix('admin/auth')->controller(AdminAuthController::class)->group(function () {
        Route::post('login', 'login');
        Route::post('logout', 'logout')->middleware('auth:api_admin');
        Route::post('refresh', 'refresh')->middleware('auth:api_admin');
        Route::get('profile', 'profile')->middleware('auth:api_admin');
        Route::put('profile', 'updateProfile')->middleware('auth:api_admin');
        Route::post('change-password', 'changePassword')->middleware('auth:api_admin');
    });


    // --- Protected User Routes ---
    Route::middleware('auth:api_user')->group(function () {
        // Team Management
        Route::apiResource('teams', TeamController::class);
        Route::get('teams/{team}/players', [TeamController::class, 'listPlayers']);

        // Player Management (within Team context for creation)
        Route::post('teams/{team}/players', [PlayerController::class, 'store']);
        Route::put('players/{player}', [PlayerController::class, 'update']);
        Route::delete('players/{player}', [PlayerController::class, 'destroy']);
        Route::get('players/{player}', [PlayerController::class, 'show']); // Added
        // Player Preferences
        Route::post('players/{player}/preferences', [PlayerPreferenceController::class, 'store']);
        Route::get('players/{player}/preferences', [PlayerPreferenceController::class, 'show']);
        Route::put('teams/{team}/bulk-player-preferences', [PlayerPreferenceController::class, 'bulkUpdateByTeam']);
        Route::get('teams/{team}/bulk-player-preferences', [PlayerPreferenceController::class, 'bulkShowByTeam']);

        // Game Management
        Route::get('teams/{team}/games', [GameController::class, 'index']);
        Route::post('teams/{team}/games', [GameController::class, 'store']);
        Route::get('games/{game}', [GameController::class, 'show']);
        Route::put('games/{game}', [GameController::class, 'update']); // Updates game details, not lineup
        Route::delete('games/{game}', [GameController::class, 'destroy']);

        // Lineup & PDF
        Route::get('games/{game}/lineup', [GameController::class, 'getLineup']);
        Route::put('games/{game}/lineup', [GameController::class, 'updateLineup']);
        Route::post('games/{game}/autocomplete-lineup', [GameController::class, 'autocompleteLineup']);
        Route::get('games/{game}/pdf-data', [GameController::class, 'getLineupPdfData']);

        // Supporting Lists
        Route::get('organizations', [OrganizationController::class, 'index']); // User list view
        Route::get('organizations/{organization}', [OrganizationController::class, 'showForUser']); // User list view
        // --- NEW ROUTE: Get/Check Organization by Code ---
        Route::get('organizations/by-code/{organization_code}', [OrganizationController::class, 'showByCode']);
        Route::get('positions', [PositionController::class, 'index']);         // User list view

        Route::post('user/validate-organization-access-code', [UserAuthController::class, 'validateOrganizationAccessCode']);
        Route::get('user/subscription/generate-payment-link', [StripeController::class, 'generateWebPaymentLink']);
        // --- Stripe Payment Initiation ---
        Route::post('teams/{team}/create-payment-intent', [StripeController::class, 'createTeamPaymentIntent']); // Added
        Route::get('payment-details', [StripeController::class, 'showUnlockDetails']); // Added
        // --- User Payment History ---
        Route::get('payments/history', [StripeController::class, 'userPaymentHistory']);

        Route::post('promo-codes/redeem', [UserPromoCodeController::class, 'redeem']); // Added
        Route::get('promo-codes/redemption-history', [UserPromoCodeController::class, 'redemptionHistory']);

    }); // End User middleware group

    // Stripe Webhook
    Route::post('stripe/webhook', [StripeController::class, 'handleWebhook'])->name('stripe.webhook'); // Added & named


    // --- Protected Admin Routes ---
    Route::middleware('auth:api_admin')->prefix('admin')->group(function () {

        Route::prefix('utils')->controller(AdminUtilityController::class)->group(function () {
            Route::post('migrate-and-seed', 'migrateAndSeed');
            Route::post('migrate-fresh-and-seed', 'migrateFreshAndSeed');
        });

        // Organization Management (Full CRUD except index handled separately)
        Route::apiResource('organizations', OrganizationController::class)->except(['index']);
        Route::get('organizations', [OrganizationController::class, 'index']); // Admin list view (might differ from user view)
        // --- NEW ROUTE: Get/Check Organization by Code ---
        Route::get('organizations/by-code/{organization_code}', [OrganizationController::class, 'showByCode']);

        Route::get('settings', [AdminSettingsController::class, 'show']);
        Route::put('settings', [AdminSettingsController::class, 'update']);
        // Position Management (Full CRUD except index handled separately)
        Route::apiResource('positions', PositionController::class)->except(['index']);
        Route::get('positions', [PositionController::class, 'index']);         // Admin list view (might differ from user view)

        // User Management (Full CRUD) - Using AdminUserController
        Route::apiResource('users', AdminController::class);

        Route::apiResource('promo-codes', AdminPromoCodeController::class); // Added

        Route::apiResource('payments', AdminPaymentController::class)->only([
            'index', 'show' // Admins likely only need to view payments, not create/edit/delete them via API
        ]);
    }); // End Admin middleware group

}); // End V1 prefix group
