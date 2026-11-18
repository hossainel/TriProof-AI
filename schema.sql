-- TriProof AI canonical MySQL schema (mirrors database/migrations).

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `ai_anomaly_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_orders_po_number_unique` (`po_number`),
  KEY `purchase_orders_buyer_id_created_at_index` (`buyer_id`,`created_at`),
  KEY `purchase_orders_factory_id_created_at_index` (`factory_id`,`created_at`),
  KEY `purchase_orders_factory_id_index` (`factory_id`),
  CONSTRAINT `purchase_orders_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recall_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scan_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `serial_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;
