<?php

namespace App\Services;

use App\Enums\Gender;
use App\Enums\GenderPolicy;
use App\Enums\MemberStatus;
use App\Models\RideGroup;
use App\Models\RideRequest;
use App\Support\MatchCandidate;
use Illuminate\Support\Collection;

/**
 * Finds travel companions for an open ride request.
 *
 * A match is deliberately coarse: same airport, same terminal, same destination
 * zone, and departure windows that overlap by at least a configured number of
 * minutes. Everything leaving BLR funnels down the same few arteries, so a zone
 * dropdown separates travellers about as well as a routing engine would at this
 * stage — and it costs nothing to run.
 */
class MatchingService
{
    public function __construct(private readonly FareEstimator $fares) {}

    /**
     * @return Collection<int, MatchCandidate>
     */
    public function findMatches(RideRequest $request): Collection
    {
        $request->loadMissing(['user', 'zone']);

        return $this->candidateGroups($request)
            ->merge($this->candidateTravellers($request))
            ->sortByDesc(fn (MatchCandidate $c) => $c->score)
            ->take(config('hashbuddy.matching.max_results'))
            ->values();
    }

    /**
     * Groups that already exist and still have a seat.
     *
     * @return Collection<int, MatchCandidate>
     */
    private function candidateGroups(RideRequest $request): Collection
    {
        return RideGroup::query()
            ->forming()
            ->where('airport_code', $request->airport_code)
            ->where('terminal', $request->terminal)
            ->where('zone_id', $request->zone_id)
            ->overlapping($request->window_start, $request->window_end)
            ->whereColumn('seats_taken', '<', 'max_seats')
            ->whereDoesntHave('members', fn ($q) => $q
                ->where('user_id', $request->user_id)
                ->where('status', MemberStatus::Joined))
            ->with(['zone', 'activeMembers.user', 'activeMembers.rideRequest'])
            ->get()
            ->filter(fn (RideGroup $group) => $this->groupAdmits($group, $request))
            ->map(fn (RideGroup $group) => $this->scoreGroup($group, $request))
            // toBase(): an empty Eloquent collection stays Eloquent even after
            // mapping, and merging into one keys its items by model id.
            ->toBase();
    }

    /**
     * Lone travellers not yet in any group — joining one of these means forming
     * a new pair.
     *
     * @return Collection<int, MatchCandidate>
     */
    private function candidateTravellers(RideRequest $request): Collection
    {
        return RideRequest::query()
            ->open()
            ->whereNull('ride_group_id')
            ->where('id', '!=', $request->id)
            ->where('user_id', '!=', $request->user_id)
            ->where('airport_code', $request->airport_code)
            ->where('terminal', $request->terminal)
            ->where('zone_id', $request->zone_id)
            ->overlapping($request->window_start, $request->window_end)
            ->with(['user', 'zone'])
            ->get()
            ->filter(fn (RideRequest $other) => $this->travellersCompatible($request, $other))
            ->map(fn (RideRequest $other) => $this->scoreTraveller($other, $request))
            ->toBase();
    }

    private function minOverlap(): int
    {
        return (int) config('hashbuddy.matching.min_overlap_minutes');
    }

    public function groupAdmits(RideGroup $group, RideRequest $request): bool
    {
        if ($group->seatsAvailable() < $request->seats) {
            return false;
        }

        if ($group->overlapMinutesWith($request->window_start, $request->window_end) < $this->minOverlap()) {
            return false;
        }

        return $this->genderCompatible($group->gender_policy, $request);
    }

    /**
     * Gender rules run in both directions: the group must admit the traveller,
     * and a traveller who asked for women-only must not be dropped into an
     * open group.
     */
    public function genderCompatible(GenderPolicy $policy, RideRequest $request): bool
    {
        $gender = $request->user->gender ?? Gender::Undisclosed;

        if (! $policy->admits($gender)) {
            return false;
        }

        return ! ($request->gender_preference === GenderPolicy::WomenOnly && $policy !== GenderPolicy::WomenOnly);
    }

    private function travellersCompatible(RideRequest $a, RideRequest $b): bool
    {
        if ($a->overlapMinutesWith($b->window_start, $b->window_end) < $this->minOverlap()) {
            return false;
        }

        $maxSeats = config('hashbuddy.groups.absolute_max_seats');
        if ($a->seats + $b->seats > $maxSeats) {
            return false;
        }

        // Whatever policy the pair would adopt has to satisfy both of them.
        $policy = ($a->gender_preference === GenderPolicy::WomenOnly || $b->gender_preference === GenderPolicy::WomenOnly)
            ? GenderPolicy::WomenOnly
            : GenderPolicy::Any;

        return $this->genderCompatible($policy, $a) && $this->genderCompatible($policy, $b);
    }

    private function scoreGroup(RideGroup $group, RideRequest $request): MatchCandidate
    {
        $overlap = $group->overlapMinutesWith($request->window_start, $request->window_end);
        $passengers = $group->seats_taken + $request->seats;
        $luggage = $group->luggage_total + $request->luggage_count;

        $ratings = $group->activeMembers
            ->map(fn ($m) => $this->ratingFactor($m->user->rating_avg, $m->user->rating_count));

        $sameFlight = $request->flight_number !== null && $group->activeMembers
            ->contains(fn ($m) => $m->rideRequest?->flight_number === $request->flight_number);

        return new MatchCandidate(
            type: MatchCandidate::TYPE_GROUP,
            score: $this->score($overlap, $passengers, $luggage, $ratings->avg() ?? 0.7, $sameFlight),
            overlapMinutes: $overlap,
            fare: $this->fares->estimate($group->zone, $passengers, $luggage),
            sameFlight: $sameFlight,
            group: $group,
        );
    }

    private function scoreTraveller(RideRequest $other, RideRequest $request): MatchCandidate
    {
        $overlap = $other->overlapMinutesWith($request->window_start, $request->window_end);
        $passengers = $other->seats + $request->seats;
        $luggage = $other->luggage_count + $request->luggage_count;

        $sameFlight = $request->flight_number !== null
            && $other->flight_number === $request->flight_number;

        return new MatchCandidate(
            type: MatchCandidate::TYPE_TRAVELLER,
            score: $this->score(
                $overlap,
                $passengers,
                $luggage,
                $this->ratingFactor($other->user->rating_avg, $other->user->rating_count),
                $sameFlight,
            ),
            overlapMinutes: $overlap,
            fare: $this->fares->estimate($request->zone, $passengers, $luggage),
            sameFlight: $sameFlight,
            rideRequest: $other,
        );
    }

    /**
     * A 0-100 ranking signal. Overlap dominates because it is the only factor
     * that decides whether the ride can physically happen.
     */
    private function score(int $overlap, int $passengers, int $luggage, float $ratingFactor, bool $sameFlight): int
    {
        $w = config('hashbuddy.matching.weights');
        $ideal = max(1, (int) config('hashbuddy.matching.ideal_overlap_minutes'));

        $overlapFactor = min($overlap / $ideal, 1.0);

        // A pair that still fits a sedan keeps the full saving; needing an SUV
        // is workable but meaningfully less rewarding per head.
        $fitsSedan = $passengers <= config('hashbuddy.vehicle.sedan_max_passengers')
            && $luggage <= config('hashbuddy.vehicle.sedan_max_luggage');

        $total = $overlapFactor * $w['overlap']
            + ($fitsSedan ? 1.0 : 0.5) * $w['luggage_fit']
            + $ratingFactor * $w['rating']
            + ($sameFlight ? 1.0 : 0.5) * $w['flight_match'];

        return (int) round(min(100, max(0, $total)));
    }

    /**
     * New travellers sit at a neutral 0.7 rather than zero — nobody should be
     * unmatchable purely for having no history yet.
     */
    private function ratingFactor(?float $avg, ?int $count): float
    {
        return ($count ?? 0) > 0 ? min(1.0, ($avg ?? 0) / 5) : 0.7;
    }
}
