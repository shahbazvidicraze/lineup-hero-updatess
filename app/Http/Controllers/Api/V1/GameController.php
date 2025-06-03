<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Game;
use App\Models\Team;
use App\Models\Player;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response as HttpResponse;

class GameController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests;

    /**
     * Display a listing of games for a specific team.
     * Route: GET /teams/{team}/games
     */
    public function index(Request $request, Team $team)
    {
        try {
            $this->authorize('viewAny', [Game::class, $team]); // Policy: Can user view games for this team?
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('You do not have permission to view games for this team.');
        }

        $games = $team->games()
            ->orderBy('game_date', 'desc')
            ->get(['id', 'team_id', 'opponent_name', 'game_date', 'innings', 'location_type', 'submitted_at']);
        return $this->successResponse($games, 'Games retrieved successfully.');
    }

    /**
     * Store a newly created game for a specific team.
     * Route: POST /teams/{team}/games
     */
    public function store(Request $request, Team $team)
    {
        try {
            $this->authorize('create', [Game::class, $team]); // Policy: Can user create game for this team?
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('You do not have permission to create games for this team.');
        }

        $validator = Validator::make($request->all(), [
            'opponent_name' => 'nullable|string|max:255',
            'game_date' => 'required|date',
            'innings' => 'required|integer|min:1|max:25', // Max 25 innings
            'location_type' => ['required', Rule::in(['home', 'away'])],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $validatedData = $validator->validated();
        $validatedData['lineup_data'] = (object) []; // Initialize empty lineup
        $game = $team->games()->create($validatedData);
        $game->load('team:id,name'); // Load basic team info for context
        return $this->successResponse($game, 'Game created successfully.', HttpResponse::HTTP_CREATED);
    }

    /**
     * Display the specified game.
     * Route: GET /games/{game}
     */
    public function show(Request $request, Game $game)
    {
        try {
            $this->authorize('view', $game); // Policy: Can user view this specific game?
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('You do not have permission to view this game.');
        }

        $game->load(['team:id,name', 'team.players' => function($q){
            $q->select(['id', 'team_id', 'first_name', 'last_name', 'jersey_number', 'email']);
            // Stats and full_name will be appended by Player model accessor
        }]);
        return $this->successResponse($game);
    }

    /**
     * Update the specified game details (not the lineup itself).
     * Route: PUT /games/{game}
     */
    public function update(Request $request, Game $game)
    {
        try {
            $this->authorize('update', $game); // Policy: Can user update this game?
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('You do not have permission to update this game.');
        }

        $validator = Validator::make($request->all(), [
            'opponent_name' => 'sometimes|required|string|max:255',
            'game_date' => 'sometimes|required|date',
            'innings' => 'sometimes|required|integer|min:1|max:25',
            'location_type' => ['sometimes','required', Rule::in(['home', 'away'])],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $game->update($validator->validated());
        $game->load('team:id,name');
        return $this->successResponse($game, 'Game updated successfully.');
    }

    /**
     * Remove the specified game from storage.
     * Route: DELETE /games/{game}
     */
    public function destroy(Request $request, Game $game)
    {
        try {
            $this->authorize('delete', $game); // Policy: Can user delete this game?
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('You do not have permission to delete this game.');
        }

        $game->delete();
        return $this->deletedResponse('Game deleted successfully.'); // Uses 200 OK with message
    }

    /**
     * Get the current lineup structure for a game (for lineup builder UI).
     * Route: GET /games/{game}/lineup
     */
    public function getLineup(Request $request, Game $game)
    {
        try {
            $this->authorize('view', $game); // Or a more specific 'viewLineup' ability
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('Cannot view this game lineup.');
        }

        $game->load([
            'team.players' => function ($query) { // Fetch players of the game's team
                $query->select(['id','team_id','first_name','last_name','jersey_number','email'])
                    ->with(['preferredPositions:id,name,display_name', 'restrictedPositions:id,name,display_name']);
                // Stats and full_name are appended by Player model accessor
            }
        ]);

        $responseData = [
            'game_id' => $game->id,
            'innings' => $game->innings,
            'players' => $game->team->players, // Includes preferences, stats, full_name
            'lineup' => $game->lineup_data ?? (object)[], // Current saved lineup or empty object
            'submitted_at' => $game->submitted_at?->toISOString(),
        ];
        return $this->successResponse($responseData, 'Lineup data retrieved.');
    }

    /**
     * Save/Update the full lineup data for a game.
     * Route: PUT /games/{game}/lineup
     */
    public function updateLineup(Request $request, Game $game)
    {
        try {
            $this->authorize('update', $game); // Or 'updateLineup' ability
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('Cannot update this game lineup.');
        }

        $validator = Validator::make($request->all(), [
            'lineup' => 'required|array',
            'lineup.*.player_id' => ['required', Rule::exists('players', 'id')->where('team_id', $game->team_id)],
            'lineup.*.batting_order' => 'nullable|integer|min:0',
            'lineup.*.innings' => 'required|array',
            'lineup.*.innings.*' => 'nullable|string|exists:positions,name',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $lineupData = $request->input('lineup');

        // Business Logic: Duplicate Position Check per Inning
        $inningsCount = $game->innings;
        for ($i = 1; $i <= $inningsCount; $i++) {
            $inningPositions = [];
            foreach ($lineupData as $playerLineup) {
                $inningStr = (string)$i;
                if (isset($playerLineup['innings'][$inningStr])) {
                    $position = $playerLineup['innings'][$inningStr];
                    if (!empty($position) && is_string($position) && strtoupper($position) !== 'OUT' && strtoupper($position) !== 'BENCH') {
                        $upperPos = strtoupper($position);
                        if (isset($inningPositions[$upperPos])) {
                            return $this->errorResponse("Duplicate position '{$position}' found in inning {$i}.", HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
                        }
                        $inningPositions[$upperPos] = true;
                    }
                }
            }
        }

        $game->lineup_data = $lineupData;
        $game->submitted_at = now();
        $game->save();

        return $this->successResponse(
            ['lineup' => $game->lineup_data, 'submitted_at' => $game->submitted_at->toISOString()],
            'Lineup updated successfully.'
        );
    }

    /**
     * Trigger auto-complete, get positional assignments, assign batting order.
     * Route: POST /games/{game}/autocomplete-lineup
     */
    public function autocompleteLineup(Request $request, Game $game)
    {
        try {
            $this->authorize('update', $game); // Or 'optimizeLineup' ability
        } catch (AuthorizationException $e) {
            return $this->forbiddenResponse('Cannot optimize lineup for this game.');
        }

        $validator = Validator::make($request->all(), [
            'fixed_assignments' => 'present|array',
            'fixed_assignments.*' => 'sometimes|array',
            'fixed_assignments.*.*' => 'sometimes|string|exists:positions,name',
            'players_in_game' => 'required|array|min:1',
            'players_in_game.*' => ['integer', Rule::exists('players', 'id')->where('team_id', $game->team_id)],
        ]);
        if ($validator->fails()) { return $this->validationErrorResponse($validator); }

        $fixedAssignmentsInput = $request->input('fixed_assignments', []);
        $playersInGameIds = $request->input('players_in_game');

        try {
            $playersForPayload = Player::with(['preferredPositions:id,name', 'restrictedPositions:id,name'])
                ->whereIn('id', $playersInGameIds)->get();
            $actualCounts = []; $playerPreferences = [];
            foreach ($playersForPayload as $player) {
                $stats = $player->stats;
                $actualCounts[(string)$player->id] = $stats['position_counts'] ?? (object)[];
                $playerPreferences[(string)$player->id] = [
                    'preferred' => $player->preferredPositions->pluck('name')->toArray(),
                    'restricted' => $player->restrictedPositions->pluck('name')->toArray(),
                ];
            }
            $finalFixedAssignments = empty($fixedAssignmentsInput) ? (object)[] : $fixedAssignmentsInput;
            $pythonPayload = [ /* ... as before ... */ ]; // Construct payload

            $settings = Settings::instance();
            $optimizerUrl = $settings->optimizer_service_url;
            $optimizerTimeout = config('services.lineup_optimizer.timeout', 60);
            if (!$optimizerUrl) { throw new \Exception('Optimizer service URL not configured.'); }

            Log::info("Sending payload to optimizer: ", ['game_id' => $game->id]);
            $response = Http::timeout($optimizerTimeout)->acceptJson()->post($optimizerUrl, $pythonPayload);

            if ($response->successful()) {
                $positionalLineupData = $response->json();
                if (!is_array($positionalLineupData)) { throw new \Exception('Optimizer returned invalid data format.'); }

                $finalLineupWithBattingOrder = []; $battingSlot = 1;
                $positionAssignmentsMap = collect($positionalLineupData)->keyBy(fn($item) => (string)($item['player_id']??null));
                foreach ($playersInGameIds as $playerId) {
                    $playerIdStr = (string)$playerId;
                    if ($positionAssignmentsMap->has($playerIdStr)) {
                        $playerAssignment = $positionAssignmentsMap->get($playerIdStr);
                        $playerAssignment['innings'] = isset($playerAssignment['innings']) && is_array($playerAssignment['innings']) ? (object)$playerAssignment['innings'] : (object)[];
                        $playerAssignment['batting_order'] = $battingSlot++;
                        $finalLineupWithBattingOrder[] = $playerAssignment;
                    } else { /* ... handle player not in optimizer output ... */ }
                }
                $game->lineup_data = $finalLineupWithBattingOrder;
                $game->submitted_at = now();
                $game->save();
                return $this->successResponse(['lineup' => $game->lineup_data], 'Lineup optimized and saved successfully.');
            } else { /* ... handle optimizer service failure ... */ }
        } catch (\Illuminate\Http\Client\RequestException $e) { /* ... handle connection error ... */ }
        catch (\Exception $e) { /* ... handle general error ... */ }
    }

    /**
     * Provide JSON data for client-side PDF generation.
     * Access controlled by GamePolicy@viewPdfData (checks user's subscription).
     * Route: GET /games/{game}/pdf-data
     */
    public function getLineupPdfData(Request $request, Game $game)
    {
        try {
            $this->authorize('viewPdfData', $game);
        } catch (AuthorizationException $e) {
            $user = $request->user(); // Get authenticated user
            if ($user) { // Check if user is authenticated
                $game->loadMissing('team.user'); // Ensure team and its owner are loaded
                if ($game->team && $game->team->user_id === $user->id) { // User owns the team
                    if (!$user->hasActiveSubscription()) { // Check user's subscription
                        if ($user->subscription_expires_at && $user->subscription_expires_at->isPast()) {
                            return $this->forbiddenResponse('Your account subscription has expired. Please renew to generate PDF lineups.');
                        }
                        return $this->forbiddenResponse('Access Denied. Your account does not have an active subscription.');
                    }
                }
            }
            // Default forbidden if ownership or other policy reasons
            return $this->forbiddenResponse('Access Denied. You may not have permission or an active subscription.');
        }

        $lineupArray = is_object($game->lineup_data) ? json_decode(json_encode($game->lineup_data), true) : $game->lineup_data;
        if (empty($lineupArray) || !is_array($lineupArray)) {
            return $this->notFoundResponse('No valid lineup data for this game to generate PDF.');
        }

        $playerIdsInLineup = collect($lineupArray)->pluck('player_id')->filter()->unique()->toArray();
        $playersList = [];
        if (!empty($playerIdsInLineup)) {
            $playersList = Player::whereIn('id', $playerIdsInLineup)
                ->select(['id', 'first_name', 'last_name', 'jersey_number'])
                ->get()
                ->map(fn ($p) => ['id'=> (string)$p->id, 'full_name'=>$p->full_name, 'jersey_number'=>$p->jersey_number])
                ->values()->all();
        }

        $game->loadMissing('team:id,name');
        $gameDetails = [
            'id' => $game->id, 'team_name' => $game->team?->name ?? 'N/A',
            'opponent_name' => $game->opponent_name ?? 'N/A',
            'game_date' => $game->game_date?->toISOString(),
            'innings_count' => $game->innings, 'location_type' => $game->location_type
        ];
        $responseData = [
            'game_details'       => $gameDetails,
            'players_info'       => $playersList,
            'lineup_assignments' => $lineupArray
        ];
        return $this->successResponse($responseData, 'PDF data retrieved successfully.');
    }
}