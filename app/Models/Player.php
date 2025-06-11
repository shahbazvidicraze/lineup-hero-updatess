<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Log;

class Player extends Model
{
    use HasFactory;
    protected $fillable = ['team_id', 'first_name', 'last_name', 'jersey_number', 'email', 'phone'];
    protected ?array $calculatedStatsCache = null;
    protected $appends = ['stats', 'full_name']; // Add full_name if you want it directly

    // --- Relationships ---
    public function team() { return $this->belongsTo(Team::class); }
    public function positionPreferences() { return $this->hasMany(PlayerPositionPreference::class); }
    public function preferredPositions() {
        return $this->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')
            ->wherePivot('preference_type', 'preferred');
    }
    public function restrictedPositions() {
        return $this->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')
            ->wherePivot('preference_type', 'restricted');
    }

    // --- Stats Calculation Logic ---
    public function calculateHistoricalStats(): array
    {
        if ($this->calculatedStatsCache !== null) return $this->calculatedStatsCache;
        $this->loadMissing('team');
        if (!$this->team) {
            Log::warning("Player ID {$this->id} missing team for stats.");
            return $this->cacheStatsResult([
                'pct_innings_played' => null, 'top_position' => null, 'avg_batting_loc' => null,
                'position_counts' => (object) [], 'total_innings_participated_in' => 0,
                'active_innings_played' => 0, 'pct_inf_played' => null, 'pct_of_played' => null,
            ]);
        }

        // Fetch INF/OF position names once
        static $infPositions = null, $ofPositions = null;
        if ($infPositions === null) {
            $allPositions = Position::select('name', 'category')->get()->keyBy('name');
            $infPositions = $allPositions->where('category', 'INF')->keys()->map(fn($name) => strtoupper($name))->all();
            $ofPositions = $allPositions->where('category', 'OF')->keys()->map(fn($name) => strtoupper($name))->all();
        }

        $submittedGames = $this->team->games()
            ->whereNotNull('submitted_at')->whereNotNull('lineup_data')
            ->where(fn($q) => $q->where('lineup_data', '!=', '[]')->where('lineup_data', '!=', '{}'))
            ->get(['id', 'innings', 'lineup_data']);

        $totalGameInningsAvailable = 0;
        $playerActiveInnings = 0;
        $positionCounts = [];
        $battingLocations = [];
        $infInningsPlayed = 0;
        $ofInningsPlayed = 0;

        if ($submittedGames->isEmpty()) {
            return $this->cacheStatsResult([
                'pct_innings_played' => 0.0, 'top_position' => null, 'avg_batting_loc' => null,
                'position_counts' => (object) [], 'total_innings_participated_in' => 0,
                'active_innings_played' => 0, 'pct_inf_played' => 0.0, 'pct_of_played' => 0.0,
            ]);
        }

        foreach ($submittedGames as $game) {
            try {
                $lineupData = $game->lineup_data;
                if (empty($lineupData)) continue;
                $lineupCollection = collect(is_object($lineupData) ? json_decode(json_encode($lineupData), true) : $lineupData);
                $playerLineupEntry = $lineupCollection->firstWhere('player_id', $this->id);

                if ($playerLineupEntry) {
                    $totalGameInningsAvailable += $game->innings;
                    if (isset($playerLineupEntry['innings']) && (is_array($playerLineupEntry['innings']) || is_object($playerLineupEntry['innings']))) {
                        $inningsArray = (array) $playerLineupEntry['innings'];
                        foreach ($inningsArray as $position) { // Key (inning num) not needed here
                            if (!empty($position) && is_string($position)) {
                                $upperPos = strtoupper($position);
                                if ($upperPos !== 'OUT' && $upperPos !== 'BENCH') {
                                    $playerActiveInnings++;
                                    $positionCounts[$position] = ($positionCounts[$position] ?? 0) + 1;
                                    if (in_array($upperPos, $infPositions)) $infInningsPlayed++;
                                    if (in_array($upperPos, $ofPositions)) $ofInningsPlayed++;
                                }
                            }
                        }
                    }
                    if (isset($playerLineupEntry['batting_order']) && is_numeric($playerLineupEntry['batting_order'])) {
                        $battingLocations[] = (int) $playerLineupEntry['batting_order'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Stats Calc Err: Game {$game->id}, Player {$this->id}: " . $e->getMessage());
                continue;
            }
        }

        $pctInningsPlayed = ($totalGameInningsAvailable > 0) ? round(($playerActiveInnings / $totalGameInningsAvailable) * 100, 1) : 0.0;
        $topPosition = null;
        if (!empty($positionCounts)) {
            $maxCount = 0;
            foreach ($positionCounts as $pos => $count) { if ($count > $maxCount) { $maxCount = $count; $topPosition = $pos; }}
        }
        $avgBattingLoc = !empty($battingLocations) ? (int) round(array_sum($battingLocations) / count($battingLocations)) : null;
        $pctInfPlayed = ($playerActiveInnings > 0) ? round(($infInningsPlayed / $playerActiveInnings) * 100, 1) : 0.0;
        $pctOfPlayed = ($playerActiveInnings > 0) ? round(($ofInningsPlayed / $playerActiveInnings) * 100, 1) : 0.0;
        $finalPositionCounts = !empty($positionCounts) ? $positionCounts : (object) [];

        $result = [
            'pct_innings_played' => $pctInningsPlayed,
            'top_position' => $topPosition,
            'avg_batting_loc' => $avgBattingLoc,
            'position_counts' => $finalPositionCounts, // For optimizer
            'total_innings_participated_in' => $totalGameInningsAvailable, // For Flutter 'total innings'
            'active_innings_played' => $playerActiveInnings, // For context or client-side calcs
            'pct_inf_played' => $pctInfPlayed, // For Flutter '%inf'
            'pct_of_played' => $pctOfPlayed,   // For potential Flutter '%of'
        ];
        return $this->cacheStatsResult($result);
    }

    private function cacheStatsResult(array $result): array
    {
        $this->calculatedStatsCache = $result;
        return $result;
    }

    protected function stats(): Attribute
    {
        return Attribute::make(get: fn () => $this->calculateHistoricalStats());
    }

    protected function fullName(): Attribute // Example accessor for player_name
    {
        return Attribute::make(
            get: fn ($value, $attributes) => ($attributes['first_name'] ?? '') . ' ' . ($attributes['last_name'] ?? '')
        );
    }
}