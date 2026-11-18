<?php

namespace App\Services\Auth;

use App\Models\User;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class JwtService
{
    public function issue(User $user): array
    {
        $secret = $this->secret();
        $now = time();
        $ttl = max(5, (int) config('triproof.jwt.ttl_minutes', 720));
        $expiresAt = $now + ($ttl * 60);

        $claims = [
            'iss' => (string) config('triproof.jwt.issuer'),
            'aud' => (string) config('triproof.jwt.audience'),
            'sub' => (string) $user->id,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            'jti' => (string) Str::uuid(),
            'typ' => 'access',
            'role' => $user->role->value,
        ];

        $token = JWT::encode($claims, $secret, 'HS256');

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => gmdate('c', $expiresAt),
            'expires_in' => $ttl * 60,
            'claims' => $claims,
        ];
    }

    public function decode(string $token): array
    {
        try {
            JWT::$leeway = 30;
            $decoded = (array) JWT::decode($token, new Key($this->secret(), 'HS256'));
        } catch (ExpiredException) {
            throw new UnexpectedValueException('Token has expired.', 401);
        } catch (Throwable) {
            throw new UnexpectedValueException('Token is invalid.', 401);
        }

        if (($decoded['typ'] ?? null) !== 'access') {
            throw new UnexpectedValueException('Token type is invalid.', 401);
        }

        if (! hash_equals((string) config('triproof.jwt.issuer'), (string) ($decoded['iss'] ?? ''))) {
            throw new UnexpectedValueException('Token issuer is invalid.', 401);
        }

        $audience = $decoded['aud'] ?? '';
        $expectedAudience = (string) config('triproof.jwt.audience');
        if (is_array($audience) ? ! in_array($expectedAudience, $audience, true) : ! hash_equals($expectedAudience, (string) $audience)) {
            throw new UnexpectedValueException('Token audience is invalid.', 401);
        }

        if (empty($decoded['jti']) || ! is_string($decoded['jti'])) {
            throw new UnexpectedValueException('Token identifier is missing.', 401);
        }

        if (Cache::has($this->revocationKey($decoded['jti']))) {
            throw new UnexpectedValueException('Token has been revoked.', 401);
        }

        return $decoded;
    }

    public function revoke(array $claims): void
    {
        $jti = $claims['jti'] ?? null;
        $exp = (int) ($claims['exp'] ?? 0);

        if (! is_string($jti) || $exp <= time()) {
            return;
        }

        Cache::put($this->revocationKey($jti), true, $exp - time());
    }

    private function secret(): string
    {
        $secret = (string) config('triproof.jwt.secret');

        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT secret is missing or too short. Set JWT_SECRET (32+ characters) or run php artisan key:generate.');
        }

        return $secret;
    }

    private function revocationKey(string $jti): string
    {
        return 'jwt:revoked:'.hash('sha256', $jti);
    }
}
