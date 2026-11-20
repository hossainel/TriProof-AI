<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class AuthenticateMcp
{
    public function __construct(
        private readonly JwtService $jwt,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('triproof.mcp.api_key');
        $suppliedKey = $request->header('X-TriProof-Api-Key');

        if (is_string($configuredKey) && $configuredKey !== ''
            && is_string($suppliedKey) && $suppliedKey !== ''
            && hash_equals($configuredKey, $suppliedKey)) {
            $user = $this->apiKeyUser();

            if (! $user) {
                return $this->forbidden('The configured MCP API key is not linked to an active account.');
            }

            $this->authenticate($request, $user);
            $request->attributes->set('mcp_auth', 'api_key');

            return $next($request);
        }

        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            throw new AuthenticationException('A Bearer API token or X-TriProof-Api-Key is required.');
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (UnexpectedValueException $exception) {
            throw new AuthenticationException($exception->getMessage());
        }

        $user = User::query()->find((int) ($claims['sub'] ?? 0));
        if (! $user) {
            throw new AuthenticationException('The account linked to this token no longer exists.');
        }

        if (($claims['role'] ?? null) !== $user->role->value) {
            throw new AuthenticationException('Your role changed. Please sign in again.');
        }

        $scopes = is_array($claims['scopes'] ?? null) ? $claims['scopes'] : [];
        if (! in_array((string) config('triproof.mcp.scope', 'mcp:read'), $scopes, true)) {
            return $this->forbidden('This token is missing the mcp:read scope.');
        }

        $this->authenticate($request, $user);
        $request->attributes->set('jwt_claims', $claims);
        $request->attributes->set('mcp_auth', 'jwt');

        return $next($request);
    }

    private function apiKeyUser(): ?User
    {
        $userId = config('triproof.mcp.api_key_user_id');

        if ($userId !== null && $userId !== '') {
            return User::query()->find((int) $userId);
        }

        return User::query()->where('role', UserRole::Admin)->first();
    }

    private function authenticate(Request $request, User $user): void
    {
        Auth::setUser($user);
        $request->setUserResolver(fn (): User => $user);

        if (in_array($user->language_pref, ['en', 'bn'], true)) {
            App::setLocale($user->language_pref);
        }
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'forbidden',
                'message' => $message,
            ],
        ], 403);
    }
}
