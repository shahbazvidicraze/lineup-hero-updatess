<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User; // Ensure User model is imported
use App\Models\Settings; // Import Settings
// use App\Models\Team; // No longer directly needed for user-level promo redemption logic
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Response as HttpResponse;
use Carbon\Carbon;

class PromoCodeController extends Controller
{
    use ApiResponseTrait;

    public function redeem(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            // team_id is no longer required in the request if promo applies to user account
            // 'team_id' => 'required|integer|exists:teams,id',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $codeString = strtoupper($request->input('code'));
        // $teamId = $request->input('team_id'); // No longer needed

        $promoCode = PromoCode::where('code', $codeString)->first();
        if (!$promoCode) return $this->notFoundResponse('Invalid promo code.');

        // Team specific checks removed if promo applies to user directly
        // $team = Team::find($teamId);
        // if (!$team) return $this->notFoundResponse('Team not found.');
        // if ($team->user_id !== $user->id) return $this->forbiddenResponse('You do not own this team.');

        // Check if USER already has active subscription
        if ($user->hasActiveSubscription()) {
            return $this->errorResponse(
                'Your account already has an active subscription. Expires: ' . $user->subscription_expires_at?->toFormattedDayDateString(),
                HttpResponse::HTTP_CONFLICT
            );
        }

        // Code status & limits checks (as before)
        if (!$promoCode->is_active) return $this->errorResponse('This promo code is not active.', HttpResponse::HTTP_BAD_REQUEST);
        if ($promoCode->expires_at && $promoCode->expires_at->isPast()) return $this->errorResponse('This promo code has expired.', HttpResponse::HTTP_BAD_REQUEST);
        if ($promoCode->hasReachedMaxUses()) return $this->errorResponse('This promo code has reached its global usage limit.', HttpResponse::HTTP_BAD_REQUEST);

        // User global usage limit for THIS specific promo code
        $userGlobalRedemptionCount = PromoCodeRedemption::where('user_id', $user->id)
            ->where('promo_code_id', $promoCode->id)
            ->count();
        if ($userGlobalRedemptionCount >= $promoCode->max_uses_per_user) {
            return $this->errorResponse('You have already used this promo code the maximum number of times allowed.', HttpResponse::HTTP_BAD_REQUEST);
        }

        try {
            $actualExpiryDate = null;
            $organizationAccessCodeGenerated = null;

            DB::transaction(function () use ($user, $promoCode, &$actualExpiryDate, &$organizationAccessCodeGenerated) {
                // 1. Grant subscription access to the user (this sets expiry and org code on User model)
                $settings = Settings::instance();
                $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;
                $expiryDate = Carbon::now()->addDays($durationDays);
                $user->grantSubscriptionAccess($expiryDate); // This generates/assigns user->organization_access_code

                // Store the code that was active/generated at this point for this redemption
                $organizationAccessCodeGenerated = $user->organization_access_code;
                $actualExpiryDate = $expiryDate; // Store for message

                // 2. Record the redemption, including the access code for this specific redemption
                PromoCodeRedemption::create([
                    'user_id' => $user->id,
                    'promo_code_id' => $promoCode->id,
                    // 'team_id' => null, // Column removed or nullable
                    'user_organization_access_code' => $organizationAccessCodeGenerated, // Store the code
                    'redeemed_at' => now()
                ]);

                // 3. Increment the global use count for the promo code
                $promoCode->increment('use_count');

                Log::info("Promo code {$promoCode->code} redeemed by User ID {$user->id}. Access Code: {$organizationAccessCodeGenerated}. Expires: {$actualExpiryDate->toIso8601String()}");
            });

            $durationString = "for a limited time";
            if ($actualExpiryDate) {
                $daysGranted = Carbon::now()->diffInDays($actualExpiryDate, false);
                if ($daysGranted < 0) $daysGranted = 0;
                $durationString = $this->getHumanReadableDuration((int) round($daysGranted));
            }

            return $this->successResponse(
                [
                    'organization_access_code' => $organizationAccessCodeGenerated,
                    'subscription_expires_at' => $actualExpiryDate?->toISOString()
                ],
                'Promo code redeemed! Your account access is granted ' . $durationString . '. Your access code is ' . $organizationAccessCodeGenerated . '.'
            );

        } catch (\Exception $e) {
            Log::error("Promo redemption failed: User {$user->id}, Code: {$promoCode->code}, Error: " . $e->getMessage());
            return $this->errorResponse('Failed to redeem promo code.', HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Display the authenticated user's promo code redemption history.
     * Includes the organization access code associated with each redemption.
     * Route: GET /promo-codes/redemption-history
     */
    public function redemptionHistory(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        if (!$user) return $this->unauthorizedResponse('User not authenticated.');

        $redemptions = PromoCodeRedemption::where('user_id', $user->id)
            ->with(['promoCode:id,code,description'])
            ->orderBy('redeemed_at', 'desc')
            ->select([
                'id', 'promo_code_id',
                'user_organization_access_code', // <-- SELECT THIS
                'redeemed_at',
            ])
            ->paginate($request->input('per_page', 15));

        return $this->successResponse($redemptions, 'Promo code redemption history retrieved successfully.');
    }
}