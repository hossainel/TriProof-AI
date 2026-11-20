# GS1 / Compliance Guidelines

## GS1 identifier discipline

Factory, machine, batch and unit identifiers must remain stable for the life of a purchase order. Do not reuse a unit number inside the same factory-machine-batch tuple. Serials are canonicalized through the 7-stage state machine before signature verification so raw `/v/{OUTER_SERIAL}` smart URLs and `TP1:` inner payloads resolve to the same canonical outer serial.

## Digital Product Passport

The optional `DPP:{8HEX}` digest binds the unit to its product passport. A missing or mismatched digest does not invalidate a legacy `K00` signature, but it must be reported in the forensic inspection result for compliance review.

## Recall traceability

Recall events are batch scoped and must specify factory, machine, and affected count. Precision recalls update only matching serial statuses; never mark an entire factory as recalled. Daily Merkle anchor receipts provide immutable evidence of the recall decision.

## Cross-border compliance

Bangla explanations are mandatory for factory-floor operations in Bangladesh; English explanations are required for global buyers and customs authorities. Deterministic bilingual templates are used when no language model is available, so audit outcomes never depend on network access.

## Air-gapped deployments

Local quantized models (Ollama or llama.cpp) may enrich bilingual explanations with models such as `deepseek-r1:7b`, `qwen2.5:7b`, `glm4:9b`, and `gemma2:9b`, but the deterministic state machine remains authoritative. If the local runtime is unavailable, `AiExplanationService` falls back to templates without delaying verification. HMAC-signed alert webhooks feed an n8n workflow for Slack, Telegram, and email dispatch; D3.js lineage and Apache ECharts serial-gap widgets give auditors visual evidence.
