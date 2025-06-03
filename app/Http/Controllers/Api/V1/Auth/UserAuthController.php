<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Mail\PasswordChangedMail;
use App\Mail\WelcomeUserMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetOtpMail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response; // For status codes

class UserAuthController extends Controller
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    protected $guard = 'api_user';
    protected const OTP_EXPIRY_MINUTES = 10;

    /**
     * Validate the user's provided organization access code against their stored code.
     * Route: POST /user/validate-organization-access-code
     */
    public function validateOrganizationAccessCode(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'organization_access_code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $providedCode = $request->input('organization_access_code');

        if (!$user->hasActiveSubscription()) {
            return $this->errorResponse('You do not have an active subscription to use an organization access code.', Response::HTTP_FORBIDDEN);
        }

        if (empty($user->organization_access_code)) {
            // This case implies a subscription might be active but code wasn't generated/assigned,
            // which would be an internal issue.
            Log::error("User {$user->id} has active sub but no organization_access_code stored.");
            return $this->errorResponse('Access code not found for your account. Please contact support.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (strtoupper($providedCode) === strtoupper($user->organization_access_code)) {
            // Optionally return the main organization's ID if Flutter needs it explicitly
            $mainOrganization = \App\Models\Organization::first(); // Assuming there's always one
            return $this->successResponse(
                ['organization_id' => $mainOrganization?->id, 'organization_name' => $mainOrganization?->name],
                'Organization access code is valid.'
            );
        } else {
            return $this->errorResponse('Invalid organization access code provided.', Response::HTTP_BAD_REQUEST);
        }
    }

    protected function formatTokenResponse($token, $user)
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth($this->guard)->factory()->getTTL() * 60,
            'user' => $user
        ];
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'email' => 'required|string|email|max:100|unique:users,email',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $user = User::create([ /* ... user data ... */
            'first_name' => $request->first_name, 'last_name' => $request->last_name,
            'email' => $request->email, 'phone' => $request->phone,
            'password' => Hash::make($request->password),
        ]);

        // --- Send Welcome Email ---
        try {
            Mail::to($user->email)->send(new WelcomeUserMail($user));
            Log::info("Welcome email sent to {$user->email}");
        } catch (\Exception $e) {
            // Log the error but don't fail the registration
            Log::error('Failed to send welcome email: ' . $e->getMessage(), ['user_email' => $user->email]);
        }

        $token = JWTAuth::fromUser($user);
        return $this->successResponse($this->formatTokenResponse($token, $user), 'User registered successfully.', Response::HTTP_CREATED);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $credentials = $validator->validated();

        // Check if user with the given email exists
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            // User with this email does not exist
            return $this->errorResponse('Email not found.', Response::HTTP_UNAUTHORIZED);
        }

        // User exists, now check the password
        if (!Hash::check($credentials['password'], $user->password)) {
            // Password for the existing user is incorrect
            return $this->errorResponse('Incorrect password.', Response::HTTP_UNAUTHORIZED);
        }

        // If email and password are correct, attempt to get a token
        if (! $token = auth($this->guard)->attempt($credentials)) {
            // This case should ideally not be hit if the above checks pass,
            // but as a fallback for other auth issues (e.g., user inactive, guard misconfig)
            return $this->errorResponse('Login failed. Please try again.', Response::HTTP_UNAUTHORIZED);
        }

        return $this->successResponse(
            $this->formatTokenResponse($token, $user), // Pass the $user object we already fetched
            'Login successful.'
        );
    }

    public function logout()
    {
        try {
            auth($this->guard)->logout();
            return $this->successResponse(null, 'User successfully signed out.', Response::HTTP_OK, false);
        } catch (\Exception $e) {
            Log::error('User logout failed: ' . $e->getMessage());
            return $this->errorResponse('Could not sign out.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function refresh()
    {
        try {
            $newToken = auth($this->guard)->refresh();
            return $this->successResponse($this->formatTokenResponse($newToken, auth($this->guard)->user()), 'Token refreshed successfully.');
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return $this->errorResponse('Token is invalid.', Response::HTTP_UNAUTHORIZED);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return $this->errorResponse('Could not refresh token.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function profile()
    {
        try {
            return $this->successResponse(auth($this->guard)->userOrFail());
        } catch (\Tymon\JWTAuth\Exceptions\UserNotDefinedException $e) {
            return $this->notFoundResponse('User not found or token invalid.');
        }
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->unauthorizedResponse('User not authenticated.');

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:100', 'last_name' => 'sometimes|required|string|max:100',
            'email' => ['sometimes','required','string','email','max:100', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable','string','max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'receive_payment_notifications' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $user->fill($validator->validated());
        $user->save();
        return $this->successResponse($user, 'Profile updated successfully.');
    }

    /**
     * Send Password Reset OTP to User's Email.
     * Route: POST /user/auth/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $user = User::where('email', $request->email)->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('User with this email not found.');
        }

        $otp = Str::padLeft((string) random_int(0, 999999), 6, '0');
        $expiresAt = Carbon::now()->addMinutes(self::OTP_EXPIRY_MINUTES)->toDateTimeString();
        $now = Carbon::now()->toDateTimeString();

        try {
            // Using DB::table for password_reset_otps
            DB::table('password_reset_otps')->updateOrInsert(
                ['email' => $user->email], // Conditions to find the record
                [                         // Values to update or insert
                    'otp' => $otp,
                    'expires_at' => $expiresAt,
                    'created_at' => $now
                ]
            );
            Log::info("DB: OTP stored/updated for {$user->email}");

        } catch (\Exception $e) {
            Log::error('DB: Failed to store/update password reset OTP: ' . $e->getMessage(), [
                'user_email' => $user->email, 'exception' => $e
            ]);
            return $this->errorResponse('Could not process your password reset request. Please try again later.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            Mail::to($user->email)->send(new PasswordResetOtpMail($otp, $user->first_name ?? 'User'));
            return $this->successResponse(null, 'Password reset OTP has been sent to your email.', Response::HTTP_OK, false);
        } catch (\Exception $e) {
            Log::error('Failed to send password reset OTP email: ' . $e->getMessage(), ['user_email' => $user->email]);
            return $this->errorResponse('Could not send OTP email. Please try again later.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reset User's Password using OTP.
     * Route: POST /user/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|digits:6',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        // Find OTP record using DB::table()
        $otpRecord = DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$otpRecord) {
            return $this->errorResponse('Invalid or incorrect OTP.', Response::HTTP_BAD_REQUEST);
        }

        // Check if OTP has expired
        if (Carbon::parse($otpRecord->expires_at)->isPast()) {
            DB::table('password_reset_otps')->where('email', $request->email)->delete(); // Delete expired OTP
            return $this->errorResponse('OTP has expired. Please request a new one.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = User::where('email', $request->email)->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('User with this email not found for password reset.');
        }

        try {
            $user->password = Hash::make($request->password);
            $user->save();

            DB::table('password_reset_otps')->where('email', $request->email)->delete(); // Delete OTP record

            return $this->successResponse(null, 'Password has been reset successfully.', Response::HTTP_OK, false);

        } catch (\Exception $e) {
            Log::error('Failed to reset password for user: ' . $request->email . '. Error: ' . $e->getMessage(), ['exception' => $e]);
            return $this->errorResponse('Could not reset your password at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'current_password' => ['required','string', function ($attr, $val, $fail) use ($user) { if (!Hash::check($val, $user->password)) $fail('Current password incorrect.'); }],
            'password' => ['required','confirmed', Password::defaults(), 'different:current_password'],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $user->password = Hash::make($request->password);
        $user->save();

        // --- Send Password Changed Notification ---
        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user));
            Log::info("Password changed notification sent to {$user->email} after OTP reset.");
        } catch (\Exception $e) {
            Log::error('Failed to send password changed (OTP reset) email: ' . $e->getMessage(), ['user_email' => $user->email]);
        }
        // --- End Send Notification ---

        return $this->successResponse(null, 'Password changed successfully.', Response::HTTP_OK, false);
    }
}
