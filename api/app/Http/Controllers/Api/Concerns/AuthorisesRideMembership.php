<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\RideGroup;
use Illuminate\Http\Request;

trait AuthorisesRideMembership
{
    /**
     * Chat and calls are for people who share a ride, nobody else.
     *
     * 404 rather than 403 on purpose: a stranger probing group ids should not
     * be able to tell an existing ride they are barred from apart from one that
     * was never there.
     */
    protected function requireMembership(Request $request, RideGroup $group): void
    {
        abort_unless($group->hasMember($request->user()->id), 404);
    }
}
