<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SerialRegistry;
use App\Services\Crypto\SerialCryptoService;
use App\Services\DppService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PublicPassportController extends Controller
{
    public function __invoke(string $payload, Request $request, SerialCryptoService $crypto, DppService $dpp): View
    {
        $outer = $crypto->normalizePayload($payload);
        $serial = SerialRegistry::query()->where('outer_serial', $outer)->with('po.buyer')->first();
        $parsed = $crypto->parseOuterSerial($outer);

        return view('passport.show', [
            'outer' => $outer,
            'serial' => $serial,
            'parsed' => $parsed,
            'scorecard' => $serial?->po && $serial->po->dpp_metadata
                ? $dpp->scorecard($serial->po->dpp_metadata)
                : null,
            'dppDigest' => $serial?->po?->dpp_digest,
            'appScheme' => 'triproof://verify?outer='.rawurlencode($outer),
        ]);
    }
}
