<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Passport\Token;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthService
{
    /**
     * @param array{email: string, password: string} $credentials
     * @return array<string, mixed>
     */
    public function login(array $credentials): array
    {
        $user = User::query()
            ->with('employee')
            ->where('email', $credentials['email'])
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw new UnauthorizedHttpException('Bearer', 'The provided credentials are incorrect.');
        }

        $tokenPayload = $this->issueToken([
            'grant_type' => 'password',
            'client_id' => $this->passwordClientId(),
            'client_secret' => $this->passwordClientSecret(),
            'username' => $credentials['email'],
            'password' => $credentials['password'],
            'scope' => '',
        ]);

        return [
            ...$tokenPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken(?string $refreshToken): array
    {
        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new UnauthorizedHttpException('Bearer', 'Refresh token is missing.');
        }

        return $this->issueToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->passwordClientId(),
            'client_secret' => $this->passwordClientSecret(),
            'scope' => '',
        ]);
    }

    public function logout(?User $user): void
    {
        if ($user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        $token = $user->token();

        if ($token instanceof Token) {
            $token->refreshToken?->revoke();
            $token->revoke();
        }
    }

    public function me(?User $user): User
    {
        if ($user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        return $user->load(['employee', 'roles']);
    }

    /**
     * @param array{current_password: string, new_password: string, new_password_confirmation: string} $data
     * @return array<string, string>
     */
    public function changePassword(?User $user, array $data): array
    {
        if ($user === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        if (! Hash::check($data['current_password'], $user->password)) {
            throw new HttpException(422, 'The current password is incorrect.');
        }

        $user->forceFill([
            'password' => Hash::make($data['new_password']),
        ])->save();

        return [
            'message' => 'Password changed successfully.',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function forgotPassword(string $email): array
    {
        $user = User::query()->where('email', $email)->first();

        $token = null;

        if ($user !== null) {
            $token = Password::broker()->createToken($user);
        }

        return [
            'message' => 'If the account exists, a password reset token has been generated.',
            'reset_token' => app()->isProduction() ? null : ($token ?? Str::random(64)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function resetPassword(User $user, string $newPassword): array
    {
        DB::transaction(function () use ($user, $newPassword): void {
            $user->forceFill([
                'password' => Hash::make($newPassword),
            ])->save();

            $user->tokens()->each(function (Token $token): void {
                $token->refreshToken?->revoke();
                $token->revoke();
            });
        });

        return [
            'message' => 'Password reset successfully.',
        ];
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function issueToken(array $payload): array
    {
        $response = app()->handle(
            Request::create('/oauth/token', 'POST', $payload)
        );

        /** @var array<string, mixed>|null $data */
        $data = json_decode($response->getContent(), true);

        if ($response->isSuccessful() && is_array($data)) {
            return $data;
        }

        $error = is_array($data) ? ($data['error'] ?? null) : null;
        $message = is_array($data)
            ? ($data['error_description'] ?? $data['message'] ?? 'Authentication request failed.')
            : 'Authentication request failed.';

        if ($error === 'invalid_grant') {
            throw new UnauthorizedHttpException('Bearer', $message);
        }

        throw new HttpException($response->getStatusCode() ?: 500, $message);
    }

    private function passwordClientId(): string
    {
        $clientId = config('services.passport.password_client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw new HttpException(500, 'Passport password client ID is not configured.');
        }

        return $clientId;
    }

    private function passwordClientSecret(): string
    {
        $clientSecret = config('services.passport.password_client_secret');

        if (! is_string($clientSecret) || $clientSecret === '') {
            throw new HttpException(500, 'Passport password client secret is not configured.');
        }

        return $clientSecret;
    }
}
