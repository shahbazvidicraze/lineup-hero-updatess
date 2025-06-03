<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable; // Use Authenticatable if login needed
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

// Make Authenticatable if Orgs need direct login
class Organization extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'organization_code', 'email', 'password']; // Add fillable fields
    protected $hidden = ['password', 'remember_token']; // Hide if login needed

    protected function casts(): array {
        return [
            'password' => 'hashed', // Cast if login needed
        ];
    }

    // JWTSubject Methods (Implement fully if login needed)
    public function getJWTIdentifier() { return $this->getKey(); }
    public function getJWTCustomClaims() {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email, // Include if exists/needed
            'type' => 'organization', // Identify type in token
        ];
     }

    // Define Relationships
    public function teams()
    {
        return $this->hasMany(Team::class);
    }
}
