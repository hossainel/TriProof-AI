<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OfflineBlacklistService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class OfflineBlacklistController extends Controller
{
    public function __invoke(OfflineBlacklistService $blacklist): JsonResponse
    {
        return ApiResponse::success($blacklist->build());
    }
}
