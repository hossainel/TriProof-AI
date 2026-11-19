<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCrosscheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $key = is_array($this->input('logs')) ? 'logs' : 'records';
        $nested = $key.'.*';
        $topLevel = is_array($this->input('logs')) || is_array($this->input('records'));

        if ($topLevel) {
            return [
                $key => ['required', 'array', 'min:1', 'max:500'],
                'device_token' => ['nullable', 'string', 'max:128'],
                $nested.'.sync_uuid' => ['required', 'string', 'max:64', 'distinct'],
                $nested.'.outer_serial' => ['required', 'string', 'max:128'],
                $nested.'.inner_serial' => ['nullable', 'string', 'max:255'],
                $nested.'.device_token' => ['nullable', 'string', 'max:128'],
                $nested.'.unit_hash' => ['nullable', 'string', 'max:64'],
                $nested.'.factory_id' => ['nullable', 'string', 'max:32'],
                $nested.'.machine_id' => ['nullable', 'string', 'max:32'],
                $nested.'.batch_id' => ['nullable', 'string', 'max:32'],
                $nested.'.prod_date' => ['nullable', 'string', 'max:32'],
                $nested.'.exp_date' => ['nullable', 'string', 'max:32'],
                $nested.'.offline_status' => ['nullable', 'string', 'max:64'],
                $nested.'.scan_mode' => ['nullable', 'string', 'max:32'],
                $nested.'.offline_verdict' => ['nullable', 'string', 'max:64'],
                $nested.'.transfer_ticket' => ['nullable', 'string', 'max:512'],
                $nested.'.transfer' => ['nullable'],
                $nested.'.scan_seq' => ['nullable', 'integer', 'min:0'],
                $nested.'.device_signature' => ['nullable', 'string', 'max:255'],
                $nested.'.contact_hash' => ['nullable', 'string', 'max:64'],
                $nested.'.gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
                $nested.'.gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
                $nested.'.scanned_at' => ['nullable', 'date'],
            ];
        }

        return [
            'sync_uuid' => ['required', 'string', 'max:64'],
            'outer_serial' => ['required', 'string', 'max:128'],
            'inner_serial' => ['nullable', 'string', 'max:255'],
            'device_token' => ['required_without:records', 'nullable', 'string', 'max:128'],
            'scan_mode' => ['nullable', 'string', 'max:32'],
            'offline_verdict' => ['nullable', 'string', 'max:64'],
            'transfer_ticket' => ['nullable', 'string', 'max:512'],
            'transfer' => ['nullable'],
            'scan_seq' => ['nullable', 'integer', 'min:0'],
            'device_signature' => ['nullable', 'string', 'max:255'],
            'contact_hash' => ['nullable', 'string', 'max:64'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'scanned_at' => ['nullable', 'date'],
        ];
    }

    public function normalizedLogs(): array
    {
        $rows = $this->input('logs') ?? $this->input('records');
        $topDevice = (string) ($this->input('device_token') ?? '');

        if (is_array($rows)) {
            $logs = array_values($rows);
        } else {
            $logs = [$this->only([
                'sync_uuid', 'outer_serial', 'inner_serial', 'device_token', 'unit_hash',
                'factory_id', 'machine_id', 'batch_id', 'prod_date', 'exp_date', 'offline_status',
                'scan_mode', 'offline_verdict', 'transfer_ticket', 'transfer', 'scan_seq',
                'device_signature', 'contact_hash', 'gps_lat', 'gps_lng', 'scanned_at',
            ])];
        }

        return array_map(function (array $log) use ($topDevice) {
            if (($log['device_token'] ?? '') === '' && $topDevice !== '') {
                $log['device_token'] = $topDevice;
            }
            $mode = strtolower((string) ($log['scan_mode'] ?? 'offline_synced'));
            if (in_array($mode, ['consumer_unboxing', 'factory_packing_qa'], true)) {
                $log['scan_mode'] = 'offline_synced';
            }

            return $log;
        }, $logs);
    }
}
