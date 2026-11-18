<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecallTriggerRequest;
use App\Services\RecallService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RecallController extends Controller
{
    public function trigger(RecallTriggerRequest $request, RecallService $service): JsonResponse
    {
        $data = $service->trigger($request->user(), $request->validated());

        return ApiResponse::success(
            $data,
            "Precision recall applied to {$data['affected_units']} unit(s); {$data['devices_notified']} device(s) notified."
        );
    }
}
