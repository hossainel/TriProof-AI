<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class McpController extends Controller
{
    public function __construct(
        private readonly McpToolService $tools,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $id = $request->input('id');

        if ($request->input('jsonrpc') !== '2.0') {
            return $this->rpcError($id, -32600, 'Invalid Request: jsonrpc must be 2.0.');
        }

        $method = $request->input('method');
        if (! is_string($method)) {
            return $this->rpcError($id, -32600, 'Invalid Request: method is required.');
        }

        return match ($method) {
            'initialize' => $this->rpcSuccess($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name' => 'triproof-ai-mcp',
                    'version' => '2.1.0',
                ],
            ]),
            'tools/list' => $this->rpcSuccess($id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->handleToolCall($request, $id),
            default => $this->rpcError($id, -32601, 'Method not found.'),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'verify_serial',
                'description' => 'Run the deterministic 7-stage verification state machine and return authenticity, key ID and scan count.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'payload_or_serial' => ['type' => 'string', 'description' => 'Raw serial, smart URL, or TP1 payload.'],
                    ],
                    'required' => ['payload_or_serial'],
                ],
            ],
            [
                'name' => 'get_batch_anomalies',
                'description' => 'Return deterministic detector findings and bilingual summaries for a batch.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'batch_id' => ['type' => 'string'],
                        'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    ],
                    'required' => ['batch_id'],
                ],
            ],
            [
                'name' => 'trace_rework_lineage',
                'description' => 'Traverse parent-child rework serials and carton containment into a full unit tree.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'serial' => ['type' => 'string'],
                    ],
                    'required' => ['serial'],
                ],
            ],
            [
                'name' => 'check_recall_status',
                'description' => 'Check active recall events and blacklist status for a batch or serial.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'batch_id_or_serial' => ['type' => 'string'],
                    ],
                    'required' => ['batch_id_or_serial'],
                ],
            ],
            [
                'name' => 'get_merkle_proof',
                'description' => 'Return the SHA-256 Merkle inclusion proof and daily anchor receipt for a scan log.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'scan_log_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['scan_log_id'],
                ],
            ],
        ];
    }

    private function handleToolCall(Request $request, mixed $id): JsonResponse
    {
        $params = $request->input('params');

        if (! is_array($params) || ! isset($params['name']) || ! is_string($params['name'])) {
            return $this->rpcError($id, -32602, 'Invalid params: params.name is required.');
        }

        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            return $this->rpcError($id, -32602, 'Invalid params: params.arguments must be an object.');
        }

        return $this->rpcSuccess($id, $this->callTool($params['name'], $arguments));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        try {
            $result = match ($name) {
                'verify_serial' => $this->verifySerial($arguments),
                'get_batch_anomalies' => $this->batchAnomalies($arguments),
                'trace_rework_lineage' => $this->reworkLineage($arguments),
                'check_recall_status' => $this->recallStatus($arguments),
                'get_merkle_proof' => $this->merkleProof($arguments),
                default => $this->toolError("Unknown tool: {$name}"),
            };
        } catch (Throwable) {
            return $this->toolError('The requested tool could not complete.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function verifySerial(array $arguments): array
    {
        $value = $arguments['payload_or_serial'] ?? null;
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 1000) {
            return $this->toolError('payload_or_serial is required (max 1000 characters).');
        }

        return $this->toolResult($this->tools->verifySerial(trim($value)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function batchAnomalies(array $arguments): array
    {
        $batch = $arguments['batch_id'] ?? null;
        if (! is_string($batch) || trim($batch) === '' || mb_strlen($batch) > 64) {
            return $this->toolError('batch_id is required (max 64 characters).');
        }

        $severity = $arguments['severity'] ?? null;
        if ($severity !== null && (! is_string($severity) || ! in_array(strtolower($severity), ['low', 'medium', 'high'], true))) {
            return $this->toolError('severity must be low, medium, or high.');
        }

        return $this->toolResult($this->tools->getBatchAnomalies(trim($batch), $severity ? strtolower($severity) : null));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function reworkLineage(array $arguments): array
    {
        $serial = $arguments['serial'] ?? null;
        if (! is_string($serial) || trim($serial) === '' || mb_strlen($serial) > 255) {
            return $this->toolError('serial is required (max 255 characters).');
        }

        return $this->toolResult($this->tools->traceReworkLineage(trim($serial)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function recallStatus(array $arguments): array
    {
        $value = $arguments['batch_id_or_serial'] ?? null;
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
            return $this->toolError('batch_id_or_serial is required (max 255 characters).');
        }

        return $this->toolResult($this->tools->checkRecallStatus(trim($value)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function merkleProof(array $arguments): array
    {
        $scanLogId = $arguments['scan_log_id'] ?? null;
        if (! is_numeric($scanLogId) || (int) $scanLogId < 1) {
            return $this->toolError('scan_log_id must be a positive integer.');
        }

        return $this->toolResult($this->tools->getMerkleProof((int) $scanLogId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toolResult(array $data): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
            ]],
            'isError' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolError(string $message): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ]],
            'isError' => true,
        ];
    }

    private function rpcSuccess(mixed $id, array $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function rpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
