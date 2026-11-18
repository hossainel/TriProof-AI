<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Ai\AnomalyAnalysisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiController extends Controller
{
    public function analyze(Request $request, AnomalyAnalysisService $service): RedirectResponse
    {
        $data = $request->validate([
            'po_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'batch_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,15}$/'],
        ]);

        try {
            $result = $service->analyze(
                $request->user(),
                isset($data['po_id']) ? (int) $data['po_id'] : null,
                isset($data['batch_id']) ? strtoupper($data['batch_id']) : null
            );
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['ai' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Web AI analysis failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['ai' => __('triproof.ai.analysis_failed')]);
        }

        $result['reports'] = array_slice($result['reports'], 0, 40);

        return back()
            ->with('success', __('triproof.flash.analyzed', ['count' => $result['findings']]))
            ->with('ai_result', $result);
    }
}
