<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCrosscheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $hasLogs = is_array($this->input('logs'));

        if ($hasLogs) {
            return [
                'logs' => ['required', 'array', 'min:1', 'max:500'],
                'logs.*.sync_uuid' => ['required', 'string', 'max:64', 'distinct'],
                'logs.*.outer_serial' => ['required', 'string', 'max:128'],
                'logs.*.inner_serial' => ['required', 'string', 'max:255'],
                'logs.*.device_token' => ['required', 'string', 'max:128'],
                'logs.*.scan_mode' => ['nullable', 'in:offline_synced,online_live'],
                'logs.*.offline_verdict' => ['nullable', 'string', 'max:64'],
                'logs.*.gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
                'logs.*.gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
                'logs.*.scanned_at' => ['nullable', 'date'],
            ];
        }

        return [
            'sync_uuid' => ['required', 'string', 'max:64'],
            'outer_serial' => ['required', 'string', 'max:128'],
            'inner_serial' => ['required', 'string', 'max:255'],
            'device_token' => ['required', 'string', 'max:128'],
            'scan_mode' => ['nullable', 'in:offline_synced,online_live'],
            'offline_verdict' => ['nullable', 'string', 'max:64'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'scanned_at' => ['nullable', 'date'],
        ];
    }

    public function normalizedLogs(): array
    {
        $logs = $this->input('logs');

        if (is_array($logs)) {
            return array_values($logs);
        }

        return [$this->only([
            'sync_uuid',
            'outer_serial',
            'inner_serial',
            'device_token',
            'scan_mode',
            'offline_verdict',
            'gps_lat',
            'gps_lng',
            'scanned_at',
        ])];
    }
}
