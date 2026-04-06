<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\IndexPositionRequest;
use App\Http\Resources\PositionResource;
use App\Services\Employee\PositionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PositionController extends Controller
{
    public function __construct(
        private PositionService $positionService,
    ) {}

    public function index(IndexPositionRequest $request): AnonymousResourceCollection
    {
        return PositionResource::collection(
            $this->positionService->list($request->validated())
        );
    }
}
