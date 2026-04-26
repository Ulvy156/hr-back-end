<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var User|null $user */
        $user = $request->user('api') ?? $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $requiredPermissions = Collection::make($permissions)
            ->flatMap(
                static fn (string $permission): array => preg_split('/[|,]/', $permission) ?: []
            )
            ->map(static fn (string $permission): string => trim($permission))
            ->filter()
            ->values();

        if ($requiredPermissions->isEmpty()) {
            return response()->json([
                'message' => 'Forbidden.',
            ], Response::HTTP_FORBIDDEN);
        }

        $hasRequiredPermission = $requiredPermissions
            ->contains(fn (string $permission): bool => $user->can($permission));

        if (! $hasRequiredPermission) {
            return response()->json([
                'message' => 'Forbidden.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
