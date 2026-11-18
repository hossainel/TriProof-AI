<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyCrosscheckRequest;
use App\Services\OwnershipTransferService;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function transferTicket(Request $request, OwnershipTransferService $service): JsonResponse
    {
        $data = $request->validate([
            'outer_serial' => ['required', 'string', 'max:128'],
            'inner_serial' => ['required', 'string', 'max:255'],
            'device_token' => ['required', 'string', 'max:128'],
        ]);

        return ApiResponse::success($service->issue($data['outer_serial'], $data['inner_serial'], $data['device_token']));
    }

    public function onlineCrosscheck(VerifyCrosscheckRequest $request, VerificationService $service): JsonResponse
    {
        $data = $service->crossCheck($request->normalizedLogs(), $request->user());

        $message = $data['feedback_notifications'] === []
            ? 'Cloud cross-check completed.'
            : 'Cloud cross-check completed; immediate feedback notifications are attached.';

        return ApiResponse::success($data, $message);
    }
}
