<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(?array $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $payload['data'] = $data;

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(
            ['success' => false, 'error' => $error],
            $status,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
