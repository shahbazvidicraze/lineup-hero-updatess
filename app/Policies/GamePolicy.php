<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\Team; // <-- Import Team model
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GamePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models (list games for a specific team).
     * The user must own the team to view its games.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Team  $team The team for which games are being listed
     * @return bool
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->id === $team->user_id;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Game $game): bool
    {
        return $user->id === $game->team->user_id;
    }

    /**
     * Determine whether the user can create models.
     * The user must own the team to create a game for it.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->id === $team->user_id;
    }


    /**
     * Determine whether the user can get the data needed to generate a PDF for the game.
     */
    public function viewPdfData(User $user, Game $game): bool
    {
        if ($user->id !== $game->team->user_id) return false; // User must own team
        $game->loadMissing('team.organization'); // Load team and its organization
        return $game->team?->organization?->hasActiveSubscription() ?? false; // Check org's subscription
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Game $game): bool
    {
        return $user->id === $game->team->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Game $game): bool
    {
        return $user->id === $game->team->user_id;
    }
}