<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CartonAggregationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartonController extends Controller
{
    public function aggregate(Request $request, CartonAggregationService $service): JsonResponse
    {
        $data = $request->validate([
            'po_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'factory_id' => ['nullable', 'string', 'max:32'],
            'batch_id' => ['required', 'string', 'max:32'],
            'device_token' => ['nullable', 'string', 'max:128'],
            'outer_serial' => ['required', 'array', 'min:2', 'max:100'],
            'outer_serial.*' => ['required', 'string', 'max:128'],
        ]);

        return ApiResponse::success($service->aggregate($request->user(), $data), 'Master carton sealed.');
    }

    public function verify(string $cartonSerial, CartonAggregationService $service): JsonResponse
    {
        if (! preg_match('/^CTN-[A-Z0-9-]{6,60}$/', $cartonSerial)) {
            return ApiResponse::error('invalid_carton_serial', 'Invalid carton serial.', 422);
        }

        return ApiResponse::success($service->verify($cartonSerial));
    }
}
