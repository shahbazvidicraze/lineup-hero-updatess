<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCodeRedemption extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = [
        'user_id', // User who redeemed the code
        'promo_code_id',
        'organization_id', // Organization that was created/activated
        // 'user_organization_access_code', // This code is on the Organization model now
        'redeemed_at',
    ];
    protected $casts = [ 'redeemed_at' => 'datetime' ];

    public function user() { return $this->belongsTo(User::class); }
    public function promoCode() { return $this->belongsTo(PromoCode::class); }
    public function organization() { return $this->belongsTo(Organization::class); } // New relationship
}