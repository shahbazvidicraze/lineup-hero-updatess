<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute; // Import Attribute

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
//        'team_id',
        'stripe_payment_intent_id',
        'user_organization_access_code',
        'amount', // Stored in cents
        'currency',
        'status',
        'paid_at',

    ];

    protected $casts = [
        'amount' => 'decimal:2', // Keep as integer (cents)
        'paid_at' => 'datetime',
    ];

    /**
      * The accessors to append to the model's array form.
      * This ensures 'formatted_amount' is included in JSON responses.
      *
      * @var array
      */

    // --- Relationships ---
    public function user() { return $this->belongsTo(User::class); }
//    public function team() { return $this->belongsTo(Team::class); }


    protected function amount(): Attribute
    {
        return Attribute::make(
            get: function (int $valueFromDatabase) {
                // Ensure the result is a float with two decimal places
                return (float) number_format($valueFromDatabase / 100, 2, '.', '');
            }
        );
    }

    /**
     * Get the payment amount formatted as a decimal string (e.g., "5.00").
     * Access via $payment->formatted_amount
     */

}
