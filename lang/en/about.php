<?php

return [
    'title' => 'About TriProof AI',
    'tagline' => 'Offline-first Ed25519 supply-chain authenticity, quota-locked serial signing, and bilingual compliance intelligence for global buyers and Bangladeshi factories.',
    'team_title' => 'Built for Supply-Chain Trust',

    'mission_title' => 'Our Mission',
    'mission_intro' => 'Counterfeiting, outer-box swapping, and unreported ghost overproduction undermine trust between global apparel and consumer brands and the factories that manufacture their goods.',
    'mission_intro_2' => 'TriProof AI eliminates reliance on expensive proprietary security foils or constant factory-floor internet connectivity by pairing standard thermal/A4 QR labels with mathematical Ed25519 signatures.',
    'mission_intro_3' => 'From Dhaka and Chattogram packing lines to customs checkpoints and retail shelves, every unit is verifiable in under 50 milliseconds—even in Airplane Mode.',

    'mission_points' => [
        'Prevent outer-box swapping and label cloning',
        'Stop unreported ghost overproduction with row-locked PO quotas',
        'Verify authenticity in <50ms with zero internet connection',
        'Execute precision batch recalls instead of broad recalls',
        'Reconstruct broken serial lineage during factory rework',
        'Bridge compliance gaps with plain-Bangla supervisor guidance',
    ],

    'how_title' => 'How It Works',
    'how_intro' => 'A three-stage cryptographic lifecycle from purchase order issuance to consumer unboxing.',
    'how_sign' => 'Step 01 • Quota-Locked Issuance',
    'how_verify' => 'Step 02 • Offline & Cloud Check',
    'how_audit' => 'Step 03 • Merkle & Recall Control',
    'how_steps' => [
        'Global buyers issue a quota-locked Purchase Order. HQ signs each unit’s outer serial with a private Ed25519 key to produce a paired inner seal signature and SHA-256 unit hash.',
        'Factory QA, customs, and consumers scan paired O1/I1 labels offline in <50ms using on-device Vision AI, then auto-sync via store-and-forward cross-checking when online.',
        'Every batch is anchored to a deterministic SHA-256 Merkle root, enabling instant clone alerts, rework lineage tracking, and surgical batch recalls.',
    ],

    'tech_title' => 'Technology Stack',
    'tech_description' => 'Engineered for high-throughput factory floors, low-connectivity ports, and verifiable cryptographic audits.',
    'tech' => [
        'Laravel 12 & PHP 8.4',
        'Native Ed25519 (ext-sodium)',
        'SHA-256 Merkle Trees',
        'MySQL 8 / MariaDB',
        'Flutter & On-Device ML Kit OCR',
        'SQLCipher Encrypted Storage',
        'Tailwind CSS 4 & Vite',
        'Bilingual AI Compliance Engine (EN/BN)',
    ],

    'member_role_lead_architect' => 'System Architecture & Cryptography',
    'member_bio_lead_architect' => 'Designed the quota-locked Ed25519 signing protocol, Merkle root auditing, and end-to-end verification state machine.',

    'member_role_cryptography_engineer' => 'Applied Security & Key Management',
    'member_bio_cryptography_engineer' => 'Built detached signature verification, anti-clock-rollback protection, and the <50KB offline blacklist compression pipeline.',

    'member_role_mobile_vision_engineer' => 'Offline Mobile & Computer Vision',
    'member_bio_mobile_vision_engineer' => 'Developed the sub-50ms offline scanner, curved-label ML Kit OCR normalizer, and store-and-forward sync engine.',

    'member_role_backend_systems_engineer' => 'API, Database & Print Exports',
    'member_bio_backend_systems_engineer' => 'Engineered transactional batch generation, rework lineage reconstruction, and print-ready O1/I1 QR sticker sheets.',

    'member_role_compliance_product_lead' => 'Factory Operations & Bilingual UX',
    'member_bio_compliance_product_lead' => 'Designed the English/Bangla floor workflows, precision recall controls, and actionable supervisor anomaly reports.',

    'proof_title' => 'Built Together for Real Factory Floors',
    'proof_text' => [
        'TriProof AI was built around the real operational constraints of export manufacturing: internet dead zones in warehouses, curved or smudged polybag labels, multi-shift packing lines, and factory teams who need clear Bangla guidance rather than cryptic error codes.',
        'Every cryptographic check, offline cache, and audit trail is test-verified so buyers, inspectors, and end consumers can trust every label.',
    ],

    'cta_note' => 'Ready to verify a label or manage your factory purchase orders?',
    'cta_text' => 'Sign In to Dashboard',

    'nav_about' => 'About',
    'sign_in' => 'Sign in',
    'team_lead_badge' => 'Team lead',
    'privacy_title' => 'Privacy',
    'privacy_description' => 'How TriProof AI handles operational and account data.',
    'privacy_body' => [
        'TriProof AI stores only the account details you provide, the scan audit metadata needed for traceability, and the minimum operational records required to verify labels and manage purchase orders.',
        'Audit records are retained to support recall investigations and compliance reviews. We do not sell personal data to third parties.',
        'For access, correction, or deletion requests, contact your deployment administrator.',
    ],
    'terms_title' => 'Terms',
    'terms_description' => 'Access rules for the TriProof AI verification platform.',
    'terms_body' => [
        'Access is limited to authorized buyers, factory QA teams, and administrators. Keep your credentials confidential and report suspected misuse immediately.',
        'Cryptographic verification results are decision-support evidence. Physical inspection of the product and its packaging remains the final authority.',
        'Unauthorized access, automated scraping, or attempts to forge labels and serials are prohibited.',
    ],
];
