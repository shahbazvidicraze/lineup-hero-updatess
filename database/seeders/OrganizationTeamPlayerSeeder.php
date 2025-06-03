<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Position;
use App\Models\Team;
use App\Models\User;
use App\Models\Settings; // Import Settings model
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Carbon\Carbon; // Import Carbon

class OrganizationTeamPlayerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // --- Configurable Options ---
        $teamsPerUser = 3; // How many teams each selected user will own
        $playersPerTeam = 12;
        $gamesPerTeam = 3;
        $numberOfExistingUsersToProcess = 3; // How many existing users to create data for
        // --- End Options ---

        $this->command->info("Seeding data for existing Users, linking to a single main Organization...");

        // --- 1. Ensure Main Organization Exists ---
        $mainOrganization = Organization::first(); // Check if any organization exists
        if (!$mainOrganization) {
            // If no organization exists, create one.
            // The OrganizationFactory will generate an organization_code.
            $mainOrganization = Organization::factory()->create([
                'name' => 'Lineup Hero Central League', // Specific name for the main org
                'email' => 'league@lineupheroapp.com', // Example contact
            ]);
            $this->command->info("Created Main Organization: {$mainOrganization->name} (ID: {$mainOrganization->id}, Code: {$mainOrganization->organization_code})");
        } else {
            // If an organization exists, use the first one found as the main one.
            // Ensure it has an organization_code if that's important for your logic.
            if (empty($mainOrganization->organization_code)) {
                $mainOrganization->organization_code = strtoupper(\Illuminate\Support\Str::random(8));
                // Ensure uniqueness if generating
                while (Organization::where('organization_code', $mainOrganization->organization_code)->where('id', '!=', $mainOrganization->id)->exists()) {
                    $mainOrganization->organization_code = strtoupper(\Illuminate\Support\Str::random(8));
                }
                $mainOrganization->save();
                $this->command->info("Updated Main Organization '{$mainOrganization->name}' with Code: {$mainOrganization->organization_code}");
            } else {
                $this->command->info("Using existing Main Organization: {$mainOrganization->name} (ID: {$mainOrganization->id}, Code: {$mainOrganization->organization_code})");
            }
        }


        // --- 2. Fetch Positions ---
        $positions = Position::all();
        $preferablePositions = $positions->whereNotIn('name', ['OUT', 'BENCH'])->pluck('id')->toArray(); // Exclude BENCH too
        if (empty($preferablePositions)) {
            $this->command->error('No preferable positions found. Ensure PositionSeeder ran first.');
            return;
        }
        $this->command->info('Fetched Positions for preference setting.');

        // --- 3. Fetch Existing Users & Grant Test Subscriptions ---
        $existingUsers = User::orderBy('id', 'asc')->take($numberOfExistingUsersToProcess)->get();
        if ($existingUsers->isEmpty()) {
            $this->command->warn('No existing users found to seed data for. Ensure users are seeded first by DatabaseSeeder.');
            return;
        }
        $this->command->info("Found {$existingUsers->count()} existing users to process.");

        $settings = Settings::instance(); // Get global settings for access duration
        $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;

        foreach ($existingUsers as $currentUser) {
            $this->command->info("--- Processing for User: {$currentUser->email} (ID: {$currentUser->id}) ---");

            // Grant a test subscription to each user processed by this seeder
            if (!$currentUser->hasActiveSubscription()) {
                $expiryDate = Carbon::now()->addDays($durationDays);
                $currentUser->grantSubscriptionAccess($expiryDate); // This generates the org access code
                $this->command->info("  User ID {$currentUser->id} granted test subscription. Access Code: {$currentUser->organization_access_code}. Expires: {$expiryDate->toDateString()}");
            } else {
                $this->command->info("  User ID {$currentUser->id} already has an active subscription. Access Code: {$currentUser->organization_access_code}. Expires: {$currentUser->subscription_expires_at?->toDateString()}");
            }

            // Create Teams for this User, all linked to the Main Organization
            $teams = Team::factory()
                ->count($teamsPerUser)
                ->for($currentUser)         // Set user_id
                ->for($mainOrganization)    // Set organization_id to the main org
                ->create();
            $this->command->info("    - Created {$teamsPerUser} Teams for User ID {$currentUser->id}, linked to Main Org ID {$mainOrganization->id}.");

            foreach ($teams as $team) {
                $this->command->info("      - Processing Team: {$team->name} (ID: {$team->id})");
                $players = Player::factory()->count($playersPerTeam)->state(['team_id' => $team->id])->create();
                $this->command->info("        - Created {$playersPerTeam} Players.");

                // Set Preferences for each Player
                foreach ($players as $player) {
                    $numPreferred = rand(1, 3); $numRestricted = rand(0, 2);
                    $numPreferred = min($numPreferred, count($preferablePositions));
                    $numRestricted = min($numRestricted, count($preferablePositions) - $numPreferred);
                    $prefsToSync = [];

                    if (count($preferablePositions) >= $numPreferred) {
                        $preferredIds = ($numPreferred > 0) ? Arr::random($preferablePositions, $numPreferred) : [];
                        $preferredIds = is_array($preferredIds) ? $preferredIds : [$preferredIds];
                        foreach ($preferredIds as $id) { $prefsToSync[$id] = ['preference_type' => 'preferred']; }

                        $remainingPositions = array_diff($preferablePositions, $preferredIds);
                        if (count($remainingPositions) >= $numRestricted && $numRestricted > 0) {
                            $restrictedIds = Arr::random($remainingPositions, $numRestricted);
                            $restrictedIds = is_array($restrictedIds) ? $restrictedIds : [$restrictedIds];
                            foreach ($restrictedIds as $id) { $prefsToSync[$id] = ['preference_type' => 'restricted']; }
                        }

                        if (!empty($prefsToSync)) {
                            $player->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')
                                ->withPivot('preference_type')->sync($prefsToSync);
                        } else {
                            $player->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')->detach();
                        }
                    }
                }
                $this->command->info("        - Set random Player Preferences.");

                // Create Games for this Team
                Game::factory()->count($gamesPerTeam)->for($team)->create();
                $this->command->info("        - Created {$gamesPerTeam} Games.");
            } // End team loop
        } // End user loop

        $this->command->info('Database seeding for existing users completed!');
    }
}