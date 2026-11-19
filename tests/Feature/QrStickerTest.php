<?php

namespace Tests\Feature;

use App\Models\User;
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

        // Verify what the camera actually sees: extract the four PNGs dompdf embedded from the
        // finished PDF, then decode them with the local QR reader. No network service is involved.
        $token = bin2hex(random_bytes(6));
        $temp = sys_get_temp_dir().'/triproof-qr-'.$token.'.pdf';
        $prefix = sys_get_temp_dir().'/triproof-qr-'.$token;
        file_put_contents($temp, $pdf);
        exec('pdfimages -png '.escapeshellarg($temp).' '.escapeshellarg($prefix).' 2>/dev/null', $output, $code);
        $images = glob($prefix.'*.png');
        $read = [];
        foreach ($images as $image) {
            $read[] = (new QRCode)->readFromFile($image)->data;
        }
        foreach ([...$images, $temp] as $file) {
            @unlink($file);
        }

        $this->assertSame(0, $code);
        $this->assertCount(4, $read);
        foreach ($expected as $serial) {
            $this->assertContains($serial->outer_serial, $read);
            $this->assertContains($serial->inner_serial, $read);
        }

        $html = view('exports.qr-labels-pdf', [
            'order' => $order,
            'serials' => $expected->map(fn ($serial) => [
                'outer_serial' => $serial->outer_serial,
                'inner_serial' => $serial->inner_serial,
                'unit_hash' => $serial->unit_hash,
                'factory_id' => $serial->factory_id,
                'machine_id' => $serial->machine_id,
                'batch_id' => $serial->batch_id,
                'exp_date' => $serial->exp_date->toDateString(),
                'outer_qr' => 'data:image/png;base64,AAAA',
                'inner_qr' => 'data:image/png;base64,BBBB',
            ])->all(),
            'printedPairs' => 2,
            'totalPairs' => 5,
            'truncated' => true,
            'merkle' => $order->merkle_root_hash ?: '—',
            'printedAt' => now(),
        ])->render();

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
}
