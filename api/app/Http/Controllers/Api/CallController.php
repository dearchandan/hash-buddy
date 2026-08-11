<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorisesRideMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartCallRequest;
use App\Http\Resources\CallSessionResource;
use App\Models\CallSession;
use App\Models\RideGroup;
use App\Services\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends Controller
{
    use AuthorisesRideMembership;

    public function __construct(private readonly CallService $calls) {}

    /**
     * ICE servers, including short-lived TURN credentials. Fetched immediately
     * before a call rather than cached in the app, because the credentials
     * expire and a stale set fails at the worst possible moment.
     */
    public function iceServers(): JsonResponse
    {
        return response()->json([
            'data' => [
                'ice_servers' => $this->calls->iceServers(),
                'poll_seconds' => (int) config('hashbuddy.calls.poll_seconds', 2),
                'ring_seconds' => (int) config('hashbuddy.calls.ring_seconds', 45),
            ],
        ]);
    }

    public function start(StartCallRequest $request, RideGroup $rideGroup): JsonResponse
    {
        $this->requireMembership($request, $rideGroup);

        $call = $this->calls->start(
            $rideGroup,
            $request->user(),
            $request->integer('callee_id'),
            $request->string('offer_sdp')->toString(),
        );

        return (new CallSessionResource($call))->response()->setStatusCode(201);
    }

    /**
     * Poll: what, if anything, is live for me on this ride.
     */
    public function current(Request $request, RideGroup $rideGroup): JsonResponse
    {
        $this->requireMembership($request, $rideGroup);

        $call = $this->calls->current($rideGroup, $request->user());

        return response()->json(['data' => $call ? new CallSessionResource($call) : null]);
    }

    public function show(Request $request, CallSession $call): CallSessionResource
    {
        abort_unless($call->involves($request->user()->id), 404);

        return new CallSessionResource($call);
    }

    public function accept(Request $request, CallSession $call): CallSessionResource
    {
        abort_unless($call->involves($request->user()->id), 404);

        $request->validate(['answer_sdp' => ['required', 'string', 'max:65535']]);

        return new CallSessionResource(
            $this->calls->accept($call, $request->user(), $request->string('answer_sdp')->toString()),
        );
    }

    public function decline(Request $request, CallSession $call): CallSessionResource
    {
        abort_unless($call->involves($request->user()->id), 404);

        return new CallSessionResource($this->calls->decline($call, $request->user()));
    }

    public function hangUp(Request $request, CallSession $call): CallSessionResource
    {
        abort_unless($call->involves($request->user()->id), 404);

        return new CallSessionResource($this->calls->hangUp($call, $request->user()));
    }
}
