<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\AiResponseException;
use App\Exceptions\AiUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiClient;
use App\Services\VerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VerifierController extends Controller
{
    public function index(Request $request): View
    {
        return view('verify.index', [
            'result' => session('verify_result'),
            'outer' => (string) session('verify_outer', ''),
            'inner' => (string) session('verify_inner', ''),
            'extraction' => null,
        ]);
    }

    public function verify(Request $request, VerificationService $service, AiClient $ai): View|RedirectResponse
    {
        $data = $request->validate([
            'outer_serial' => ['nullable', 'string', 'max:128'],
            'inner_serial' => ['nullable', 'string', 'max:512'],
            'label_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $outer = trim((string) ($data['outer_serial'] ?? ''));
        $inner = trim((string) ($data['inner_serial'] ?? ''));
        $extraction = null;

        if ($request->hasFile('label_image')) {
            if (! $ai->isConfigured()) {
                return back()
                    ->withErrors(['label_image' => __('triproof.verify.ocr_unavailable')])
                    ->withInput();
            }

            $file = $request->file('label_image');
            $base64 = base64_encode((string) $file->get());

            try {
                $extraction = $ai->extractSerialsFromImage($base64, (string) ($file->getMimeType() ?: 'image/jpeg'));
            } catch (AiUnavailableException|AiResponseException $e) {
                return back()
                    ->withErrors(['label_image' => $e->getMessage()])
                    ->withInput();
            }

            if ($outer === '' && $extraction['outer_serial'] !== '') {
                $outer = $extraction['outer_serial'];
            }
            if ($inner === '' && $extraction['inner_serial'] !== '') {
                $inner = $extraction['inner_serial'];
            }
        }

        if ($outer === '' || $inner === '') {
            return back()
                ->withErrors(['verify' => __('triproof.verify.both_required')])
                ->withInput();
        }

        $result = $service->inspect($outer, $inner);

        return view('verify.index', [
            'result' => $result,
            'outer' => $outer,
            'inner' => $inner,
            'extraction' => $extraction,
        ]);
    }
}
