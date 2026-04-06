<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\IndexEmployeeRequest;
use App\Http\Requests\Employee\RestoreEmployeeRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\TerminateEmployeeRequest;
use App\Http\Requests\Employee\UnterminateEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Employee\UploadEmployeeProfilePhotoRequest;
use App\Http\Resources\EmployeeIndexResource;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use App\Services\Employee\EmployeeExportService;
use App\Services\Employee\EmployeeProfilePhotoService;
use App\Services\Employee\EmployeeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
        private EmployeeExportService $employeeExportService,
        private EmployeeProfilePhotoService $employeeProfilePhotoService,
    ) {}

    public function index(IndexEmployeeRequest $request): AnonymousResourceCollection
    {
        return EmployeeIndexResource::collection(
            $this->employeeService->paginate($request->validated())
        );
    }

    public function exportExcel(IndexEmployeeRequest $request): BinaryFileResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        $export = $this->employeeExportService->exportExcel($actor, $request->validated());

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => $export['content_type']]
        )->deleteFileAfterSend(true);
    }

    public function show(Request $request, int $id): EmployeeResource
    {
        /** @var User|null $authenticatedUser */
        $authenticatedUser = $request->user();

        if ($authenticatedUser === null) {
            throw new AuthorizationException('Unauthenticated.');
        }

        return new EmployeeResource(
            $this->employeeService->findAccessible(
                $id,
                $authenticatedUser,
                $this->requestedIncludes($request),
            )
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return (new EmployeeResource(
            $this->employeeService->create($request->validated(), $actor)
        ))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmployeeRequest $request, int $id): EmployeeResource
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return new EmployeeResource(
            $this->employeeService->update($id, $request->validated(), $actor)
        );
    }

    public function destroy(Request $request, int $id): Response
    {
        /** @var User|null $actor */
        $actor = $request->user();

        $this->employeeService->delete($id, $actor);

        return response()->noContent();
    }

    public function restore(RestoreEmployeeRequest $request, int $id): EmployeeResource
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return new EmployeeResource(
            $this->employeeService->restore($id, $actor)
        );
    }

    public function activate(Request $request, int $id): EmployeeResource
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return new EmployeeResource(
            $this->employeeService->activate($id, $actor)
        );
    }

    public function deactivate(Request $request, int $id): EmployeeResource
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return new EmployeeResource(
            $this->employeeService->deactivate($id, $actor)
        );
    }

    public function terminate(TerminateEmployeeRequest $request, int $id): EmployeeResource
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return new EmployeeResource(
            $this->employeeService->terminate($id, $request->validated(), $actor)
        );
    }

    public function unterminate(UnterminateEmployeeRequest $request, int $id): EmployeeResource
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return new EmployeeResource(
            $this->employeeService->unterminate($id, $actor)
        );
    }

    public function getByManager(int $managerId): AnonymousResourceCollection
    {
        return EmployeeIndexResource::collection(
            $this->employeeService->getByManager($managerId)
        );
    }

    public function uploadProfilePhoto(UploadEmployeeProfilePhotoRequest $request, int $id): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
            'employee' => (new EmployeeResource(
                $this->employeeProfilePhotoService->upload(
                    $id,
                    $request->file('profile_photo'),
                    $actor,
                )
            ))->resolve($request),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function requestedIncludes(Request $request): array
    {
        $includes = $request->query('include', []);

        if (is_string($includes)) {
            $includes = array_filter(array_map('trim', explode(',', $includes)));
        }

        if (! is_array($includes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $include): string => is_string($include) ? trim($include) : '',
            $includes,
        )));
    }
}
