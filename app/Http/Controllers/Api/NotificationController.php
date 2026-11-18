<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MarkNotificationsReadRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    public function index(Request $request, string $deviceToken): JsonResponse
    {
        $this->assertDeviceToken($deviceToken);

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $query = Notification::query()->where('device_token', $deviceToken);

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $items = $query->latest('id')->paginate($perPage);

        $unread = Notification::query()
            ->where('device_token', $deviceToken)
            ->where('is_read', false)
            ->count();

        return ApiResponse::success([
            'device_token' => $deviceToken,
            'unread_count' => $unread,
            'notifications' => collect($items->items())->map(fn (Notification $n) => NotificationService::toPayload($n))->all(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function markRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        $deviceToken = (string) $request->input('device_token');
        $this->assertDeviceToken($deviceToken);

        if (! $request->boolean('mark_all') && empty($request->input('ids'))) {
            throw ValidationException::withMessages([
                'ids' => ['Provide notification ids or set mark_all=true.'],
            ]);
        }

        $query = Notification::query()
            ->where('device_token', $deviceToken)
            ->where('is_read', false);

        if (! $request->boolean('mark_all')) {
            $query->whereIn('id', $request->input('ids', []));
        }

        $updated = $query->update(['is_read' => true, 'updated_at' => now()]);

        return ApiResponse::success([
            'device_token' => $deviceToken,
            'marked_read' => $updated,
        ], 'Notifications updated.');
    }

    private function assertDeviceToken(string $deviceToken): void
    {
        if ($deviceToken === '' || strlen($deviceToken) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $deviceToken)) {
            throw new BusinessRuleException('invalid_device_token', 'The device token format is invalid.');
        }
    }
}
