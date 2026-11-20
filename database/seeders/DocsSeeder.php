<?php

namespace Database\Seeders;

use App\Models\DocSection;
use App\Models\DocTeamMember;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class DocsSeeder extends Seeder
{
    public function run(): void
    {
        $year = now()->year;

        $settings = [
            'docs_visibility_enabled' => '1',
            'docs_schedule_enabled' => '0',
            'docs_available_from' => "{$year}-06-10 00:00:00",
            'docs_available_until' => "{$year}-06-14 23:59:59",
            'docs_team_name' => 'Team TriProof AI',
        ];

        foreach ($settings as $key => $value) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        // Pre-seed team members from config/team.php or fallback definition
        $teamMembers = [
            [
                'full_name' => 'Feroz Mahmud',
                'role_title' => 'Team Lead & Product Architect',
                'email' => 'feroz@triproof.local',
                'bio_short' => 'Leading product strategy, supply-chain cryptography, and stakeholder alignment.',
                'photo_path' => null,
                'sort_order' => 1,
            ],
            [
                'full_name' => 'Shafayet Al Hossain',
                'role_title' => 'Full Stack Developer & Domain Expert',
                'email' => 'shafayet@triproof.local',
                'bio_short' => 'Core developer specializing in Laravel 12 architecture, Ed25519 verification pipelines, and Flutter offline sync.',
                'photo_path' => null,
                'sort_order' => 2,
            ],
            [
                'full_name' => 'Remon Islam',
                'role_title' => 'AI & ML Engineer',
                'email' => 'remon@triproof.local',
                'bio_short' => 'Designing deterministic bilingual EN/বাংলা anomaly detection and automated audit intelligence.',
                'photo_path' => null,
                'sort_order' => 3,
            ],
            [
                'full_name' => 'Masrurul Hasan Maktum',
                'role_title' => 'Developer & QA Tester',
                'email' => 'masrurul@triproof.local',
                'bio_short' => 'Ensuring robust test coverage across automated feature suites, QR sticker validation, and security gates.',
                'photo_path' => null,
                'sort_order' => 4,
            ],
            [
                'full_name' => 'Golam Rabbani',
                'role_title' => 'DevOps Engineer',
                'email' => 'rabbani@triproof.local',
                'bio_short' => 'Managing zero-SSH cPanel deployment packaging, automated APK builds, and infrastructure telemetry.',
                'photo_path' => null,
                'sort_order' => 5,
            ],
        ];

        foreach ($teamMembers as $member) {
            DocTeamMember::firstOrCreate(
                ['email' => $member['email']],
                $member
            );
        }

        // Pre-seed sections (Pitch Deck + Technical Whitepaper)
        $sections = [
            // A. YC Pitch Deck
            [
                'slug' => 'problem',
                'category' => 'pitch_deck',
                'section_key' => 'problem',
                'title_en' => 'The Global Supply-Chain Trust Crisis',
                'title_bn' => 'বৈশ্বিক সরবরাহ চেইন আস্থার সংকট',
                'subtitle_en' => '8 critical failure points plaguing modern manufacturing and international trade',
                'subtitle_bn' => 'আধুনিক উৎপাদন ও আন্তর্জাতিক বাণিজ্যের ৮টি গুরুত্বপূর্ণ ব্যর্থতার বিন্দু',
                'content_markdown' => "### The Problem\n\nGlobal supply chains lose over **$600B annually** to counterfeit goods, unauthorized quota overruns, unverified gray-market leakage, untrusted offline scanning, opaque product recalls, paper-based bill-of-lading fraud, non-deterministic compliance audits, and lack of verifiable origin provenance.\n\n#### 8 Solved Supply-Chain Risks\n1. **Counterfeit Injection:** Unsigned serials allow malicious third parties to clone stickers.\n2. **Quota Overruns:** Factories manufacturing unapproved units beyond buyer purchase order limits.\n3. **Gray-Market Leakage:** Diverted shipments bypassing authorized distribution channels.\n4. **Disconnected Blind Spots:** Inspectors operating in remote factories without reliable cellular connectivity.\n5. **Opaque Recalls:** Inability to pinpoint affected batches instantly, leading to sweeping recalls.\n6. **Paper Fraud:** Manual documentation prone to alteration and loss.\n7. **Compliance Friction:** Non-deterministic audit processes causing cross-border customs delays.\n8. **Provenance Ambiguity:** Zero cryptographic proof linking factory floor production to final buyer receipt.",
                'sort_order' => 10,
            ],
            [
                'slug' => 'solution',
                'category' => 'pitch_deck',
                'section_key' => 'solution',
                'title_en' => 'TriProof AI Solution: Signed at Source. Verified Everywhere.',
                'title_bn' => 'ট্রাইপ্রুফ এআই সমাধান: উৎসে স্বাক্ষরিত। সর্বত্র যাচাইযোগ্য।',
                'subtitle_en' => 'Quota-locked Ed25519 cryptography with offline-first verification and bilingual AI',
                'subtitle_bn' => 'অফলাইন-প্রথম যাচাইকরণ এবং দ্বিভাষিক এআই সহ কোটা-লক করা Ed25519 ক্রিপ্টোগ্রাফি',
                'content_markdown' => "### The Solution\n\n**TriProof AI** provides a mathematically provable, tamper-evident authenticity platform combining:\n- **Ed25519 Cryptographic Signatures:** Every serial is cryptographically signed at the factory gate (`K00` or buyer key).\n- **Quota-Locked Generation:** Atomic database locking (`SELECT ... FOR UPDATE`) prevents any purchase order from exceeding its authorized quota.\n- **Offline-First Verification:** A 50 KB edge package with a 240K-bit Bloom filter enabling instant verification without cellular data.\n- **Bilingual AI Diagnostics:** Deterministic English and Bengali anomaly detection and automated audit reporting.",
                'sort_order' => 20,
            ],
            [
                'slug' => 'why_now',
                'category' => 'pitch_deck',
                'section_key' => 'why_now',
                'title_en' => 'Why Now? Regulatory Mandates & Mobile Edge Readiness',
                'title_bn' => 'কেন এখনই? নিয়ন্ত্রক আদেশ এবং মোবাইল এজ প্রস্তুতি',
                'subtitle_en' => 'Digital Product Passport (DPP) regulations and ubiquitous smartphone penetration',
                'subtitle_bn' => 'ডিজিটাল প্রোডাক্ট পাসপোর্ট (DPP) প্রবিধান এবং সর্বত্র স্মার্টফোনের ব্যবহার',
                'content_markdown' => "### Why Now?\n\n- **Regulatory Tailwinds:** EU Digital Product Passport (DPP) and global traceability mandates require immutable unit-level provenance.\n- **Mobile Edge Power:** Modern smartphones feature secure enclaves and robust local storage (SQLCipher), making edge cryptography viable.\n- **Supply Chain Resiliency:** Post-pandemic logistics require decentralized, verifiable trust frameworks that do not depend on constant cloud connectivity.",
                'sort_order' => 30,
            ],
            [
                'slug' => 'product_demo',
                'category' => 'pitch_deck',
                'section_key' => 'product_demo',
                'title_en' => 'Interactive Product Demo & Verification Walkthrough',
                'title_bn' => 'ইন্টারেক্টিভ প্রোডাক্ট ডেমো এবং যাচাইকরণ ওয়াকথ্রু',
                'subtitle_en' => 'Test live passports instantly',
                'subtitle_bn' => 'তাত্ক্ষণিকভাবে লাইভ পাসপোর্ট পরীক্ষা করুন',
                'content_markdown' => "### Interactive Product Demo\n\nExperience TriProof AI in action:\n- Visit the live [Public Verification Portal](/verify) to scan or enter sample serials.\n- Sample verified payload format: `K00-PO2026-DHK04-M01-B01-000001|2026-06-10|2027-06-10|DPP:A1B2C3D4`\n- Inspect public signing keys at `/.well-known/triproof-key.json`.",
                'sort_order' => 40,
            ],
            [
                'slug' => 'market',
                'category' => 'pitch_deck',
                'section_key' => 'market',
                'title_en' => 'Market Opportunity & TAM / SAM / SOM',
                'title_bn' => 'বাজারের সুযোগ এবং TAM / SAM / SOM',
                'subtitle_en' => 'Multi-billion dollar global manufacturing and compliance market',
                'subtitle_bn' => 'বহু বিলিয়ন ডলারের বৈশ্বিক উৎপাদন এবং কমপ্লায়েন্স বাজার',
                'content_markdown' => "### Market Opportunity\n\n- **TAM ($85B):** Global supply chain visibility, anti-counterfeit, and enterprise compliance software.\n- **SAM ($18B):** Apparel, electronics, and pharma export manufacturing hubs across South Asia, Southeast Asia, and Eastern Europe.\n- **SOM ($1.2B):** Mid-to-enterprise tier manufacturers requiring quota-locked cryptographic tracking and offline verification.",
                'sort_order' => 50,
            ],
            [
                'slug' => 'business_model',
                'category' => 'pitch_deck',
                'section_key' => 'business_model',
                'title_en' => 'Business Model & Unit Economics',
                'title_bn' => 'ব্যবসায়িক মডেল এবং ইউনিট ইকোনমিক্স',
                'subtitle_en' => 'SaaS subscription + per-serial cryptographic verification tiers',
                'subtitle_bn' => 'SaaS সাবস্ক্রিপশন + প্রতি-সিরিয়াল ক্রিপ্টোগ্রাফিক যাচাইকরণ টায়ার',
                'content_markdown' => "### Business Model\n\n- **Platform Subscription:** Tiered monthly fees per factory / enterprise tenant.\n- **Serial Volume Tiers:** Pricing based on cryptographic serial generation volume (e.g., per 10k units signed).\n- **Enterprise Customization:** Dedicated private signing key vaults, ERP integrations, and custom AI compliance rules.",
                'sort_order' => 60,
            ],
            [
                'slug' => 'traction',
                'category' => 'pitch_deck',
                'section_key' => 'traction',
                'title_en' => 'Live System Traction & Real-Time Metrics',
                'title_bn' => 'লাইভ সিস্টেম ট্র্যাকশন এবং রিয়েল-টাইম মেট্রিক্স',
                'subtitle_en' => 'Verified through live database telemetry',
                'subtitle_bn' => 'লাইভ ডাটাবেস টেলিমিত্রির মাধ্যমে যাচাইকৃত',
                'content_markdown' => "### Live Traction\n\nTriProof AI monitors production integrity in real time. Our telemetry engine tracks active factories, purchase order quotas, cryptographically signed serials, packed units, verified scans, active recalls, and AI anomaly detection reports directly from the database.",
                'sort_order' => 70,
            ],
            [
                'slug' => 'competition',
                'category' => 'pitch_deck',
                'section_key' => 'competition',
                'title_en' => 'Competitive Landscape & Differentiation',
                'title_bn' => 'প্রতিযোগিতামূলক ল্যান্ডস্কেপ এবং পৃথকীকরণ',
                'subtitle_en' => 'Why TriProof AI outperforms legacy RFID, centralized databases, and blockchain bloat',
                'subtitle_bn' => 'কেন ট্রাইপ্রুফ এআই ঐতিহ্যবাহী RFID, কেন্দ্রীভূত ডাটাবেস এবং ব্লকচেইন ব্লোটকে ছাড়িয়ে যায়',
                'content_markdown' => "### Competitive Advantage\n\n| Feature | Legacy RFID / Barcode | Enterprise Bloatware | TriProof AI (v2.1) |\n|---|---|---|---|\n| **Tamper Proof** | Easily cloned | Centralized DB risk | **Ed25519 Source Signed** |\n| **Offline Capability**| None / Reader dependent | Requires cloud | **50 KB Package + Bloom** |\n| **Quota Control** | Manual / Audit prone | ERP dependent | **Atomic SELECT ... FOR UPDATE** |\n| **Deployment** | Expensive hardware | Months of setup | **Zero-SSH cPanel Zip** |",
                'sort_order' => 80,
            ],
            [
                'slug' => 'unique_advantage',
                'category' => 'pitch_deck',
                'section_key' => 'unique_advantage',
                'title_en' => 'Unique Advantage & Defensive Moat',
                'title_bn' => 'অনন্য সুবিধা এবং প্রতিরক্ষামূলক খাঁড়ি',
                'subtitle_en' => 'Cryptographic guarantees at the manufacturing edge',
                'subtitle_bn' => 'উৎপাদন প্রান্তে ক্রিপ্টোগ্রাফিক গ্যারান্টি',
                'content_markdown' => "### Defensive Moat\n\n1. **Cryptographic Binding:** Serials cannot be forged without holding the private signing key.\n2. **Edge Autonomy:** Offline verification works seamlessly in remote factories without internet.\n3. **Deterministic AI:** Consistent bilingual audit results without probabilistic hallucinations.",
                'sort_order' => 90,
            ],
            [
                'slug' => 'gtm',
                'category' => 'pitch_deck',
                'section_key' => 'gtm',
                'title_en' => 'Go-To-Market Strategy (GTM)',
                'title_bn' => 'গো-টু-মার্কেট কৌশল (GTM)',
                'subtitle_en' => 'Bottom-up factory onboarding and top-down global buyer mandates',
                'subtitle_bn' => 'বটম-আপ কারখানা অনবোর্ডিং এবং টপ-ডাউন গ্লোবাল ক্রেতা ম্যান্ডেট',
                'content_markdown' => "### Go-To-Market Strategy\n\n- **Factory Pilots:** Zero-cost onboarding for Tier-2 suppliers with zero-SSH cPanel deployment.\n- **Global Buyer Mandates:** Partnering with major apparel and electronics brands to require TriProof AI cryptographic serials across their supply chains.",
                'sort_order' => 100,
            ],
            [
                'slug' => 'team',
                'category' => 'pitch_deck',
                'section_key' => 'team',
                'title_en' => 'The Team',
                'title_bn' => 'টিম',
                'subtitle_en' => 'World-class engineers, cryptographers, and supply-chain domain experts',
                'subtitle_bn' => 'বিশ্বমানের প্রকৌশলী, ক্রিপ্টোগ্রাফার এবং সরবরাহ চেইন ডোমেন বিশেষজ্ঞ',
                'content_markdown' => "### Team TriProof AI\n\nOur team brings deep expertise in Laravel enterprise architecture, Ed25519 cryptography, Flutter mobile engineering, AI compliance models, and robust DevOps automation.",
                'sort_order' => 110,
            ],
            [
                'slug' => 'vision',
                'category' => 'pitch_deck',
                'section_key' => 'vision',
                'title_en' => 'Long-Term Vision',
                'title_bn' => 'দীর্ঘমেয়াদী দৃষ্টি',
                'subtitle_en' => 'The universal cryptographic verification layer for global commerce',
                'subtitle_bn' => 'বৈশ্বিক বাণিজ্যের জন্য সার্বজনীন ক্রিপ্টোগ্রাফিক যাচাইকরণ স্তর',
                'content_markdown' => "### Long-Term Vision\n\nTo become the global standard for product authenticity, ensuring every manufactured unit from factory floor to retail shelf is cryptographically signed, verifiable offline, and sustainably compliant.",
                'sort_order' => 120,
            ],

            // B-O. Technical Documentation
            [
                'slug' => 'overview',
                'category' => 'technical',
                'section_key' => 'overview',
                'title_en' => 'Technical Overview & Architecture',
                'title_bn' => 'প্রযুক্তিগত ওভারভিউ এবং আর্কিটেকচার',
                'subtitle_en' => 'High-level system design of TriProof AI v2.1',
                'subtitle_bn' => 'ট্রাইপ্রুফ এআই v2.1 এর উচ্চ-স্তরের সিস্টেম ডিজাইন',
                'content_markdown' => "### Technical Architecture\n\nTriProof AI is built as a robust Laravel 12 monolith with a dedicated Flutter offline mobile verifier. It integrates atomic database locks, Ed25519 signing via sodium, and secure SQLite/SQLCipher edge storage.",
                'sort_order' => 130,
                'diagram_mermaid' => "graph TD\n    A[Factory Floor QA] -->|Generate Serials| B(Laravel 12 Backend)\n    B -->|SELECT ... FOR UPDATE| C[(MySQL Database)]\n    B -->|Ed25519 Sign| D[Signed Serial Registry]\n    D -->|Sync Blacklist / Keys| E[Flutter Mobile App]\n    E -->|Offline Scan| F[Instant Verdict]",
            ],
            [
                'slug' => 'feature_matrix',
                'category' => 'technical',
                'section_key' => 'feature_matrix',
                'title_en' => 'Live Feature Matrix & Subsystems',
                'title_bn' => 'লাইভ ফিচার ম্যাট্রিক্স এবং সাবসিস্টেম',
                'subtitle_en' => 'Operational status of core cryptographic and supply chain modules',
                'subtitle_bn' => 'মূল ক্রিপ্টোগ্রাফিক এবং সাপ্লাই চেইন মডিউলের অপারেশনাল স্ট্যাটাস',
                'content_markdown' => "### Subsystem Matrix\n\n- **Ed25519 Signing Engine:** Active & Verified\n- **Quota-Locked Purchase Orders:** Active\n- **Offline 50 KB Blacklist + 240K Bloom Filter:** Active\n- **Bilingual EN/বাংলা AI:** Live",
                'sort_order' => 140,
            ],
            [
                'slug' => 'architecture_diagram',
                'category' => 'technical',
                'section_key' => 'architecture_diagram',
                'title_en' => 'System Architecture Diagram',
                'title_bn' => 'সিস্টেম আর্কিটেকচার ডায়াগ্রাম',
                'subtitle_en' => 'Mermaid.js interactive architecture visualization',
                'subtitle_bn' => 'Mermaid.js ইন্টারেক্টিভ আর্কিটেকচার ভিজ্যুয়ালাইজেশন',
                'content_markdown' => "### Architecture Flow\n\n```mermaid\ngraph TD\n    Client[Web & Mobile Clients] -->|HTTPS /api/v1| Router[Laravel 12 Routing]\n    Router --> Middleware[EnsureDocsAccessible & Auth]\n    Middleware --> Controllers[API & Web Controllers]\n    Controllers --> Services[Domain Services]\n    Services --> DB[(MySQL / SQLCipher)]\n```",
                'diagram_mermaid' => "graph TD\n    Client[Web & Mobile Clients] -->|HTTPS /api/v1| Router[Laravel 12 Routing]\n    Router --> Middleware[EnsureDocsAccessible & Auth]\n    Middleware --> Controllers[API & Web Controllers]\n    Controllers --> Services[Domain Services]\n    Services --> DB[(MySQL / SQLCipher)]",
                'sort_order' => 150,
            ],
            [
                'slug' => 'data_flow_diagram',
                'category' => 'technical',
                'section_key' => 'data_flow_diagram',
                'title_en' => 'Data Flow & Verification Precedence',
                'title_bn' => 'ডেটা ফ্লো এবং যাচাইকরণ অগ্রাধিকার',
                'subtitle_en' => '7-stage verification precedence model',
                'subtitle_bn' => '৭-পর্যায়ের যাচাইকরণ অগ্রাধিকার মডেল',
                'content_markdown' => "### 7-Stage Verification Precedence\n1. **Signature Validity:** Cryptographic verification against public key.\n2. **Registration Check:** Presence in local/cloud serial registry.\n3. **Recall Status:** Instant check against active recall events.\n4. **Expiry Verification:** Comparison against factory and retail expiry windows.\n5. **Clone Detection:** Anomaly flagging for duplicate scan attempts.\n6. **Offline Cross-Check:** Edge verification via cached blacklist and bloom filter.\n7. **Ownership & Grace:** Final verdict assignment (Clean, Grace OK, Forged, etc.).",
                'diagram_mermaid' => "sequenceDiagram\n    participant App as Mobile App\n    participant API as Laravel Backend\n    participant DB as MySQL DB\n    App->>API: POST /api/v1/verify\n    API->>DB: SELECT FOR UPDATE serial\n    DB-->>API: Registry Row\n    API->>API: 7-Stage Verification Precedence\n    API-->>App: Verdict & Bilingual Message",
                'sort_order' => 160,
            ],
            [
                'slug' => 'tech_stack',
                'category' => 'technical',
                'section_key' => 'tech_stack',
                'title_en' => 'Technology Stack',
                'title_bn' => 'প্রযুক্তি স্ট্যাক',
                'subtitle_en' => 'Backend, mobile, frontend, and cryptography stack',
                'subtitle_bn' => 'ব্যাকএন্ড, মোবাইল, ফ্রন্টএন্ড এবং ক্রিপ্টোগ্রাফি স্ট্যাক',
                'content_markdown' => "### Tech Stack\n\n- **Backend:** Laravel 12, PHP 8.2+, MySQL 8.0+\n- **Mobile:** Flutter (Android / iOS), SQLCipher, Ed25519 Dart bindings\n- **Frontend:** Tailwind CSS 4, Blade templates, Vite\n- **PDF & Export:** barryvdh/laravel-dompdf",
                'sort_order' => 170,
            ],
            [
                'slug' => 'api_docs',
                'category' => 'technical',
                'section_key' => 'api_docs',
                'title_en' => 'API Documentation & Endpoints',
                'title_bn' => 'এপিআই ডকুমেন্টেশন এবং এন্ডপয়েন্ট',
                'subtitle_en' => '/api/v1 REST endpoints and /.well-known/triproof-key.json',
                'subtitle_bn' => '/api/v1 REST এন্ডপয়েন্ট এবং /.well-known/triproof-key.json',
                'content_markdown' => "### Core API Endpoints\n\n- `GET /api/v1/health` — System health check\n- `POST /api/v1/auth/login` — JWT authentication\n- `GET /api/v1/blacklist/delta` — Offline blacklist sync delta\n- `GET /.well-known/triproof-key.json` — Public signing key publication\n- `GET /v/{payload}` — Public QR passport verification",
                'sort_order' => 180,
            ],
            [
                'slug' => 'data_layer',
                'category' => 'technical',
                'section_key' => 'data_layer',
                'title_en' => 'Data Layer & Schema (14 Tables)',
                'title_bn' => 'ডেটা লেয়ার এবং স্কিমা (১৪টি টেবিল)',
                'subtitle_en' => 'Relational database schema with atomic locking',
                'subtitle_bn' => 'পারমাণবিক লকিং সহ রিলেশনাল ডাটাবেস স্কিমা',
                'content_markdown' => "### 14 Core Tables\n1. `users`\n2. `factories`\n3. `purchase_orders`\n4. `serial_registry`\n5. `scan_logs`\n6. `notifications`\n7. `ai_anomaly_reports`\n8. `recall_events`\n9. `signing_keys`\n10. `carton_aggregations`\n11. `blockchain_anchors`\n12. `device_attestations`\n13. `doc_sections`\n14. `doc_revisions` (+ `doc_team_members`)",
                'sort_order' => 190,
            ],
            [
                'slug' => 'ai_layer',
                'category' => 'technical',
                'section_key' => 'ai_layer',
                'title_en' => 'AI Layer & Bilingual Compliance',
                'title_bn' => 'এআই লেয়ার এবং দ্বিভাষিক কমপ্লায়েন্স',
                'subtitle_en' => 'Deterministic EN/বাংলা anomaly analysis and audit intelligence',
                'subtitle_bn' => 'নির্ধারক EN/বাংলা অসঙ্গতি বিশ্লেষণ এবং অডিট বুদ্ধিমত্তা',
                'content_markdown' => "### AI Layer\n\nProvides automated anomaly detection across scan logs and batch generation metrics with deterministic English and Bengali responses, ensuring compliance across international markets.",
                'sort_order' => 200,
            ],
            [
                'slug' => 'roadmap',
                'category' => 'technical',
                'section_key' => 'roadmap',
                'title_en' => 'Product Roadmap',
                'title_bn' => 'প্রোডাক্ট রোডম্যাপ',
                'subtitle_en' => 'Short, medium, and long-term milestones',
                'subtitle_bn' => 'স্বল্প, মধ্য এবং দীর্ঘমেয়াদী মাইলফলক',
                'content_markdown' => "### Roadmap\n\n- **v2.1 (Current):** Dynamic website trust sync, hardened QR normalization, live docs module.\n- **v2.2 (Q3):** Extended blockchain anchoring and multi-tenant key vault isolation.\n- **v3.0 (2027):** Autonomous IoT factory floor sensor integration.",
                'sort_order' => 210,
            ],
            [
                'slug' => 'performance',
                'category' => 'technical',
                'section_key' => 'performance',
                'title_en' => 'Performance & Scalability',
                'title_bn' => 'পারফরম্যান্স এবং স্কেলেবিলিটি',
                'subtitle_en' => 'Sub-50ms verification latency and optimized DB indexing',
                'subtitle_bn' => 'সাব-50ms যাচাইকরণ লেটেন্সি এবং অপ্টিমাইজড DB ইনডেক্সিং',
                'content_markdown' => "### Performance\n\nIndexed database queries, query caching via Redis/array, and optimized edge sync ensure sub-50ms verification response times even under high concurrent load.",
                'sort_order' => 220,
            ],
            [
                'slug' => 'security',
                'category' => 'technical',
                'section_key' => 'security',
                'title_en' => 'Security & Cryptographic Guarantees',
                'title_bn' => 'নিরাপত্তা এবং ক্রিপ্টোগ্রাফিক গ্যারান্টি',
                'subtitle_en' => 'Ed25519 signing, CSRF protection, rate limiting, and security headers',
                'subtitle_bn' => 'Ed25519 স্বাক্ষর, CSRF সুরক্ষা, রেট সীমিতকরণ এবং নিরাপত্তা শিরোনাম',
                'content_markdown' => "### Security Measures\n\n- Strict rate-limiting on verification and auth endpoints.\n- Cryptographic private key isolation.\n- Security headers middleware protecting against XSS and clickjacking.",
                'sort_order' => 230,
            ],
            [
                'slug' => 'analytics',
                'category' => 'operations',
                'section_key' => 'analytics',
                'title_en' => 'Live Analytics & System Telemetry',
                'title_bn' => 'লাইভ অ্যানালিটিক্স এবং সিস্টেম টেলিমিত্রি',
                'subtitle_en' => 'Real-time telemetry integration powering executive visibility',
                'subtitle_bn' => 'নির্বাহী দৃশ্যমানতা প্রদানকারী রিয়েল-টাইম টেলিমিত্রি ইন্টিগ্রেশন',
                'content_markdown' => "### Live Analytics\n\nTelemetry data is pulled live from the database via `DocsTelemetryService`, providing judges, investors, and operators with verified operational metrics.",
                'sort_order' => 240,
            ],
            [
                'slug' => 'changelog',
                'category' => 'technical',
                'section_key' => 'changelog',
                'title_en' => 'Changelog (v2.1 Release)',
                'title_bn' => 'চেঞ্জলগ (v2.1 রিলিজ)',
                'subtitle_en' => 'Detailed release notes for v2.1 dynamic trust sync',
                'subtitle_bn' => 'v2.1 গতিশীল ট্রাস্ট সিঙ্কের জন্য বিস্তারিত রিলিজ নোট',
                'content_markdown' => "### Changelog v2.1\n\n- Added dynamic website trust sync for Flutter mobile app.\n- Hardened smart-URL and `TP1:` QR normalization.\n- Created comprehensive admin-controlled `/docs` studio and public pitch deck.",
                'sort_order' => 250,
            ],
        ];

        foreach ($sections as $sec) {
            DocSection::firstOrCreate(
                ['slug' => $sec['slug']],
                $sec
            );
        }
    }
}
