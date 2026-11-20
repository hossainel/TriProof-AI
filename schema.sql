-- TriProof AI canonical MySQL schema (mirrors database/migrations; V2).

/*M!999999\- enable the sandbox mode */

/*!40101 SET NAMES utf8mb4 */;
DROP TABLE IF EXISTS `ai_anomaly_reports`;
CREATE TABLE `ai_anomaly_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `batch_id` varchar(32) DEFAULT NULL,
  `anomaly_type` enum('clone_cluster','ghost_production_attempt','rework_gap','defect_cluster') NOT NULL,
  `explanation_en` text NOT NULL,
  `explanation_bn` text NOT NULL,
  `affected_units_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`affected_units_json`)),
  `status` varchar(24) NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_reports_scope_idx` (`po_id`,`batch_id`,`anomaly_type`,`status`),
  KEY `ai_anomaly_reports_batch_id_index` (`batch_id`),
  KEY `ai_anomaly_reports_anomaly_type_index` (`anomaly_type`),
  KEY `ai_anomaly_reports_status_index` (`status`),
  CONSTRAINT `ai_anomaly_reports_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `blockchain_anchors`;
CREATE TABLE `blockchain_anchors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `anchor_date` date NOT NULL,
  `batch_merkle_root` char(64) NOT NULL,
  `po_ids_json` longtext NOT NULL,
  `provider` enum('opentimestamps','polygon_rpc','local_ledger') NOT NULL,
  `tx_hash_or_receipt` text NOT NULL,
  `status` enum('pending','anchored','verified') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `blockchain_anchors_anchor_date_status_index` (`anchor_date`,`status`),
  KEY `blockchain_anchors_batch_merkle_root_index` (`batch_merkle_root`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `cache`;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `cache_locks`;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `carton_aggregations`;
CREATE TABLE `carton_aggregations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `carton_serial` varchar(64) NOT NULL,
  `po_id` bigint(20) unsigned NOT NULL,
  `factory_id` varchar(32) NOT NULL,
  `batch_id` varchar(32) NOT NULL,
  `unit_count` int(10) unsigned NOT NULL,
  `carton_merkle_root` char(64) NOT NULL,
  `carton_signature` varchar(255) NOT NULL,
  `status` enum('sealed','in_transit','customs_cleared','opened') NOT NULL DEFAULT 'sealed',
  `sealed_by_device` varchar(128) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `carton_aggregations_carton_serial_unique` (`carton_serial`),
  KEY `carton_aggregations_po_id_batch_id_index` (`po_id`,`batch_id`),
  KEY `carton_aggregations_status_index` (`status`),
  CONSTRAINT `carton_aggregations_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `carton_units`;
CREATE TABLE `carton_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `carton_id` bigint(20) unsigned NOT NULL,
  `serial_registry_id` bigint(20) unsigned NOT NULL,
  `leaf_index` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `carton_units_carton_id_leaf_index_unique` (`carton_id`,`leaf_index`),
  UNIQUE KEY `carton_units_serial_registry_id_unique` (`serial_registry_id`),
  CONSTRAINT `carton_units_carton_id_foreign` FOREIGN KEY (`carton_id`) REFERENCES `carton_aggregations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `carton_units_serial_registry_id_foreign` FOREIGN KEY (`serial_registry_id`) REFERENCES `serial_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `device_attestations`;
CREATE TABLE `device_attestations` (
  `device_token` varchar(128) NOT NULL,
  `device_public_key_b64` varchar(88) NOT NULL,
  `play_integrity_verdict` varchar(64) DEFAULT NULL,
  `last_scan_seq` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`device_token`),
  KEY `device_attestations_last_scan_seq_index` (`last_scan_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `job_batches`;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_token` varchar(128) NOT NULL,
  `outer_serial` varchar(128) DEFAULT NULL,
  `severity` enum('info','warning','critical_clone','recall_alert') NOT NULL,
  `title_en` varchar(255) NOT NULL,
  `message_en` text NOT NULL,
  `title_bn` varchar(255) NOT NULL,
  `message_bn` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_device_token_is_read_created_at_index` (`device_token`,`is_read`,`created_at`),
  KEY `notifications_created_at_index` (`created_at`),
  KEY `notifications_device_token_index` (`device_token`),
  KEY `notifications_outer_serial_index` (`outer_serial`),
  KEY `notifications_severity_index` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `purchase_orders`;
CREATE TABLE `purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_number` varchar(64) NOT NULL,
  `buyer_id` bigint(20) unsigned NOT NULL,
  `factory_id` varchar(32) NOT NULL,
  `authorized_quota` int(10) unsigned NOT NULL,
  `generated_count` int(10) unsigned NOT NULL DEFAULT 0,
  `factory_expiry_days` smallint(5) unsigned NOT NULL DEFAULT 45,
  `retail_expiry_days` smallint(5) unsigned NOT NULL DEFAULT 365,
  `merkle_root_hash` char(64) DEFAULT NULL,
  `dpp_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dpp_metadata`)),
  `dpp_digest` char(8) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_orders_po_number_unique` (`po_number`),
  KEY `purchase_orders_buyer_id_created_at_index` (`buyer_id`,`created_at`),
  KEY `purchase_orders_factory_id_created_at_index` (`factory_id`,`created_at`),
  KEY `purchase_orders_factory_id_index` (`factory_id`),
  KEY `purchase_orders_dpp_digest_index` (`dpp_digest`),
  CONSTRAINT `purchase_orders_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `recall_events`;
CREATE TABLE `recall_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `factory_id` varchar(32) NOT NULL,
  `machine_id` varchar(32) NOT NULL,
  `batch_id` varchar(32) NOT NULL,
  `affected_count` int(10) unsigned NOT NULL DEFAULT 0,
  `reason` text DEFAULT NULL,
  `triggered_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recall_events_po_id_foreign` (`po_id`),
  KEY `recall_events_triggered_by_foreign` (`triggered_by`),
  KEY `recall_events_factory_id_machine_id_batch_id_index` (`factory_id`,`machine_id`,`batch_id`),
  KEY `recall_events_created_at_index` (`created_at`),
  CONSTRAINT `recall_events_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recall_events_triggered_by_foreign` FOREIGN KEY (`triggered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `scan_logs`;
CREATE TABLE `scan_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sync_uuid` varchar(64) NOT NULL,
  `outer_serial` varchar(128) NOT NULL,
  `inner_serial` varchar(255) NOT NULL,
  `device_token` varchar(128) NOT NULL,
  `user_role` enum('global_buyer','factory_qa','admin') NOT NULL,
  `scan_mode` enum('offline_synced','online_live') NOT NULL DEFAULT 'offline_synced',
  `offline_verdict` varchar(32) DEFAULT NULL,
  `cloud_cross_check_verdict` enum('verified_clean','owner_repeat_ok','store_grace_ok','clone_detected','recalled_batch','expired','forged_signature') NOT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `scanned_at` datetime NOT NULL,
  `synced_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_logs_sync_uuid_unique` (`sync_uuid`),
  KEY `scan_logs_outer_serial_scanned_at_index` (`outer_serial`,`scanned_at`),
  KEY `scan_logs_device_token_created_at_index` (`device_token`,`created_at`),
  KEY `scan_logs_outer_serial_index` (`outer_serial`),
  KEY `scan_logs_device_token_index` (`device_token`),
  KEY `scan_logs_cloud_cross_check_verdict_index` (`cloud_cross_check_verdict`),
  KEY `scan_logs_scanned_at_index` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `serial_registry`;
CREATE TABLE `serial_registry` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned NOT NULL,
  `outer_serial` varchar(128) NOT NULL,
  `inner_serial` varchar(255) NOT NULL,
  `unit_hash` char(64) NOT NULL,
  `factory_id` varchar(32) NOT NULL,
  `machine_id` varchar(32) NOT NULL,
  `batch_id` varchar(32) NOT NULL,
  `unit_number` int(10) unsigned NOT NULL,
  `prod_date` date NOT NULL,
  `exp_date` date NOT NULL,
  `status` enum('generated','packed','in_transit','claimed_genuine','cloned_flagged','recalled') NOT NULL DEFAULT 'generated',
  `claimed_device_token` varchar(128) DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `claimed_gps` varchar(64) DEFAULT NULL,
  `rework_parent_serial` varchar(128) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `serial_registry_outer_serial_unique` (`outer_serial`),
  UNIQUE KEY `serial_registry_unit_hash_unique` (`unit_hash`),
  KEY `serial_registry_factory_id_machine_id_batch_id_index` (`factory_id`,`machine_id`,`batch_id`),
  KEY `serial_registry_batch_unit_idx` (`factory_id`,`machine_id`,`batch_id`,`unit_number`),
  KEY `serial_registry_po_id_status_index` (`po_id`,`status`),
  KEY `serial_registry_exp_date_index` (`exp_date`),
  KEY `serial_registry_status_index` (`status`),
  KEY `serial_registry_claimed_device_token_index` (`claimed_device_token`),
  KEY `serial_registry_rework_parent_serial_index` (`rework_parent_serial`),
  CONSTRAINT `serial_registry_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `signing_keys`;
CREATE TABLE `signing_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key_version` char(4) NOT NULL,
  `buyer_id` bigint(20) unsigned DEFAULT NULL,
  `public_key_b64` varchar(88) NOT NULL,
  `private_key_encrypted` text NOT NULL,
  `status` enum('active','verify_only','revoked') NOT NULL DEFAULT 'verify_only',
  `activated_at` timestamp NULL DEFAULT NULL,
  `retired_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `signing_keys_key_version_unique` (`key_version`),
  KEY `signing_keys_status_key_version_index` (`status`,`key_version`),
  KEY `signing_keys_buyer_id_index` (`buyer_id`),
  CONSTRAINT `signing_keys_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('global_buyer','factory_qa','admin') NOT NULL DEFAULT 'global_buyer',
  `language_pref` enum('en','bn') NOT NULL DEFAULT 'en',
  `factory_id` varchar(32) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_index` (`role`),
  KEY `users_factory_id_index` (`factory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `factories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name_en` varchar(160) NOT NULL,
  `name_bn` varchar(160) DEFAULT NULL,
  `location` varchar(160) DEFAULT NULL,
  `contact_email` varchar(160) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `factories_code_unique` (`code`),
  KEY `factories_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_settings` (
  `key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doc_sections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(120) NOT NULL,
  `category` enum('pitch_deck','technical','operations') NOT NULL,
  `section_key` varchar(120) NOT NULL,
  `title_en` varchar(255) NOT NULL,
  `title_bn` varchar(255) DEFAULT NULL,
  `subtitle_en` varchar(255) DEFAULT NULL,
  `subtitle_bn` varchar(255) DEFAULT NULL,
  `content_markdown` longtext NOT NULL,
  `draft_markdown` longtext DEFAULT NULL,
  `diagram_mermaid` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('published','draft') NOT NULL DEFAULT 'published',
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_sections_slug_unique` (`slug`),
  KEY `doc_sections_sort_order_index` (`sort_order`),
  KEY `doc_sections_updated_by_foreign` (`updated_by`),
  CONSTRAINT `doc_sections_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doc_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doc_section_id` bigint(20) unsigned DEFAULT NULL,
  `version_label` varchar(64) NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `change_note` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `doc_revisions_doc_section_id_created_at_index` (`doc_section_id`,`created_at`),
  KEY `doc_revisions_created_by_foreign` (`created_by`),
  CONSTRAINT `doc_revisions_doc_section_id_foreign` FOREIGN KEY (`doc_section_id`) REFERENCES `doc_sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `doc_revisions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `doc_team_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) NOT NULL,
  `role_title` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `bio_short` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `doc_team_members_sort_order_index` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
