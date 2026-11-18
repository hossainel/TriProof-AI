<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PackBatchRequest;
use App\Services\PackingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PackingController extends Controller
{
    public function pack(PackBatchRequest $request, PackingService $service): JsonResponse
    {
        $data = $service->pack($request->user(), $request->validated());

        return ApiResponse::success($data, "{$data['packed_count']} unit(s) marked as packed.");
    }
}
