<?php

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.passport.refresh_cookie_name', 'refresh_token');
    config()->set('services.passport.refresh_cookie_lifetime', 43200);
    config()->set('services.passport.refresh_cookie_path', '/');
    config()->set('services.passport.refresh_cookie_domain', null);
    config()->set('services.passport.refresh_cookie_secure', false);
    config()->set('services.passport.refresh_cookie_same_site', 'lax');
});

it('stores the refresh token in an http only cookie on login', function () {
    $this->mock(AuthService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('login')
            ->once()
            ->with([
                'email' => 'admin@example.com',
                'password' => 'password',
            ])
            ->andReturn([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'user' => [
                    'id' => 1,
                    'email' => 'admin@example.com',
                ],
            ]);
    });

    $response = $this->postJson('/api/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('access_token', 'access-token')
        ->assertJsonMissingPath('refresh_token');

    $refreshCookie = refreshTokenCookieFromResponse($response);

    expect($refreshCookie)->not->toBeNull()
        ->and($refreshCookie?->getValue())->toBe('refresh-token')
        ->and($refreshCookie?->isHttpOnly())->toBeTrue()
        ->and($refreshCookie?->isSecure())->toBeFalse()
        ->and($refreshCookie?->getSameSite())->toBe('lax');
});

it('refreshes the access token using the refresh token cookie', function () {
    $this->mock(AuthService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('refreshToken')
            ->once()
            ->with('refresh-token-from-cookie')
            ->andReturn([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
            ]);
    });

    $response = $this->call(
        'POST',
        '/api/auth/refresh',
        [],
        ['refresh_token' => 'refresh-token-from-cookie'],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $response
        ->assertOk()
        ->assertJsonPath('access_token', 'new-access-token')
        ->assertJsonMissingPath('refresh_token');

    expect(refreshTokenCookieFromResponse($response)?->getValue())
        ->toBe('new-refresh-token');
});

it('clears the refresh token cookie on logout', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->mock(AuthService::class, function (MockInterface $mock) use ($user): void {
        $mock->shouldReceive('logout')
            ->once()
            ->withArgs(function (?User $authenticatedUser) use ($user): bool {
                return $authenticatedUser instanceof User && $authenticatedUser->is($user);
            });
    });

    $response = $this
        ->withUnencryptedCookies(['refresh_token' => 'refresh-token-from-cookie'])
        ->postJson('/api/auth/logout');

    $response->assertNoContent();

    expect(refreshTokenCookieFromResponse($response)?->getExpiresTime())
        ->toBeLessThan(time());
});

function refreshTokenCookieFromResponse(\Illuminate\Testing\TestResponse $response): ?Cookie
{
    /** @var list<Cookie> $cookies */
    $cookies = $response->baseResponse->headers->getCookies();

    foreach ($cookies as $cookie) {
        if ($cookie->getName() === 'refresh_token') {
            return $cookie;
        }
    }

    return null;
}
