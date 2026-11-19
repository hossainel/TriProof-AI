<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AiAnomalyReport;
use App\Models\BlockchainAnchor;
use App\Models\PurchaseOrder;
use App\Models\RecallEvent;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Crypto\MasterKeyService;
use App\Services\Crypto\SerialCryptoService;
use App\Support\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, SerialCryptoService $crypto, MasterKeyService $keys): View
    {
        $user = $request->user();
        $data = $this->overview($user, $crypto, $keys);

        return match ($user->role) {
            UserRole::Admin => view('dashboard.admin', $data),
            UserRole::FactoryQa => view('dashboard.factory', $data),
            default => view('dashboard.buyer', $data),
        };
    }

    private function overview(User $user, SerialCryptoService $crypto, MasterKeyService $keys): array
    {
        $orderQuery = PurchaseOrder::query()->visibleTo($user);
        $poIdsQuery = (clone $orderQuery)->select('purchase_orders.id');

        $totalsRow = (clone $orderQuery)
            ->selectRaw('COALESCE(SUM(authorized_quota), 0) as authorized_quota')
            ->selectRaw('COALESCE(SUM(generated_count), 0) as generated_count')
            ->selectRaw('COUNT(*) as order_count')
            ->first();

        $status = SerialRegistry::query()
            ->whereIn('po_id', $poIdsQuery)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $totals = [
            'authorized_quota' => (int) $totalsRow->authorized_quota,
            'generated' => (int) $totalsRow->generated_count,
            'order_count' => (int) $totalsRow->order_count,
            'generated_units' => (int) ($status['generated'] ?? 0),
            'packed' => (int) ($status['packed'] ?? 0),
            'in_transit' => (int) ($status['in_transit'] ?? 0),
            'claimed' => (int) ($status['claimed_genuine'] ?? 0),
            'cloned' => (int) ($status['cloned_flagged'] ?? 0),
            'recalled' => (int) ($status['recalled'] ?? 0),
            'serials' => (int) $status->sum(),
        ];

        $orders = (clone $orderQuery)
            ->with('buyer:id,name,email')
            ->withCount([
                'serials as total_units',
                'serials as packed_units' => fn ($q) => $q->where('status', 'packed'),
                'serials as claimed_units' => fn ($q) => $q->where('status', 'claimed_genuine'),
                'serials as cloned_units' => fn ($q) => $q->where('status', 'cloned_flagged'),
                'serials as recalled_units' => fn ($q) => $q->where('status', 'recalled'),
            ])
            ->latest()
            ->limit(100)
            ->get();

        $factories = User::query()
            ->where('role', UserRole::FactoryQa)
            ->whereNotNull('factory_id')
            ->orderBy('factory_id')
            ->get(['id', 'name', 'factory_id'])
            ->unique('factory_id')
            ->values();

        $buyers = User::query()
            ->where('role', UserRole::GlobalBuyer)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $accessibleFactories = $factories->pluck('factory_id')->filter()->values()->all();
        if ($user->isFactory()) {
            $accessibleFactories = array_values(array_filter([$user->factory_id]));
        } elseif ($user->isBuyer()) {
            $accessibleFactories = $orders->pluck('factory_id')->merge(
                PurchaseOrder::query()->where('buyer_id', $user->id)->pluck('factory_id')
            )->unique()->filter()->values()->all();
        }

        $anomalyQuery = AiAnomalyReport::query()
            ->with('po:id,po_number')
            ->where('status', 'open');

        if (! $user->isAdmin()) {
            $anomalyQuery->where(function ($q) use ($poIdsQuery) {
                $q->whereIn('po_id', $poIdsQuery);
            });
        }

        $anomalies = $anomalyQuery->latest('updated_at')->limit(30)->get();

        $recallQuery = RecallEvent::query()->with(['po:id,po_number', 'triggeredBy:id,name']);

        if (! $user->isAdmin()) {
            if ($accessibleFactories === []) {
                $recallQuery->whereRaw('1 = 0');
            } else {
                $recallQuery->whereIn('factory_id', $accessibleFactories);
            }
        }

        $recalls = $recallQuery->latest()->limit(15)->get();

        $batchQuery = DB::table('serial_registry as sr')
            ->join('purchase_orders as po', 'po.id', '=', 'sr.po_id')
            ->whereIn('sr.po_id', $poIdsQuery)
            ->select(
                'sr.po_id',
                'po.po_number',
                'sr.factory_id',
                'sr.machine_id',
                'sr.batch_id',
                DB::raw('COUNT(*) as units'),
                DB::raw("SUM(CASE WHEN sr.status = 'generated' THEN 1 ELSE 0 END) as generated"),
                DB::raw("SUM(CASE WHEN sr.status = 'packed' THEN 1 ELSE 0 END) as packed"),
                DB::raw("SUM(CASE WHEN sr.status = 'claimed_genuine' THEN 1 ELSE 0 END) as claimed"),
                DB::raw("SUM(CASE WHEN sr.status = 'cloned_flagged' THEN 1 ELSE 0 END) as cloned"),
                DB::raw("SUM(CASE WHEN sr.status = 'recalled' THEN 1 ELSE 0 END) as recalled"),
                DB::raw('MAX(sr.created_at) as latest_at')
            )
            ->groupBy(
                'sr.po_id',
                'po.po_number',
                'sr.factory_id',
                'sr.machine_id',
                'sr.batch_id'
            )
            ->orderByDesc(DB::raw('MAX(sr.created_at)'))
            ->limit(100)
            ->get();

        $markers = $this->markers($user, $poIdsQuery, $accessibleFactories, $crypto);

        $users = $user->isAdmin()
            ? User::query()->orderBy('name')->limit(200)->get(['id', 'name', 'email', 'role', 'factory_id', 'language_pref', 'created_at'])
            : collect();

        return [
            'totals' => $totals,
            'users' => $users,
            'orders' => $orders,
            'factories' => $factories,
            'buyers' => $buyers,
            'anomalies' => $anomalies,
            'recalls' => $recalls,
            'batches' => $batchQuery,
            'markers' => $markers,
            'chartData' => [
                'generated' => $totals['generated_units'],
                'packed' => $totals['packed'],
                'in_transit' => $totals['in_transit'],
                'claimed' => $totals['claimed'],
                'cloned' => $totals['cloned'],
                'recalled' => $totals['recalled'],
            ],
            'isAdmin' => $user->isAdmin(),
            'accessibleFactories' => $accessibleFactories,
            'keyRing' => $keys->keyRing(),
            'anchors' => BlockchainAnchor::query()->latest('anchor_date')->limit(8)->get(),
        ];
    }

    private function markers(User $user, $poIdsQuery, array $accessibleFactories, SerialCryptoService $crypto): array
    {
        $rows = DB::table('scan_logs as s')
            ->join('serial_registry as r', 'r.outer_serial', '=', 's.outer_serial')
            ->whereIn('r.po_id', $poIdsQuery)
            ->whereIn('s.cloud_cross_check_verdict', ['clone_detected', 'recalled_batch', 'expired'])
            ->whereNotNull('s.gps_lat')
            ->whereNotNull('s.gps_lng')
            ->orderByDesc('s.scanned_at')
            ->limit(250)
            ->get([
                's.id',
                's.outer_serial',
                's.device_token',
                's.cloud_cross_check_verdict as verdict',
                's.gps_lat',
                's.gps_lng',
                's.scanned_at',
                'r.batch_id',
                'r.factory_id',
            ]);

        $markers = [];
        foreach ($rows as $row) {
            $markers[] = [
                'id' => $row->id,
                'lat' => (float) $row->gps_lat,
                'lng' => (float) $row->gps_lng,
                'serial' => $row->outer_serial,
                'batch' => $row->batch_id,
                'factory' => $row->factory_id,
                'verdict' => $row->verdict,
                'device' => DeviceToken::mask($row->device_token),
                'scanned_at' => $row->scanned_at,
            ];
        }

        $forged = DB::table('scan_logs')
            ->where('cloud_cross_check_verdict', 'forged_signature')
            ->whereNotNull('gps_lat')
            ->whereNotNull('gps_lng')
            ->orderByDesc('scanned_at')
            ->limit(150)
            ->get(['id', 'outer_serial', 'device_token', 'gps_lat', 'gps_lng', 'scanned_at']);

        foreach ($forged as $row) {
            $parsed = $crypto->parseOuterSerial($row->outer_serial);
            if (! $user->isAdmin()) {
                if (! $parsed || ! in_array($parsed['factory_id'], $accessibleFactories, true)) {
                    continue;
                }
            }

            $markers[] = [
                'id' => $row->id,
                'lat' => (float) $row->gps_lat,
                'lng' => (float) $row->gps_lng,
                'serial' => $row->outer_serial,
                'batch' => $parsed['batch_id'] ?? null,
                'factory' => $parsed['factory_id'] ?? null,
                'verdict' => 'forged_signature',
                'device' => DeviceToken::mask($row->device_token),
                'scanned_at' => $row->scanned_at,
            ];
        }

        return $markers;
    }
}
