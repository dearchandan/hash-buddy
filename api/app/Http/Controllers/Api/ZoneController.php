<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpenRideResource;
use App\Http\Resources\ZoneResource;
use App\Models\RideGroup;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ZoneController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $zones = Zone::query()
            ->active()
            ->when($request->filled('city'), fn ($q) => $q->where('city', $request->string('city')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ZoneResource::collection($zones);
    }

    /**
     * Areas with something to join, for the home screen.
     *
     * The point of this screen is that a traveller who has just landed should
     * not have to describe their trip before finding out whether anyone is
     * already going their way. So it answers one question — where are people
     * heading right now — and answers it in a single query.
     */
    public function areas(Request $request): AnonymousResourceCollection
    {
        $terminal = $request->filled('terminal') ? $request->string('terminal')->toString() : null;

        // Applied to the count, the aggregates and the filter alike. Narrowing
        // only one of them would show "Koramangala · 3 rides" and then a list
        // with one in it.
        $atTerminal = function ($query) use ($terminal) {
            if ($terminal !== null) {
                $query->where('terminal', $terminal);
            }
        };

        $zones = Zone::query()
            ->active()
            ->withCount(['openRides' => $atTerminal])
            // Sub-selects rather than a join: joining alongside withCount would
            // multiply the count by the number of rows matched.
            ->addSelect([
                'seats_available' => RideGroup::query()
                    ->selectRaw('coalesce(sum(max_seats - seats_taken), 0)')
                    ->whereColumn('ride_groups.zone_id', 'zones.id')
                    ->joinable()
                    ->tap($atTerminal),
                'next_departure' => RideGroup::query()
                    ->select('window_start')
                    ->whereColumn('ride_groups.zone_id', 'zones.id')
                    ->joinable()
                    ->tap($atTerminal)
                    ->orderBy('window_start')
                    ->limit(1),
            ])
            // whereHas, not having: withCount is a sub-select rather than a
            // grouped aggregate, so there is no group for HAVING to filter.
            ->whereHas('openRides', $atTerminal)
            // Busiest first: the area most likely to already have your ride in
            // it is the one worth putting under the traveller's thumb.
            ->orderByDesc('open_rides_count')
            ->orderBy('name')
            ->get();

        return ZoneResource::collection($zones);
    }

    /**
     * Every ride heading to this area that still has a seat.
     */
    public function openRides(Request $request, Zone $zone): AnonymousResourceCollection
    {
        $rides = $zone->openRides()
            ->with(['zone', 'activeMembers.user'])
            ->when(
                $request->filled('terminal'),
                fn ($q) => $q->where('terminal', $request->string('terminal')),
            )
            // Leaving soonest first — the one you have to decide about now.
            ->orderBy('window_start')
            ->get();

        return OpenRideResource::collection($rides);
    }
}
