<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\OfflineBlacklistService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const DUMMY_HASH = '$2y$12$vmuZPj/Tl0rE9VbVd2NtyevI1Tx05EzDiaGtfzPVY/wZtXUFYGOke';

    public function login(LoginRequest $request, JwtService $jwt, OfflineBlacklistService $blacklist): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');
        $user = User::query()->where('email', $email)->first();

        $valid = Hash::check($password, $user?->getAuthPassword() ?? self::DUMMY_HASH) && $user !== null;

        if (! $valid) {
            throw ValidationException::withMessages([
                'email' => ['The provided email or password is incorrect.'],
            ]);
        }

        $token = $jwt->issue($user);
        $blacklistPackage = $blacklist->build();
        $publicKey = (string) config('triproof.crypto.public_key');
        $decodedPublic = base64_decode($publicKey, true);

        unset($token['claims']);

        return ApiResponse::success([
            'token' => $token['token'],
            'token_type' => $token['token_type'],
            'expires_in' => $token['expires_in'],
            'expires_at' => $token['expires_at'],
            'user' => $this->userPayload($user),
            'verification' => [
                'algorithm' => 'Ed25519',
                'public_key_base64' => $publicKey !== '' ? $publicKey : null,
                'key_fingerprint' => $decodedPublic ? hash('sha256', $decodedPublic) : null,
                'configured' => $decodedPublic !== false && $decodedPublic !== null && strlen((string) $decodedPublic) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            ],
            'offline_blacklist' => $blacklistPackage,
            'api_version' => 'v1',
        ], 'Authenticated.');
    }

    public function logout(Request $request, JwtService $jwt): JsonResponse
    {
        $claims = (array) $request->attributes->get('jwt_claims', []);
        $jwt->revoke($claims);

        return ApiResponse::success(null, 'Token revoked. Discard it from the device.');
    }

    public function me(Request $request): JsonResponse
    {
        $publicKey = (string) config('triproof.crypto.public_key');
        $decoded = base64_decode($publicKey, true);

        return ApiResponse::success([
            'user' => $this->userPayload($request->user()),
            'verification' => [
                'algorithm' => 'Ed25519',
                'public_key_base64' => $publicKey !== '' ? $publicKey : null,
                'key_fingerprint' => $decoded ? hash('sha256', $decoded) : null,
            ],
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'language_pref' => $user->language_pref,
            'factory_id' => $user->factory_id,
        ];
    }
}
