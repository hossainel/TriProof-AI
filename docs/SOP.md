# Factory SOPs (TriProof AI)

## Serial generation

Every unit label is generated inside a database transaction. The purchase order row is locked with `SELECT ... FOR UPDATE`, the remaining quota is verified, and exactly `count` unit hashes are inserted with unique constraints. No partial generation is permitted.

## Label sealing

Factory QA prints the outer serial and inner Ed25519 signature together. The outer serial carries factory, machine, batch, unit number, production date, expiry date and optional DPP digest. Apply the label before carton sealing and never reprint a label for a consumed unit.

## Rework reconciliation

When a unit is reworked, the child serial must reference the certified parent via `rework_parent_serial`. Rework is idempotent; a cycle or a parent from another factory is rejected. The resulting lineage is synchronized into the carton Merkle tree.

## Offline verification

Verifiers may operate without connectivity. The offline package contains a 50 KB blacklist and a 240K-bit Bloom filter. Raw `/v/{OUTER_SERIAL}` smart URLs and `TP1:`-prefixed inner payloads are normalized to the canonical outer serial before Ed25519 verification. The 7-stage deterministic state machine is: normalize, parse, resolve key ring, verify signature, registry lookup, recall/expiry/clone flags, and scan-history replay check. Verdict precedence is forged signature, recall, expiry, clone, then clean. Sync trust keys and blacklist deltas when connectivity returns.

## Provenance and analytics

Rework serials and carton containment are synchronized into the provenance graph (Neo4j when configured, SQL recursive CTE otherwise) to compute multi-hop recall blast radius. Daily serial snapshots feed German Tank gap estimation without locking transactional tables. Agents may inspect all of this through the five read-only MCP tools with the `mcp:read` scope.

## Bilingual incident reporting

All anomalies are recorded with English and Bangla explanations. Factory operators must acknowledge critical clone alerts before the affected carton is released. Deterministic templates are authoritative when no AI provider is configured.
