<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Cookie as CookieAlias;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->respondWithRefreshTokenCookie(
            $this->authService->login($request->validated())
        );
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->respondWithRefreshTokenCookie(
            $this->authService->refreshToken($request->cookie($this->refreshTokenCookieName()))
        );
    }

    public function logout(Request $request): Response
    {
        $this->authService->logout($request->user('api'));

        return response()
            ->noContent()
            ->withCookie($this->expiredRefreshTokenCookie());
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(
            $this->authService->me($request->user('api'))
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        return response()->json(
            $this->authService->changePassword($request->user('api'), $request->validated())
        );
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        return response()->json(
            $this->authService->forgotPassword($validated['email'])
        );
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'new_password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        return response()->json(
            $this->authService->resetPassword($user, $validated['new_password'])
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respondWithRefreshTokenCookie(array $payload): JsonResponse
    {
        $refreshToken = $payload['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new HttpException(500, 'Refresh token was not issued.');
        }

        return response()
            ->json(Arr::except($payload, ['refresh_token']))
            ->withCookie($this->refreshTokenCookie($refreshToken));
    }

    private function refreshTokenCookie(string $refreshToken): CookieAlias
    {
        return new CookieAlias(
            name: $this->refreshTokenCookieName(),
            value: $refreshToken,
            expire: now()->addMinutes($this->refreshTokenCookieLifetimeMinutes()),
            path: $this->refreshTokenCookiePath(),
            domain: $this->refreshTokenCookieDomain(),
            secure: $this->refreshTokenCookieSecure(),
            httpOnly: true,
            raw: false,
            sameSite: $this->refreshTokenCookieSameSite(),
        );
    }

    private function expiredRefreshTokenCookie(): CookieAlias
    {
        return cookie()->forget(
            $this->refreshTokenCookieName(),
            $this->refreshTokenCookiePath(),
            $this->refreshTokenCookieDomain()
        );
    }

    private function refreshTokenCookieName(): string
    {
        return (string) config('services.passport.refresh_cookie_name', 'refresh_token');
    }

    private function refreshTokenCookieLifetimeMinutes(): int
    {
        return max((int) config('services.passport.refresh_cookie_lifetime', 43200), 1);
    }

    private function refreshTokenCookiePath(): string
    {
        return (string) config('services.passport.refresh_cookie_path', '/');
    }

    private function refreshTokenCookieDomain(): ?string
    {
        $domain = config('services.passport.refresh_cookie_domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    private function refreshTokenCookieSecure(): bool
    {
        return (bool) config('services.passport.refresh_cookie_secure', false);
    }

    private function refreshTokenCookieSameSite(): ?string
    {
        $sameSite = config('services.passport.refresh_cookie_same_site', 'lax');

        return is_string($sameSite) && $sameSite !== '' ? $sameSite : null;
    }
}
