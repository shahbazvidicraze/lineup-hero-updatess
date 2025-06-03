<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCodeRedemption extends Model
{
    use HasFactory;

    // Disable timestamps for this model if created_at/updated_at are not needed
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'promo_code_id',
        'user_organization_access_code',
        'redeemed_at',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
    ];

    /**
     * Get the user who redeemed the code.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the promo code that was redeemed.
     */
    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }
}
