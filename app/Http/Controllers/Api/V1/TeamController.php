<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;

class TeamController extends Controller
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    public function index(Request $request)
    {
        $user = $request->user();
        $teams = $user->teams()
            ->with('organization') // Eager load organization if needed
            ->orderBy('id', 'desc')
            ->get();
        // Return paginated data using the trait
        // $teams = $query->paginate($request->input('per_page', 15));
        // return $this->successResponse($teams, 'Teams retrieved successfully.');

        if ($teams->isNotEmpty()) {
            return $this->successResponse($teams, 'Teams retrieved successfully.');
        } else {
            return $this->successResponse([], 'No teams created yet.'); // Return empty array
        }
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sport_type' => ['required', Rule::in(['baseball', 'softball'])],
            'team_type' => ['required', Rule::in(['travel', 'recreation', 'school'])],
            'age_group' => 'required|string|max:50',
            'season' => 'nullable|string|max:50',
            'year' => 'nullable|integer|digits:4',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'organization_code' => [ // User MUST provide code of an active org
                'required', 'string', 'max:50',
                Rule::exists('organizations', 'organization_code')
            ],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $validatedData = $validator->validated();
        $organization = Organization::where('organization_code', strtoupper($validatedData['organization_code']))->first(); // firstOrFail() not needed due to exists rule

        if (!$organization) { // Should be caught by 'exists' but defensive check
            return $this->errorResponse('Invalid organization code provided.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$organization->hasActiveSubscription()) {
            return $this->forbiddenResponse('The specified organization ('.$organization->name.') does not have an active subscription. Please have the organization admin renew it.');
        }

        $validatedData['organization_id'] = $organization->id;
        unset($validatedData['organization_code']);

        $team = $user->teams()->create($validatedData); // User still "owns" the team for management
        $team->load('organization:id,name,organization_code');
        return $this->successResponse($team, 'Team created successfully under organization: ' . $organization->name, Response::HTTP_CREATED);
    }

    public function show(Request $request, Team $team)
    {
        if ($request->user()->id !== $team->user_id) return $this->forbiddenResponse('You do not own this team.');
        $team->load(['organization:id,name', 'games', 'players' => function($q){
            $q->select(['id', 'team_id', 'first_name', 'last_name', 'jersey_number', 'email']); // Select specific player columns
        }]);
        return $this->successResponse($team);
    }

    public function update(Request $request, Team $team)
    {
        if ($request->user()->id !== $team->user_id) return $this->forbiddenResponse('You do not own this team.');
        $validator = Validator::make($request->all(), [ /* same as store but 'sometimes|required' */
            'name' => 'sometimes|required|string|max:255',
            'sport_type' => ['sometimes','required', Rule::in(['baseball', 'softball'])],
            'team_type' => ['sometimes','required', Rule::in(['travel', 'recreation', 'school'])],
            'age_group' => 'sometimes|required|string|max:50',
            'season' => 'nullable|string|max:50', 'year' => 'nullable|integer|digits:4',
            'city' => 'nullable|string|max:100', 'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100', 'organization_id' => 'nullable|exists:organizations,id',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $team->update($validator->validated());
        $team->load('organization:id,name');
        return $this->successResponse($team, 'Team updated successfully.');
    }

    public function destroy(Request $request, Team $team)
    {
        if ($request->user()->id !== $team->user_id) return $this->forbiddenResponse('You do not own this team.');
        $team->delete();
        return $this->successResponse(null, 'Team deleted successfully.', Response::HTTP_OK, false);
    }

    public function listPlayers(Request $request, Team $team)
    {
        if ($request->user()->id !== $team->user_id && !$request->user('api_admin')) {
            return $this->forbiddenResponse('Cannot access this team\'s players.');
        }
        $players = $team->players()->select(['id','team_id','first_name','last_name','jersey_number','email', 'phone'])->orderBy('last_name')->orderBy('first_name')->get();
        // $players = $team->players()->select([...])->orderBy(...)->paginate(50);
        // return $this->successResponse($players, 'Players retrieved successfully.');

        if ($players->isNotEmpty()) {
            return $this->successResponse($players, 'Players retrieved successfully.');
        }
        return $this->successResponse([], 'No players found for this team.');
    }
}
