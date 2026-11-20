# TriProof AI MCP Server

TriProof AI exposes a read-only Model Context Protocol (MCP) surface for agents, judges, and partner systems. All tools are deterministic and backed by existing transactional services; private signing keys are never returned.

## Authentication & scope

Every request must carry either:

- `Authorization: Bearer <JWT>` issued by `POST /api/v1/auth/login`, with the `mcp:read` scope claim, or
- `X-TriProof-Api-Key: <key>` matching `MCP_API_KEY`, mapped to `MCP_API_KEY_USER_ID` (or the first admin account).

Requests are rate limited by `RATE_LIMIT_MCP` (default 60/min).

## Transports

### HTTP / Streamable HTTP

```text
POST {APP_URL}/api/v1/mcp
Content-Type: application/json
```

The Laravel endpoint implements JSON-RPC 2.0 methods `initialize`, `tools/list`, and `tools/call`. A lightweight bridge is also provided for clients that prefer a local process:

```bash
MCP_TRANSPORT=stdio TRIPROOF_BASE_URL=https://... TRIPROOF_MCP_API_KEY=... node mcp-server/bridge.js
MCP_TRANSPORT=http MCP_HTTP_PORT=8787 TRIPROOF_BASE_URL=https://... TRIPROOF_MCP_API_KEY=... node mcp-server/bridge.js
```

`mcp.json` contains ready-to-use Claude Desktop / Cursor / OpenCode client entries.

## Tools (read-only)

| Tool | Arguments | Returns |
|---|---|---|
| `verify_serial` | `payload_or_serial` | 7-stage forensic state machine, key ID, authenticity verdict, scan count |
| `get_batch_anomalies` | `batch_id`, optional `severity` | Detector findings, severity, English/Bangla summaries, recommended actions |
| `trace_rework_lineage` | `serial` | Parent-child rework nodes/edges plus carton containment |
| `check_recall_status` | `batch_id_or_serial` | Active recall events, blacklist flag, recalled/cloned serial counts |
| `get_merkle_proof` | `scan_log_id` | SHA-256 inclusion proof, verification result, daily anchor receipt |

Example:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "verify_serial",
    "arguments": { "payload_or_serial": "K00-DHK04-M01-B01-000001|20261120|20271120" }
  }
}
```

The response content is an MCP text item containing pretty-printed JSON, with `isError: false` on success.

## Permissions

- No tool mutates state, claims ownership, rotates keys, or triggers recalls.
- Purchase-order data is filtered with `visibleTo()` for the authenticated principal.
- Cryptographic verification remains deterministic when AI, Neo4j, vector, or DuckDB services are unavailable.
