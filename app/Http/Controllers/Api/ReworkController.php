<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReworkReconcileRequest;
use App\Services\ReworkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReworkController extends Controller
{
    public function reconcile(ReworkReconcileRequest $request, ReworkService $service): JsonResponse
    {
        $data = $service->reconcile($request->user(), $request->input('entries'));

        return ApiResponse::success(
            $data,
            "Rework reconciliation complete: {$data['counts']['linked']} linked, {$data['counts']['gaps']} gap(s)."
        );
    }
}
