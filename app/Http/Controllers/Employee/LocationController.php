<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\IndexLocationRequest;
use App\Http\Resources\LocationResource;
use App\Services\Employee\LocationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function __construct(
        private LocationService $locationService,
    ) {}

    public function provinces(IndexLocationRequest $request): AnonymousResourceCollection
    {
        return LocationResource::collection(
            $this->locationService->provinces($request->validated())
        );
    }

    public function districts(IndexLocationRequest $request): AnonymousResourceCollection
    {
        return LocationResource::collection(
            $this->locationService->districts($request->validated())
        );
    }

    public function communes(IndexLocationRequest $request): AnonymousResourceCollection
    {
        return LocationResource::collection(
            $this->locationService->communes($request->validated())
        );
    }

    public function villages(IndexLocationRequest $request): AnonymousResourceCollection
    {
        return LocationResource::collection(
            $this->locationService->villages($request->validated())
        );
    }
}
