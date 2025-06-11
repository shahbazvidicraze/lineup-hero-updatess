<?php
namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Organization;
use App\Models\Team;
use App\Models\Settings; // For renewal price
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\Rules\Password;
use Stripe\PaymentIntent; // For renewal
use Stripe\Stripe;      // For renewal
use Stripe\Exception\ApiErrorException; // For renewal

class OrganizationPanelController extends Controller
{
    use ApiResponseTrait;
    protected $guard = 'api_org_admin'; // Define guard

    public function __construct() {
        Stripe::setApiKey(config('services.stripe.secret')); // For renewal intent
        Stripe::setApiVersion('2024-04-10');
    }


    protected function formatTokenResponse($token, Organization $organization) {
        return [
            'access_token' => $token, 'token_type' => 'bearer',
            'expires_in' => auth($this->guard)->factory()->getTTL() * 60,
            'user_type' => 'organization_admin',
            'organization' => $organization->only(['id', 'name', 'organization_code', 'email', 'subscription_status', 'subscription_expires_at', 'creator_user_id'])
        ];
    }

    public function login(Request $request) {
        $validator = Validator::make($request->all(), [
            'organization_code' => 'required|string',
            'password' => 'required|string',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        // Attempt login using 'organization_code' as the username field
        $credentials = ['organization_code' => strtoupper($request->organization_code), 'password' => $request->password];

        if (!$token = auth($this->guard)->attempt($credentials)) {
            // Check if org code exists to give more specific feedback (optional, slight enumeration risk)
            $org = Organization::where('organization_code', $credentials['organization_code'])->first();
            if (!$org) return $this->errorResponse('Invalid organization code.', Response::HTTP_UNAUTHORIZED);
            return $this->errorResponse('Incorrect password for organization.', Response::HTTP_UNAUTHORIZED);
        }
        return $this->successResponse($this->formatTokenResponse($token, auth($this->guard)->user()), 'Organization login successful.');
    }

    public function logout(Request $request) {
        try { auth($this->guard)->logout(); return $this->successResponse(null, 'Successfully logged out.'); }
        catch (\Exception $e) { return $this->errorResponse('Could not log out.', Response::HTTP_INTERNAL_SERVER_ERROR); }
    }

    public function profile(Request $request) { // Org Profile
        /** @var Organization $organization */
        $organization = $request->user(); // Authenticated Organization
        return $this->successResponse($organization->only(['id','name','email','organization_code','subscription_status','subscription_expires_at','creator_user_id']));
    }

    public function changePassword(Request $request) {
        /** @var Organization $organization */
        $organization = $request->user();
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', function ($attribute, $value, $fail) use ($organization) {
                if (!Hash::check($value, $organization->password)) $fail('Current password incorrect.');
            }],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);
        $organization->password = $request->password; // Model cast will hash
        $organization->save();
        return $this->successResponse(null, 'Organization password changed successfully.');
    }

    public function listTeams(Request $request) {
        /** @var Organization $organization */
        $organization = $request->user();
        $teams = $organization->teams()->with('user:id,first_name,last_name,email') // Show team owner
        ->orderBy('name')->paginate($request->input('per_page', 15));
        return $this->successResponse($teams, 'Teams retrieved successfully.');
    }

    public function showTeam(Request $request, Team $team) {
        /** @var Organization $organization */
        $organization = $request->user();
        if ($team->organization_id !== $organization->id) return $this->forbiddenResponse('This team does not belong to your organization.');
        $team->load(['user:id,first_name,last_name,email', 'players' => fn($q) => $q->select('id','team_id','first_name','last_name','jersey_number')]);
        return $this->successResponse($team);
    }

    public function deleteTeam(Request $request, Team $team) {
        /** @var Organization $organization */
        $organization = $request->user();
        if ($team->organization_id !== $organization->id) return $this->forbiddenResponse('This team does not belong to your organization.');
        $team->delete();
        return $this->successResponse(null, 'Team deleted successfully from organization.', Response::HTTP_OK, false);
    }

    public function createSubscriptionRenewalIntent(Request $request) {
        /** @var Organization $organization */
        $organization = $request->user();

        // Optionally check if subscription is already very far in future, or near expiry
        // if ($organization->hasActiveSubscription() && $organization->subscription_expires_at->gt(now()->addMonths(11))) {
        //    return $this->errorResponse('Subscription renewal can only be done closer to expiry.', Response::HTTP_CONFLICT);
        // }

        $settings = Settings::instance(); /* ... get amount/currency ... */
        $amountInDollars = (float)$settings->unlock_price_amount; $currency = $settings->unlock_currency;
        $amountInCents = (int)round($amountInDollars * 100);

        try {
            if (!$organization->stripe_customer_id) { // Org should have a Stripe customer ID if previously paid
                $customer = \Stripe\Customer::create(['email' => $organization->email ?? $organization->creator?->email, 'name' => $organization->name, 'metadata' => ['organization_id' => $organization->id]]);
                $organization->stripe_customer_id = $customer->id;
                $organization->saveQuietly();
            }
            $paymentIntent = PaymentIntent::create([
                'amount' => $amountInCents, 'currency' => $currency,
                'customer' => $organization->stripe_customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "Renewal Subscription for Organization: {$organization->name} ({$organization->organization_code})",
                'metadata' => [ 'organization_id' => $organization->id, 'organization_code' => $organization->organization_code, 'renewal' => 'true' ],
            ]);
            return $this->successResponse([ 'clientSecret' => $paymentIntent->client_secret, /* ... other details ... */ ], 'Renewal Payment Intent created.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generates a secure, temporary signed URL for the Organization Admin
     * to renew their Organization's subscription via a web page.
     * Route: POST /organization-panel/subscription/generate-renewal-link (Requires Org Admin Auth)
     */
    public function generateWebRenewalLink(Request $request)
    {
        /** @var \App\Models\Organization $organization */
        $organization = $request->user(); // Authenticated Organization

        // Optional: Check if renewal is appropriate (e.g., not too early)
        if ($organization->hasActiveSubscription() && $organization->subscription_expires_at->gt(now()->addMonths(1))) { // Example: 1 month buffer
            return $this->errorResponse(
                'Subscription renewal can typically be done closer to the expiry date.',
                Response::HTTP_CONFLICT,
                ['expires_at' => $organization->subscription_expires_at->toFormattedDayDateString()]
            );
        }

        try {
            $signedUrl = URL::temporarySignedRoute(
                'organization.payment.initiate.renewal', // <-- Use the new route name for renewal
                now()->addMinutes(30),
                ['organization' => $organization->id] // Pass the organization ID
            );
            return $this->successResponse(['payment_url' => $signedUrl], 'Secure renewal payment link generated.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to generate renewal link for Org ID {$organization->id}: " . $e->getMessage());
            return $this->errorResponse('Could not generate renewal link.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}