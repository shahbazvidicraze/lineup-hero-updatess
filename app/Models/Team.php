<?php

namespace App\Models;

use
    Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Models\Settings;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'season',
        'year',
        'sport_type',
        'team_type',
        'age_group',
        'city',
        'state',
        'country',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    // --- Relationships ---

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function games()
    {
        return $this->hasMany(Game::class);
    }

    public function promoCodeRedemptions()
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    public function payments() // Relationship to payments table
    {
        return $this->hasMany(Payment::class);
    }


    // --- Helper Methods ---

    /**
     * Check if the team currently has active access (paid or promo).
     */
    public function hasActiveAccessOld(): bool
    {
        // Check status first
        if (!in_array($this->access_status, ['paid_active', 'promo_active'])) {
            return false;
        }

        // Check expiry (if it exists)
        if ($this->access_expires_at && $this->access_expires_at->isPast()) {
            // Optional: Add logic here to automatically set status to 'inactive' if expired
            // $this->access_status = 'inactive';
            // $this->saveQuietly(); // Avoid triggering events if needed
            return false;
        }

        // Status is active and not expired (or expiry is null)
        return true;
    }

    /**
     * NEW METHOD: Check if the team's access has specifically expired.
     * This assumes that if access_status is 'paid_active' or 'promo_active'
     * but access_expires_at is in the past, then it's an "expired" state.
     */
    public function hasAccessExpiredOld(): bool
    {
        // It can only be considered "expired" if it once had an active status
        // and has an expiry date that is now in the past.
        if (in_array($this->access_status, ['paid_active', 'promo_active']) &&
            $this->access_expires_at &&
            $this->access_expires_at->isPast()) {
            return true;
        }
        return false;
    }

    /**
     * Grant access via Promo Code using duration from settings.
     * @return Carbon The calculated expiry date.
     */
    public function grantPromoAccessOld(): Carbon
    {
        $settings = Settings::instance();
        $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;

        $this->access_status = 'promo_active';
        $this->access_expires_at = Carbon::now()->addDays($durationDays);
        $this->save();
        return $this->access_expires_at; // Return the expiry date
    }

    /**
     * Grant access via Payment using duration from settings.
     * @return Carbon The calculated expiry date.
     */
    public function grantPaidAccessOld(): Carbon // Removed $expiresAt parameter
    {
        $settings = Settings::instance();
        $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;

        $this->access_status = 'paid_active';
        $this->access_expires_at = Carbon::now()->addDays($durationDays);
        $this->save();
        return $this->access_expires_at; // Return the expiry date
    }

    /**
     * Helper to check if the team's owner has an active subscription.
     */
    public function organizationHasActiveSubscription(): bool
    {
        $this->loadMissing('organization'); // Ensure organization is loaded
        return $this->organization?->hasActiveSubscription() ?? false;
    }
}
