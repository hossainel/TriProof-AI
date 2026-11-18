<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiAnalyzeRequest;
use App\Services\Ai\AiClient;
use App\Services\Ai\AnomalyAnalysisService;
use App\Services\ReworkService;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AiController extends Controller
{
    public function analyze(
        AiAnalyzeRequest $request,
        AnomalyAnalysisService $analysis,
        ReworkService $rework,
        AiClient $ai,
        VerificationService $verifier,
    ): JsonResponse {
        $data = [];

        if (is_array($request->input('entries')) && $request->input('entries') !== []) {
            $data['rework'] = $rework->reconcile($request->user(), $request->input('entries'));
        }

        if (filled($request->input('image_base64'))) {
            if (! $ai->isConfigured()) {
                throw new BusinessRuleException(
                    'vision_ai_unavailable',
                    'Vision AI is not configured. Set AI_PROVIDER and AI_API_KEY to use image verification.',
                    503
                );
            }

            $base64 = (string) $request->input('image_base64');
            $mime = (string) $request->input('image_mime', 'image/jpeg');

            if (str_starts_with($base64, 'data:')) {
                [$meta, $base64] = explode(',', $base64, 2);
                if (preg_match('/data:([^;]+);base64/', $meta, $m)) {
                    $mime = $m[1];
                }
            }

            $extracted = $ai->extractSerialsFromImage($base64, $mime);
            $verification = null;

            if ($extracted['outer_serial'] !== '' && $extracted['inner_serial'] !== '') {
                $verification = $verifier->inspect($extracted['outer_serial'], $extracted['inner_serial']);
            }

            $data['vision'] = [
                'extraction' => $extracted,
                'verification' => $verification,
            ];
        }

        $analysisData = $analysis->analyze(
            $request->user(),
            $request->filled('po_id') ? (int) $request->input('po_id') : null,
            $request->filled('batch_id') ? strtoupper((string) $request->input('batch_id')) : null
        );

        $data = array_merge($data, $analysisData);

        return ApiResponse::success(
            $data,
            $analysisData['findings'] === 0
                ? 'Analysis completed; no new anomaly clusters crossed the reporting threshold.'
                : "Analysis completed with {$analysisData['findings']} anomaly cluster(s)."
        );
    }
}
