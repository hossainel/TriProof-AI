<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyCrosscheckRequest;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class VerificationController extends Controller
{
    public function onlineCrosscheck(VerifyCrosscheckRequest $request, VerificationService $service): JsonResponse
    {
        $data = $service->crossCheck($request->normalizedLogs(), $request->user());

        $message = $data['feedback_notifications'] === []
            ? 'Cloud cross-check completed.'
            : 'Cloud cross-check completed; immediate feedback notifications are attached.';

        return ApiResponse::success($data, $message);
    }
}
