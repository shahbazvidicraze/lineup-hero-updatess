<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    use HasFactory;
    protected $fillable = [
        'team_id', 'opponent_name', 'game_date', 'innings',
        'location_type', 'lineup_data', 'submitted_at'
    ];

    protected $casts = [
        'game_date' => 'datetime',
        'submitted_at' => 'datetime',
        'lineup_data' => 'array', // Cast JSON text to array automatically
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    // Later: Relationship to detailed lineup positions if you create separate tables
    // public function lineupPositions() { ... }
}
