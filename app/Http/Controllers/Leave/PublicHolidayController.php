<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\IndexPublicHolidayRequest;
use App\Http\Resources\PublicHolidayResource;
use App\Services\PublicHoliday\PublicHolidayService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicHolidayController extends Controller
{
    public function __construct(
        private PublicHolidayService $publicHolidayService,
    ) {}

    public function index(IndexPublicHolidayRequest $request): AnonymousResourceCollection
    {
        return PublicHolidayResource::collection(
            $this->publicHolidayService->listActive($request->validated())
        );
    }
}
