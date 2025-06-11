<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use App\Models\Organization; // Import Organization
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Response as HttpResponse;
use Carbon\Carbon;
use Illuminate\Support\Str; // For Str::random
use Illuminate\Support\Facades\Mail; // For Mail
use App\Mail\OrganizationCreatedMail;
use App\Mail\OrganizationSubscriptionRenewedViaPromoMail;
use Illuminate\Validation\Rule;

// Mailable for org admin

class PromoCodeController extends Controller
{
    use ApiResponseTrait;

    /**
     * User redeems a promo code to create and activate a new Organization.
     * The redeeming user becomes the creator/admin of this new Organization.
     * Route: POST /promo-codes/redeem
     */

    public function redeem(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'organization_code' => 'nullable|string|exists:organizations,organization_code', // Optional: to renew existing
//            'organization_name' => ['nullable', 'string', 'max:255', Rule::requiredIf(empty($request->organization_code))] // Required if creating new
        ]);

        $newOrganizationName = $user->last_name . "'s "."Organization (". ($user->administeredOrganizations()->count() + 1).")";

        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $promoCodeString = strtoupper($request->input('code'));
        $targetOrganizationCode = $request->input('organization_code') ? strtoupper($request->input('organization_code')) : null;
//        $newOrganizationName = $request->input('organization_name');

        $promoCode = PromoCode::where('code', $promoCodeString)->first();
        if (!$promoCode) return $this->notFoundResponse('Invalid promo code.');

        // --- Promo Code Validation (active, not expired, global uses) ---
        if (!$promoCode->is_active) return $this->errorResponse('This promo code is not active.', HttpResponse::HTTP_BAD_REQUEST);
        if ($promoCode->expires_at && $promoCode->expires_at->isPast()) return $this->errorResponse('This promo code has expired.', HttpResponse::HTTP_BAD_REQUEST);
        if ($promoCode->hasReachedMaxUses()) return $this->errorResponse('This promo code has reached its global usage limit.', HttpResponse::HTTP_BAD_REQUEST);

        // --- User's Global Usage Limit for this Specific Promo Code ---
        $userGlobalRedemptionCount = PromoCodeRedemption::where('user_id', $user->id)
            ->where('promo_code_id', $promoCode->id)
            ->count();
        if ($userGlobalRedemptionCount >= $promoCode->max_uses_per_user) {
            return $this->errorResponse('You have already used this promo code the maximum number of times allowed.', HttpResponse::HTTP_BAD_REQUEST);
        }

        // --- Determine if RENEWING or CREATING NEW ORG ---
        $organizationToProcess = null;
        $isRenewal = false;

        if ($targetOrganizationCode) {
            $organizationToProcess = Organization::where('organization_code', $targetOrganizationCode)->first();
            // User must be the creator to renew their own organization
            if (!$organizationToProcess || $organizationToProcess->creator_user_id !== $user->id) {
                return $this->forbiddenResponse('You do not own this organization or the code is invalid.');
            }
            // Optionally, allow renewal only if subscription is near expiry or expired
            // if ($organizationToProcess->hasActiveSubscription() && $organizationToProcess->subscription_expires_at->gt(now()->addDays(7))) {
            //     return $this->errorResponse('This organization\'s subscription is not yet due for renewal.', HttpResponse::HTTP_CONFLICT);
            // }
            $isRenewal = true;
            Log::info("Promo redemption attempt for RENEWAL of Org ID: {$organizationToProcess->id} by User ID: {$user->id}");
        } else {
            // Logic for creating a new organization
            // Optional: Check if user can create another org via promo
            Log::info("Promo redemption attempt for CREATING NEW Org by User ID: {$user->id}");
        }


        // --- Perform Action within Transaction ---
        $rawPasswordForNewOrg = null; // Only for new org
        $finalOrganization = null;    // To hold the org for email
        $actualExpiryDate = null;

        try {
            DB::transaction(function () use (
                $user, $promoCode, $isRenewal, $organizationToProcess, $newOrganizationName,
                &$rawPasswordForNewOrg, &$finalOrganization, &$actualExpiryDate
            ) {
                $settings = Settings::instance();
                $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;

                if ($isRenewal) {
                    $organization = $organizationToProcess; // Already fetched
                    // Calculate new expiry: add duration to current expiry if active, else from now
                    $newExpiry = $organization->subscription_expires_at && $organization->subscription_expires_at->isFuture()
                        ? $organization->subscription_expires_at->addDays($durationDays)
                        : Carbon::now()->addDays($durationDays);

                    $organization->subscription_status = 'active'; // Ensure active
                    $organization->subscription_expires_at = $newExpiry;
                    $organization->save();

                    $finalOrganization = $organization;
                    $actualExpiryDate = $newExpiry;
                    Log::info("Org ID {$organization->id} subscription RENEWED via promo by User ID {$user->id}. New Expiry: {$newExpiry->toIso8601String()}");

                } else { // Creating a new organization
                    $rawPasswordForNewOrg = Str::random(12);
                    $orgCode = '';
                    do { $orgCode = 'ORG-' . strtoupper(Str::random(8)); }
                    while (Organization::where('organization_code', $orgCode)->exists());

                    $organization = new Organization();
                    $organization->name = $newOrganizationName;
                    $organization->email = $user->email; // Default to creator's email

                    // grantSubscriptionAccess now expects creator and rawPassword
                    // For promo, we don't have stripe IDs
                    $expiryDateForNewOrg = Carbon::now()->addDays($durationDays);
                    $organization->grantSubscriptionAccess(
                        $expiryDateForNewOrg,
                        $user,
                        $rawPasswordForNewOrg
                    ); // This saves the organization

                    $finalOrganization = $organization;
                    $actualExpiryDate = $expiryDateForNewOrg;
                    Log::info("NEW Org ID {$organization->id} created & activated via promo by User ID {$user->id}. Org Code: {$organization->organization_code}");
                }

                // Record the redemption
                PromoCodeRedemption::create([
                    'user_id' => $user->id,
                    'promo_code_id' => $promoCode->id,
                    'organization_id' => $finalOrganization->id, // Link to the created/renewed org
                    'redeemed_at' => now()
                ]);
                $promoCode->increment('use_count');
            }); // End Transaction


            // --- Send Appropriate Email ---
            $durationString = $this->getHumanReadableDuration(Settings::instance()->access_duration_days);
            $message = "";

            // --- Send Appropriate Email ---
            $durationString = $this->getHumanReadableDuration(Settings::instance()->access_duration_days);
            $message = "";

            if ($isRenewal) {
                $message = "Promo code redeemed! Subscription for organization '{$finalOrganization->name}' has been renewed {$durationString}.";
                // Send Renewal Email to Organization's Creator (which is the current $user)
                if ($finalOrganization && $user->email && $user->receive_payment_notifications) {
                    try {
                        Mail::to($user->email)->send(new OrganizationSubscriptionRenewedViaPromoMail($finalOrganization, $user, $promoCode));
                        Log::info("Organization subscription renewal via promo email sent to {$user->email} for Org ID {$finalOrganization->id}");
                    } catch (\Exception $e) {
                        Log::error("Mail Error (OrgSubRenewedPromo to User): {$e->getMessage()}");
                    }
                }
            } else { // New Organization was created
                $message = "Promo code redeemed! Organization '{$finalOrganization->name}' created and activated {$durationString}. Login details for the organization have been sent to your email.";
                if ($finalOrganization && $user->email && $rawPasswordForNewOrg && $user->receive_payment_notifications) {
                    try {
                        Mail::to($user->email)->send(new OrganizationCreatedMail($finalOrganization, $rawPasswordForNewOrg, $user));
                        Log::info("Organization creation details (promo) sent to creator {$user->email}");
                    } catch (\Exception $e) {
                        Log::error("Mail Error (OrgCreatedPromo to User): {$e->getMessage()}");
                    }
                }
            }

            return $this->successResponse(
                [
                    'organization_id' => $finalOrganization->id,
                    'organization_name' => $finalOrganization->name,
                    'organization_code' => $finalOrganization->organization_code,
                    'subscription_expires_at' => $actualExpiryDate?->toISOString()
                ],
                $message
            );

        } catch (\Exception $e) {
            Log::error("Promo redemption failed: User {$user->id}, Code: {$promoCodeString}, Error: " . $e->getMessage(), ['exception' => $e]);
            return $this->errorResponse('Failed to redeem promo code.', HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    /**
     * Display the authenticated user's promo code redemption history.
     * Shows promo codes redeemed by this user and which organization was affected.
     */
    public function redemptionHistory(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        if (!$user) return $this->unauthorizedResponse('User not authenticated.');

        $redemptions = PromoCodeRedemption::where('user_id', $user->id)
            ->with([
                'promoCode:id,code,description',
                'organization:id,name,organization_code' // Load org linked to this redemption
            ])
            ->orderBy('redeemed_at', 'desc')
            ->select([
                'id', 'promo_code_id', 'organization_id', 'redeemed_at',
                // user_organization_access_code column removed from this table
            ])
            ->paginate($request->input('per_page', 15));

        return $this->successResponse($redemptions, 'Promo code redemption history retrieved successfully.');
    }
}