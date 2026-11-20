<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BatchExportService;
use App\Services\Crypto\SerialCryptoService;
use App\Services\VerificationService;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\QRCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class QrStickerTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    public function test_inline_sticker_pdf_is_authorized_bounded_and_locally_scannable(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(6);
        $this->generateSerials($order, $buyer, 5);
        $expected = $order->serials()->orderBy('unit_number')->limit(2)->get();

        $path = route('orders.qr-stickers', ['po' => $order, 'limit' => 2]);

        $this->get($path)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->factory('DHK05')->create())->getJson($path)->assertForbidden();
        $this->actingAs(User::factory()->buyer()->create())->getJson($path)->assertForbidden();
        $this->actingAs($factory)->get($path)->assertOk();

        $response = $this->actingAs($buyer)->get($path);
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF-', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $this->assertStringStartsWith('inline; ', $response->headers->get('Content-Disposition') ?? '');
        $this->assertStringContainsString('triproof-qr-stickers-', $response->headers->get('Content-Disposition') ?? '');

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);

        // Decode the four locally generated PNGs and render the exact view data used by the PDF.
        // No shell tools or network services are required, so this works on any CI runner.
        $data = app(BatchExportService::class)->qrStickerViewData($order, 2);
        $read = [];
        foreach ($data['serials'] as $sticker) {
            foreach (['outer_qr', 'inner_qr'] as $key) {
                $uri = $sticker[$key];
                $this->assertStringStartsWith('data:image/png;base64,', $uri);
                $png = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
                $this->assertNotFalse($png);
                $read[] = (new QRCode)->readFromBlob($png)->data;
            }
        }

        $this->assertCount(4, $read);
        foreach ($expected as $serial) {
            $this->assertContains(rtrim((string) config('app.url'), '/').'/v/'.$serial->outer_serial, $read);
            $this->assertContains($serial->inner_serial, $read);
        }

        $html = view('exports.qr-labels-pdf', array_merge(['order' => $order], $data))->render();

        $printable = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ([
            'O1', 'I1', '1 - OUTER SERIAL', '1 - INNER SERIAL', 'PAIR 1: O1 <-> I1',
            'O2', 'I2', '2 - OUTER SERIAL', '2 - INNER SERIAL', 'PAIR 2: O2 <-> I2',
            'Apply inside sealed package',
        ] as $needle) {
            $this->assertStringContainsString($needle, $printable);
        }
        $this->assertSame(0, preg_match('/[\x{0980}-\x{09FF}]/u', $html));
    }

    public function test_qr_smart_url_and_prefixed_inner_payload_verify_in_web_verifier(): void
    {
        [$order, $buyer] = $this->makeOrder(6);
        $this->generateSerials($order, $buyer, 1, 'M01', 'B01');
        $serial = $order->serials()->firstOrFail();

        $outerUrl = rtrim((string) config('app.url'), '/').'/v/'.$serial->outer_serial;
        $result = app(VerificationService::class)->inspect($outerUrl, 'TP1:'.$serial->inner_serial);

        $this->assertSame($serial->outer_serial, $result['outer_serial']);
        $this->assertTrue($result['signature_valid']);
        $this->assertTrue($result['registered']);
        $this->assertTrue($result['hash_matches']);
        $this->assertTrue($result['authentic']);
        $this->assertSame('verified_clean', $result['verdict']);
    }

    public function test_exact_qr_payloads_verify_through_web_and_api(): void
    {
        [$order, $buyer] = $this->makeOrder(6);
        $this->generateSerials($order, $buyer, 1, 'M01', 'B01');
        $serial = $order->serials()->firstOrFail();
        $sticker = app(BatchExportService::class)->qrStickerViewData($order, 1)['serials'][0];

        $decode = function (string $dataUri): string {
            $png = base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
            $this->assertNotFalse($png);

            return (new QRCode)->readFromBlob($png)->data;
        };

        $outerQr = $decode($sticker['outer_qr']);
        $innerQr = $decode($sticker['inner_qr']);

        $this->assertSame(rtrim((string) config('app.url'), '/').'/v/'.$serial->outer_serial, $outerQr);
        $this->assertSame($serial->inner_serial, $innerQr);

        $encodedOuterQr = str_replace('|', '%7C', $outerQr);
        $wrappedInnerQr = 'TP1:'.$innerQr;

        $web = $this->actingAs($buyer)->post(route('verify.check'), [
            'outer_serial' => $encodedOuterQr,
            'inner_serial' => $wrappedInnerQr,
        ]);

        $web->assertOk()->assertViewHas('result', function (array $result): bool {
            return $result['verdict'] === 'verified_clean'
                && $result['authentic'] === true
                && $result['signature_valid'] === true
                && $result['hash_matches'] === true;
        });

        $api = $this->actingAs($buyer)->postJson('/api/v1/verify/online-crosscheck', [
            'logs' => [[
                'sync_uuid' => 'qr-regression-sync-1',
                'outer_serial' => $outerQr,
                'inner_serial' => $wrappedInnerQr,
                'device_token' => 'qr-regression-device',
                'scan_mode' => 'online_live',
                'scanned_at' => now()->toIso8601String(),
            ]],
        ]);

        $api->assertOk()
            ->assertJsonPath('data.results.0.verdict', 'verified_clean')
            ->assertJsonPath('data.results.0.signature_valid', true)
            ->assertJsonPath('data.results.0.registered', true)
            ->assertJsonPath('data.results.0.duplicate', false);
    }

    public function test_invalid_limits_are_rejected_and_all_pairs_can_be_printed(): void
    {
        [$order, $buyer] = $this->makeOrder(5);
        $this->generateSerials($order, $buyer, 3);
        $this->actingAs($buyer)->getJson(route('orders.qr-stickers', ['po' => $order, 'limit' => 0]))->assertStatus(422);
        $this->actingAs($buyer)->getJson(route('orders.qr-stickers', ['po' => $order, 'limit' => 20001]))->assertStatus(422);
        $this->actingAs($buyer)->get(route('orders.qr-stickers', ['po' => $order]))->assertOk();
    }

    public function test_dashboard_and_verifier_expose_the_qr_print_links(): void
    {
        [$order, $buyer] = $this->makeOrder(5);
        $this->generateSerials($order, $buyer, 2);

        $this->actingAs($buyer)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('orders.qr-stickers', ['po' => $order, 'limit' => config('triproof.exports.qr_preview_pairs', 2)]), false)
            ->assertSee(__('triproof.orders.qr_all'), false);

        $this->actingAs($buyer)->get(route('verify.index'))
            ->assertOk()
            ->assertSee(route('orders.qr-stickers', ['po' => $order, 'limit' => config('triproof.exports.qr_preview_pairs', 2)]), false)
            ->assertSee('Print QR Stickers (2 Pairs) — PO-', false);
    }

    public function test_empty_orders_cannot_print_sticker_sheets(): void
    {
        [$order, $buyer] = $this->makeOrder(5);
        $this->actingAs($buyer)->getJson(route('orders.qr-stickers', ['po' => $order]))->assertStatus(422);
    }

    public function test_pristine_legacy_yyyymmdd_direct_scan_is_authentic(): void
    {
        $crypto = app(SerialCryptoService::class);
        $outer = $crypto->formatOuterSerial(
            'BD01',
            'M04',
            'B202601',
            1,
            CarbonImmutable::parse('20260131'),
            CarbonImmutable::parse('20270131')
        );
        $signature = $crypto->sign($outer);

        $this->assertNotNull($crypto->parseOuterSerial($outer));
        $this->assertTrue($crypto->verify($outer, $signature));
    }
}
