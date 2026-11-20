<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class EnsureDocsAccessible
{
    public function handle(Request $request, Closure $next): Response
    {
        $visibilityEnabled = SystemSetting::getValue('docs_visibility_enabled', '1') === '1';
        $scheduleEnabled = SystemSetting::getValue('docs_schedule_enabled', '0') === '1';

        $isAdmin = $request->user()?->isAdmin() ?? false;
        $previewBypass = $isAdmin && $request->boolean('preview');

        $timezone = config('app.timezone', 'Asia/Dhaka');
        $now = now($timezone);

        $availableFrom = SystemSetting::getValue('docs_available_from', now()->year.'-06-10 00:00:00');
        $availableUntil = SystemSetting::getValue('docs_available_until', now()->year.'-06-14 23:59:59');

        $fromDate = Carbon::parse($availableFrom, $timezone);
        $untilDate = Carbon::parse($availableUntil, $timezone);

        $isScheduledActive = true;
        if ($scheduleEnabled) {
            $isScheduledActive = $now->between($fromDate, $untilDate);
        }

        $isAccessible = $visibilityEnabled && $isScheduledActive;

        if (! $isAccessible) {
            if ($previewBypass) {
                $request->attributes->set('docs_preview_active', true);
            } else {
                $reason = ! $visibilityEnabled
                    ? __('Public documentation and pitch deck access is currently disabled by the administrator.')
                    : __('The scheduled showcase window is currently closed (Available from :from to :until).', [
                        'from' => $fromDate->format('M j, Y H:i T'),
                        'until' => $untilDate->format('M j, Y H:i T'),
                    ]);

                return response()->view('docs.unavailable', [
                    'reason' => $reason,
                    'fromDate' => $fromDate,
                    'untilDate' => $untilDate,
                    'visibilityEnabled' => $visibilityEnabled,
                    'isScheduledActive' => $isScheduledActive,
                ], 403);
            }
        }

        if ($previewBypass) {
            $request->attributes->set('docs_preview_active', true);
        }

        return $next($request);
    }
}
