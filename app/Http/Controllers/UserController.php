<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\IndexUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function __construct(private UserService $userService) {}

    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        return UserResource::collection(
            $this->userService->paginateUsers(
                $validated,
                (int) ($validated['per_page'] ?? 15)
            )
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($request->validated());

        return response()->json(UserResource::make($user), Response::HTTP_CREATED);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(
            UserResource::make($this->userService->getUser($user))
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        return response()->json(
            UserResource::make($this->userService->updateUser($user, $request->validated()))
        );
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => $this->userService->listRoles()
                ->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function destroy(User $user): Response
    {
        $this->userService->deleteUser($user);

        return response()->noContent();
    }
}
