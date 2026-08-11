<?php

namespace App\Http\Controllers\Api;

use App\Enums\RideRequestStatus;
use App\Exceptions\RideException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRideRequestRequest;
use App\Http\Resources\MatchResource;
use App\Http\Resources\RideGroupResource;
use App\Http\Resources\RideRequestResource;
use App\Models\RideRequest;
use App\Services\MatchingService;
use App\Services\RideGroupService;
use App\Support\MatchCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RideRequestController extends Controller
{
    public function __construct(
        private readonly MatchingService $matching,
        private readonly RideGroupService $groups,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = $request->user()->rideRequests()
            ->with(['zone', 'group.zone', 'group.activeMembers.user'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')),
                fn ($q) => $q->whereIn('status', [RideRequestStatus::Open, RideRequestStatus::Matched]),
            )
            ->latest('id')
            ->get();

        return RideRequestResource::collection($requests);
    }

    public function store(StoreRideRequestRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isBlocked()) {
            throw RideException::userBlocked();
        }

        $max = (int) config('hashbuddy.requests.max_open_per_user');
        if ($user->rideRequests()->where('status', RideRequestStatus::Open)->count() >= $max) {
            throw RideException::tooManyOpenRequests($max);
        }

        // Two identical open requests do nothing but clutter the home screen
        // and match the same rides twice. Overlapping windows are legitimate —
        // people really do keep their options open — so only an exact repeat of
        // the same trip is refused.
        $duplicate = $user->rideRequests()
            ->where('status', RideRequestStatus::Open)
            ->where('terminal', $request->validated('terminal'))
            ->where('zone_id', $request->validated('zone_id'))
            ->whereDate('window_start', $request->date('window_start'))
            ->whereTime('window_start', $request->date('window_start')->format('H:i:s'))
            ->exists();

        if ($duplicate) {
            throw RideException::duplicateRequest();
        }

        $rideRequest = $user->rideRequests()->create($request->validated());

        // ->response() rather than response()->json() so the payload keeps the
        // same "data" envelope every other single-resource endpoint uses.
        return (new RideRequestResource($rideRequest->load('zone')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, RideRequest $rideRequest): RideRequestResource
    {
        $this->authorizeOwner($request, $rideRequest);

        return new RideRequestResource(
            $rideRequest->load(['zone', 'group.zone', 'group.activeMembers.user'])
        );
    }

    public function destroy(Request $request, RideRequest $rideRequest): JsonResponse
    {
        $this->authorizeOwner($request, $rideRequest);

        if ($rideRequest->ride_group_id !== null) {
            return response()->json([
                'message' => 'Leave the ride before cancelling this request.',
                'error' => 'request_already_grouped',
            ], 409);
        }

        $rideRequest->forceFill([
            'status' => RideRequestStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        return response()->json(['message' => 'Ride request cancelled.']);
    }

    /**
     * Find mates: groups with a free seat, plus lone travellers heading the
     * same way at the same time.
     */
    public function matches(Request $request, RideRequest $rideRequest): AnonymousResourceCollection
    {
        $this->authorizeOwner($request, $rideRequest);

        if (! $rideRequest->isOpen()) {
            throw RideException::requestNotOpen();
        }

        return MatchResource::collection($this->matching->findMatches($rideRequest));
    }

    /**
     * One tap to get on a ride: take the best available seat, or open a group
     * so the travellers who match you can find you.
     */
    public function autoMatch(Request $request, RideRequest $rideRequest): JsonResponse
    {
        $this->authorizeOwner($request, $rideRequest);

        if (! $rideRequest->isOpen()) {
            throw RideException::requestNotOpen();
        }

        $best = $this->matching->findMatches($rideRequest)
            ->first(fn (MatchCandidate $c) => $c->isGroup());

        if ($best) {
            $this->groups->join($best->group, $rideRequest);

            return response()->json([
                'action' => 'joined',
                'group' => new RideGroupResource($best->group->refresh()->load(['zone', 'activeMembers.user'])),
            ]);
        }

        $group = $this->groups->createFromRequest($rideRequest);

        return response()->json([
            'action' => 'created',
            'group' => new RideGroupResource($group),
        ], 201);
    }

    private function authorizeOwner(Request $request, RideRequest $rideRequest): void
    {
        abort_unless($rideRequest->user_id === $request->user()->id, 403, 'This ride request is not yours.');
    }
}
