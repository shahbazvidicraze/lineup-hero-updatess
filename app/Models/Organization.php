<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable; // For login
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash; // For password hashing

class Organization extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email', // Organization's contact email
        'organization_code', // Acts as username
        'password', // Hashed password for organization panel login
        'creator_user_id', // ID of the User who created/paid for this org
        'subscription_status',
        'subscription_expires_at',
        'stripe_customer_id', // Stripe Customer ID associated with this Org's subscription
        'stripe_subscription_id', // Stripe Subscription ID for this Org
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token', // Default hidden attribute
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'subscription_expires_at' => 'datetime',
        'password' => 'hashed', // Ensure password is automatically hashed if set directly
    ];

    /**
     * Get the user who created this organization.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    /**
     * Get the teams belonging to this organization.
     */
    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Get the payments associated with this organization's subscription.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the promo code redemptions that activated this organization.
     */
    public function promoCodeRedemptions()
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    // --- JWTSubject Methods ---
    public function getJWTIdentifier()
    {
        return $this->getKey(); // Returns the ID
    }

    public function getJWTCustomClaims()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'organization_code' => $this->organization_code,
            'type' => 'organization_admin', // Custom claim to identify type
            'creator_user_id' => $this->creator_user_id,
            'has_active_subscription' => $this->hasActiveSubscription(),
            'subscription_expires_at' => $this->subscription_expires_at?->toISOString(),
        ];
    }

    // --- Subscription Logic ---
    public function hasActiveSubscription(): bool
    {
        if ($this->subscription_status !== 'active') {
            return false;
        }
        if ($this->subscription_expires_at && $this->subscription_expires_at->isPast()) {
            // Optional: Add logic to automatically set status to 'past_due' or 'inactive'
            return false;
        }
        return true;
    }

    /**
     * Grant subscription access to the organization.
     * Called after successful payment or promo redemption.
     */
    public function grantSubscriptionAccess(
        Carbon $expiresAt,
        User $creator, // The user who initiated this
        string $rawPassword, // For first-time creation
        ?string $stripeSubscriptionId = null,
        ?string $stripeCustomerId = null
    ): void {
        $this->creator_user_id = $creator->id;
        $this->subscription_status = 'active';
        $this->subscription_expires_at = $expiresAt;
        $this->password = $rawPassword; // Will be hashed by the 'hashed' cast

        // Generate unique organization_code if not already set (e.g., during creation)
        if (empty($this->organization_code)) {
            do {
                $this->organization_code = 'ORG-' . strtoupper(Str::random(8));
            } while (self::where('organization_code', $this->organization_code)->exists());
        }

        if ($stripeSubscriptionId) {
            $this->stripe_subscription_id = $stripeSubscriptionId;
        }
        if ($stripeCustomerId) {
            $this->stripe_customer_id = $stripeCustomerId;
        }
        $this->save();
    }

    public function revokeSubscriptionAccess(string $newStatus = 'inactive'): void
    {
        $this->subscription_status = $newStatus;
        // Consider if expiry or password should be nulled out on revoke
        $this->save();
    }
}