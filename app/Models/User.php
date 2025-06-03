<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject; // <-- Import JWTSubject
use Carbon\Carbon; // Import Carbon
use Illuminate\Support\Str; // Import Str for code generation

class User extends Authenticatable implements JWTSubject // <-- Implement JWTSubject
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name', // Added
        'last_name',  // Added
        'email',
        'password',
        'phone',      // Added
        'role_id',
        'receive_payment_notifications',
        'subscription_status',          // ADDED
        'subscription_expires_at',    // ADDED
        'stripe_customer_id',         // ADDED
        'stripe_subscription_id',     // ADDED
        'organization_access_code',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'receive_payment_notifications' => 'boolean',
            'subscription_expires_at' => 'datetime',
        ];
    }

    /**
     * Check if the user has an active subscription.
     */
    public function hasActiveSubscription(): bool
    {
        if ($this->subscription_status !== 'active') {
            return false;
        }
        if ($this->subscription_expires_at && $this->subscription_expires_at->isPast()) {
            // Optional: Logic to update status to 'past_due' or 'inactive'
            return false;
        }
        return true;
    }

    /**
     * Grant subscription access to the user.
     * Generates a unique organization access code.
     */
    public function grantSubscriptionAccess(Carbon $expiresAt, ?string $stripeSubscriptionId = null): void
    {
        $this->subscription_status = 'active';
        $this->subscription_expires_at = $expiresAt;
        if ($stripeSubscriptionId) {
            $this->stripe_subscription_id = $stripeSubscriptionId;
        }

        // Generate a unique organization_access_code if not already set or if re-subscribing
        // This code should be unique across all users.
//        if (empty($this->organization_access_code)) {
//            do {
//                // Example: ORG-USERID-RANDOM
//                $this->organization_access_code = Organization::first()->organization_code . '-' . strtoupper(Str::random(6));
//            } while (User::where('organization_access_code', $this->organization_access_code)->exists());
//        }

        if (empty($this->organization_access_code)) { // Or perhaps: if (empty($this->organization_access_code) || $this->subscription_status !== 'active')
            // to regenerate if they re-subscribe after a lapse.
            // For now, only generating if empty is fine.
            do {
                // Get the main organization's code
                $mainOrganizationCode = "DEFAULTORG"; // Fallback
                $mainOrg = \App\Models\Organization::first(); // Assuming you have one main organization
                if ($mainOrg && !empty($mainOrg->organization_code)) {
                    $mainOrganizationCode = $mainOrg->organization_code;
                } else {
                    // Log a warning if the main organization or its code is not found
                    // This indicates a setup issue.
                    \Illuminate\Support\Facades\Log::warning("Main organization or its code not found for generating user access code. User ID: {$this->id}");
                    // You might decide to use a generic prefix or throw an exception if this is critical
                }

                // New access code format: MAIN_ORG_CODE-USERID-RANDOM_SUFFIX
                $this->organization_access_code = strtoupper($mainOrganizationCode) . '-' . $this->id . '-' . strtoupper(Str::random(6));
            } while (User::where('organization_access_code', $this->organization_access_code)->exists());
        }
        $this->save();
    }

    /**
     * Revoke subscription access.
     */
    public function revokeSubscriptionAccess(string $newStatus = 'inactive'): void
    {
        $this->subscription_status = $newStatus; // e.g., 'inactive', 'canceled', 'past_due'
        // $this->subscription_expires_at = null; // Or keep it for history
        // $this->organization_access_code = null; // Optional: Nullify code on cancellation
        $this->save();
    }

    // Add this method inside the User class
    public function promoCodeRedemptions()
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    // JWTSubject Methods Implementation START

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name, // Use first_name
            'last_name' => $this->last_name,   // Use last_name
            'email' => $this->email,
            'phone' => $this->phone,           // Include phone if needed in token
            'type' => 'user',
        ];
    }
    // JWTSubject Methods Implementation END

    // Define Relationships
    public function teams()
    {
        return $this->hasMany(Team::class);
    }

     // Optional: Accessor to get full name easily
     public function getFullNameAttribute(): string
     {
         return "{$this->first_name} {$this->last_name}";
     }
}
