<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyCrosscheckRequest;
use App\Models\User;
use App\Services\OwnershipTransferService;
use App\Services\VerificationService;
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

        return response()->json([
            'success' => true,
            'server_time' => now()->toIso8601String(),
            'data' => $service->issue($data['outer_serial'], $data['inner_serial'], $data['device_token']),
        ]);
    }

    public function onlineCrosscheck(VerifyCrosscheckRequest $request, VerificationService $service): JsonResponse
    {
        $actor = $request->user() ?: new User(['role' => UserRole::GlobalBuyer]);
        $data = $service->crossCheck($request->normalizedLogs(), $actor);
        $serverTime = now()->toIso8601String();

        $mobileResults = array_map(function (array $result) {
            $verdict = in_array($result['verdict'], ['owner_repeat_ok', 'store_grace_ok'], true)
                ? 'verified_clean'
                : $result['verdict'];

            return [
                'sync_uuid' => $result['sync_uuid'],
                'verdict' => $verdict,
                'title_en' => $verdict === 'clone_detected' ? 'Counterfeit Clone Detected' :
                    ($verdict === 'recalled_batch' ? 'Precision Batch Recall' : 'Cloud Verification Complete'),
                'title_bn' => $verdict === 'clone_detected' ? 'ক্লোন পণ্য শনাক্ত' :
                    ($verdict === 'recalled_batch' ? 'নির্দিষ্ট ব্যাচ রিকল' : 'ক্লাউড যাচাই সম্পন্ন'),
                'message_en' => $result['message_en'] ?? '',
                'message_bn' => $result['message_bn'] ?? '',
                'outer_serial' => $result['outer_serial'] ?? null,
            ];
        }, $data['results']);

        return response()->json([
            'success' => true,
            'server_time' => $serverTime,
            'results' => $mobileResults,
            'feedback_notifications' => $data['feedback_notifications'],
            'data' => $data,
        ]);
    }
}
