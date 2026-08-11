<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\JoinRideGroupRequest;
use App\Http\Requests\StoreRideGroupRequest;
use App\Http\Resources\RideGroupResource;
use App\Models\RideGroup;
use App\Models\RideRequest;
use App\Services\RideGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RideGroupController extends Controller
{
    public function __construct(private readonly RideGroupService $groups) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $groups = $request->user()->activeGroups()
            ->with(['zone', 'activeMembers.user'])
            ->latest('id')
            ->get();

        return RideGroupResource::collection($groups);
    }

    /**
     * Open a ride from one of your own requests and host it.
     */
    public function store(StoreRideGroupRequest $request): JsonResponse
    {
        $rideRequest = $this->ownedRequest($request, $request->integer('ride_request_id'));

        $group = $this->groups->createFromRequest(
            $rideRequest,
            $request->has('max_seats') ? $request->integer('max_seats') : null,
            $request->input('meeting_point'),
        );

        return (new RideGroupResource($group))->response()->setStatusCode(201);
    }

    public function show(RideGroup $rideGroup): RideGroupResource
    {
        return new RideGroupResource($rideGroup->load(['zone', 'activeMembers.user']));
    }

    /**
     * Join ride: take a seat using one of your open requests.
     */
    public function join(JoinRideGroupRequest $request, RideGroup $rideGroup): JsonResponse
    {
        $rideRequest = $this->ownedRequest($request, $request->integer('ride_request_id'));

        $this->groups->join($rideGroup, $rideRequest);

        return response()->json([
            'message' => 'You are on this ride.',
            'group' => new RideGroupResource($rideGroup->refresh()->load(['zone', 'activeMembers.user'])),
        ]);
    }

    /**
     * Join a ride found by browsing, without filling in a request first.
     *
     * Everything the matcher needs except party size and luggage is already on
     * the ride, so asking for it again is asking a traveller to describe a trip
     * the screen is currently showing them.
     */
    public function quickJoin(Request $request, RideGroup $rideGroup): JsonResponse
    {
        $validated = $request->validate([
            'seats' => ['sometimes', 'integer', 'min:1', 'max:'.config('hashbuddy.groups.absolute_max_seats')],
            'luggage_count' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'flight_number' => ['nullable', 'string', 'max:10'],
        ]);

        $this->groups->quickJoin(
            $rideGroup,
            $request->user(),
            $validated['seats'] ?? 1,
            $validated['luggage_count'] ?? 1,
            $validated['flight_number'] ?? null,
        );

        return response()->json([
            'message' => 'You are on this ride.',
            'group' => new RideGroupResource($rideGroup->refresh()->load(['zone', 'activeMembers.user'])),
        ]);
    }

    public function leave(Request $request, RideGroup $rideGroup): JsonResponse
    {
        $group = $this->groups->leave($rideGroup, $request->user());

        return response()->json([
            'message' => 'You have left this ride.',
            'group' => new RideGroupResource($group),
        ]);
    }

    private function ownedRequest(Request $request, int $rideRequestId): RideRequest
    {
        $rideRequest = RideRequest::with('user')->findOrFail($rideRequestId);

        abort_unless($rideRequest->user_id === $request->user()->id, 403, 'This ride request is not yours.');

        return $rideRequest;
    }
}
