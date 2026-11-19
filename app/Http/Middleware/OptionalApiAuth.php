<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class OptionalApiAuth
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $next($request);
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (UnexpectedValueException) {
            return $next($request);
        }

        $user = User::query()->find((int) ($claims['sub'] ?? 0));
        if (! $user || ($claims['role'] ?? null) !== $user->role->value) {
            return $next($request);
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
