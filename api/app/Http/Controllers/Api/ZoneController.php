<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ZoneResource;
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
}
