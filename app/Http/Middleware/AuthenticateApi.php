<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class AuthenticateApi
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            throw new AuthenticationException('A Bearer API token is required.');
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (UnexpectedValueException $e) {
            throw new AuthenticationException($e->getMessage());
        }

        $user = User::query()->find((int) ($claims['sub'] ?? 0));

        if (! $user) {
            throw new AuthenticationException('The account linked to this token no longer exists.');
        }

        if (($claims['role'] ?? null) !== $user->role->value) {
            throw new AuthenticationException('Your role changed. Please sign in again.');
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('jwt_claims', $claims);

        if (in_array($user->language_pref, ['en', 'bn'], true)) {
            App::setLocale($user->language_pref);
        }

        return $next($request);
    }
}
