/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `accounting_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_accounting_setting` (`company_id`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `account_code` varchar(50) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `account_type` varchar(50) NOT NULL,
  `normal_side` varchar(10) NOT NULL,
  `account_level` int(11) DEFAULT 1,
  `is_group` tinyint(1) DEFAULT 0,
  `allow_posting` tinyint(1) DEFAULT 1,
  `allow_cost_center` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_accounts_company_code` (`company_id`,`account_code`),
  KEY `idx_accounts_company` (`company_id`),
  KEY `idx_accounts_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_type` varchar(30) DEFAULT NULL,
  `actor_role_code` varchar(100) DEFAULT NULL,
  `support_session_id` char(36) DEFAULT NULL,
  `ticket_reference` varchar(150) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `scope_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scope_json`)),
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_json`)),
  `result` varchar(20) DEFAULT NULL,
  `request_id` char(36) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `module_name` varchar(100) NOT NULL,
  `record_id` bigint(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module_name`),
  KEY `idx_audit_support_session` (`support_session_id`),
  KEY `idx_audit_result_created` (`result`,`created_at`),
  KEY `idx_audit_actor_created` (`user_id`,`created_at`),
  KEY `idx_audit_company_created` (`company_id`,`created_at`),
  KEY `idx_audit_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_financial_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_financial_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `default_cash_financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `default_bank_financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `default_wallet_financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `default_cost_center_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_financial_settings` (`company_id`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_code` varchar(50) DEFAULT NULL,
  `branch_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `legal_name` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `registration_number` varchar(120) DEFAULT NULL,
  `tax_number` varchar(120) DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branches_company_branch_code_unique` (`company_id`,`branch_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cars`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cars` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `driver_id` bigint(20) unsigned DEFAULT NULL,
  `car_number` varchar(100) DEFAULT NULL,
  `plate_number` varchar(100) DEFAULT NULL,
  `weight_card_number` varchar(100) DEFAULT NULL,
  `gross_weight` decimal(15,3) DEFAULT 0.000,
  `deduction_weight` decimal(15,3) DEFAULT 0.000,
  `net_weight` decimal(15,3) DEFAULT 0.000,
  `transport_cost` decimal(15,3) DEFAULT 0.000,
  `extra_cost` decimal(15,3) DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `car_status` varchar(50) DEFAULT 'OPEN',
  `arrival_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `normalized_plate_number` varchar(100) DEFAULT NULL,
  `owner_name` varchar(255) DEFAULT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `owner_type` varchar(30) NOT NULL DEFAULT 'OTHER',
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `ownership_type` varchar(30) NOT NULL DEFAULT 'OTHER',
  `owner_party_type` varchar(30) DEFAULT NULL,
  `owner_party_id` bigint(20) unsigned DEFAULT NULL,
  `make_name` varchar(120) DEFAULT NULL,
  `model_name` varchar(120) DEFAULT NULL,
  `model_year` smallint(5) unsigned DEFAULT NULL,
  `external_source_system` varchar(120) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `migration_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_cars_branch` (`branch_id`),
  KEY `fk_cars_supplier` (`supplier_id`),
  KEY `fk_cars_driver` (`driver_id`),
  KEY `idx_cars_external_ref` (`company_id`,`external_source_system`,`external_reference`),
  CONSTRAINT `fk_cars_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_cars_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`),
  CONSTRAINT `fk_cars_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_return_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_return_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `return_id` bigint(20) unsigned NOT NULL,
  `source_invoice_line_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `item_type_snapshot` varchar(20) NOT NULL DEFAULT 'STOCK',
  `track_inventory_snapshot` tinyint(1) NOT NULL DEFAULT 1,
  `quantity` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `unit_code` varchar(20) NOT NULL DEFAULT 'KG',
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `unit_price_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total_before_vat` decimal(18,3) NOT NULL DEFAULT 0.000,
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `vat_percent` decimal(9,4) NOT NULL DEFAULT 0.0000,
  `vat_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_after_vat` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_total_before_vat` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_vat_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_total_after_vat` decimal(18,3) NOT NULL DEFAULT 0.000,
  `inventory_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_commercial_return_line` (`company_id`,`return_id`),
  KEY `idx_commercial_return_source_line` (`company_id`,`source_invoice_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_return_lot_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_return_lot_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `return_line_id` bigint(20) unsigned NOT NULL,
  `inventory_lot_id` bigint(20) unsigned NOT NULL,
  `qty_kg` decimal(18,3) NOT NULL,
  `unit_cost_per_kg` decimal(18,6) NOT NULL,
  `total_cost` decimal(18,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_return_lot_source` (`company_id`,`return_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `return_type` varchar(30) NOT NULL,
  `return_number` varchar(100) NOT NULL,
  `return_date` date NOT NULL,
  `source_invoice_id` bigint(20) unsigned NOT NULL,
  `party_id` bigint(20) unsigned NOT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `total_before_vat` decimal(18,3) NOT NULL DEFAULT 0.000,
  `vat_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_total_before_vat` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_vat_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_total_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `document_status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commercial_return_number` (`company_id`,`return_type`,`return_number`),
  KEY `idx_commercial_return_lookup` (`company_id`,`branch_id`,`return_type`,`return_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `companies` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `owner_name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `legal_name` varchar(255) DEFAULT NULL,
  `registration_number` varchar(120) DEFAULT NULL,
  `tax_number` varchar(120) DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `default_language` varchar(10) NOT NULL DEFAULT 'ar',
  `timezone` varchar(80) NOT NULL DEFAULT 'UTC',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_currencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `currency_code` varchar(10) NOT NULL,
  `is_base` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_currency` (`company_id`,`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_entitlement_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_entitlement_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `feature_code` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT NULL,
  `limit_value` bigint(20) unsigned DEFAULT NULL,
  `effective_from` datetime NOT NULL,
  `effective_to` datetime DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_override_company_feature_effective` (`company_id`,`feature_code`,`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_provisioning_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_provisioning_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(100) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `channel` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL,
  `company_id` bigint(20) DEFAULT NULL,
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_provisioning_requests_idempotency_key_unique` (`idempotency_key`),
  KEY `idx_provisioning_status_created` (`status`,`created_at`),
  KEY `company_provisioning_requests_company_id_index` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `print_company_name` varchar(255) DEFAULT NULL,
  `print_phone` varchar(50) DEFAULT NULL,
  `print_email` varchar(150) DEFAULT NULL,
  `print_city` varchar(100) DEFAULT NULL,
  `print_address` text DEFAULT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `commercial_register` varchar(100) DEFAULT NULL,
  `currency_name` varchar(50) DEFAULT 'ريال',
  `currency_code` varchar(10) DEFAULT 'SAR',
  `logo_path` varchar(500) DEFAULT NULL,
  `signature_path` varchar(500) DEFAULT NULL,
  `stamp_path` varchar(500) DEFAULT NULL,
  `invoice_footer` text DEFAULT NULL,
  `report_footer` text DEFAULT NULL,
  `primary_color` varchar(20) DEFAULT '#0B2A4A',
  `secondary_color` varchar(20) DEFAULT '#123D68',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `base_currency_code` varchar(10) DEFAULT NULL,
  `currency_decimal_places` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `tax_inclusive_prices` tinyint(1) NOT NULL DEFAULT 0,
  `default_sales_tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `default_purchase_tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_prefix` varchar(30) DEFAULT NULL,
  `purchase_prefix` varchar(30) DEFAULT NULL,
  `weighbridge_tolerance_kg` decimal(18,3) NOT NULL DEFAULT 5.000,
  `shipment_item_tolerance_kg` decimal(18,3) NOT NULL DEFAULT 5.000,
  `default_customer_id` bigint(20) unsigned DEFAULT NULL,
  `default_supplier_id` bigint(20) unsigned DEFAULT NULL,
  `default_customer_account_id` bigint(20) unsigned DEFAULT NULL,
  `default_supplier_account_id` bigint(20) unsigned DEFAULT NULL,
  `strict_item_accounting` tinyint(1) NOT NULL DEFAULT 1,
  `default_service_unit_code` varchar(20) NOT NULL DEFAULT 'UNIT',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_settings_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cost_centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cost_centers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `cost_center_code` varchar(50) NOT NULL,
  `cost_center_name` varchar(255) NOT NULL,
  `is_group` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cost_centers_company_code` (`company_id`,`cost_center_code`),
  KEY `idx_cost_centers_company_branch` (`company_id`,`branch_id`),
  KEY `idx_cost_centers_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `currencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `currency_code` varchar(10) NOT NULL,
  `currency_name` varchar(100) NOT NULL,
  `symbol` varchar(20) DEFAULT NULL,
  `decimal_places` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `currencies_currency_code_unique` (`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_branch_scope` (`company_id`,`customer_id`,`branch_id`),
  KEY `idx_customer_branch_lookup` (`company_id`,`branch_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `customer_code` varchar(50) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `opening_balance` decimal(15,3) DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `legal_name` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `registration_number` varchar(120) DEFAULT NULL,
  `tax_number` varchar(120) DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `scope_all_branches` tinyint(1) NOT NULL DEFAULT 0,
  `default_branch_id` bigint(20) unsigned DEFAULT NULL,
  `is_system_default` tinyint(1) NOT NULL DEFAULT 0,
  `ledger_account_id` bigint(20) unsigned DEFAULT NULL,
  `external_source_system` varchar(120) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `migration_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_code` (`customer_code`),
  KEY `fk_customers_branch` (`branch_id`),
  KEY `idx_customers_external_ref` (`company_id`,`external_source_system`,`external_reference`),
  CONSTRAINT `fk_customers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `entity_type` varchar(50) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'COMPLETED',
  `total_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `inserted_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `result_json` longtext DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import_batch_entity` (`company_id`,`entity_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_migration_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_migration_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `entity_code` varchar(60) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `source_system` varchar(120) DEFAULT NULL,
  `import_mode` varchar(30) NOT NULL DEFAULT 'UPSERT',
  `posting_mode` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `status` varchar(30) NOT NULL DEFAULT 'RUNNING',
  `total_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `valid_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `imported_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `options_json` longtext DEFAULT NULL,
  `started_by` bigint(20) unsigned DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_data_migration_batch_lookup` (`company_id`,`entity_code`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_migration_row_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_migration_row_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `row_number` int(10) unsigned DEFAULT NULL,
  `external_key` varchar(255) DEFAULT NULL,
  `row_status` varchar(30) NOT NULL,
  `message` text DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_data_migration_row_log` (`company_id`,`batch_id`,`row_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `document_family` varchar(30) NOT NULL,
  `document_type` varchar(40) NOT NULL,
  `document_year` smallint(5) unsigned NOT NULL,
  `prefix` varchar(40) NOT NULL,
  `next_number` bigint(20) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_sequence_scope` (`company_id`,`branch_id`,`document_family`,`document_type`,`document_year`),
  KEY `idx_document_sequence_lookup` (`company_id`,`document_family`,`document_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `drivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `drivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `driver_code` varchar(50) DEFAULT NULL,
  `driver_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `id_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `affiliation_type` varchar(30) NOT NULL DEFAULT 'INDEPENDENT',
  `affiliation_id` bigint(20) unsigned DEFAULT NULL,
  `affiliation_name` varchar(255) DEFAULT NULL,
  `license_number` varchar(120) DEFAULT NULL,
  `external_source_system` varchar(120) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `migration_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `driver_code` (`driver_code`),
  KEY `fk_drivers_branch` (`branch_id`),
  KEY `idx_drivers_external_ref` (`company_id`,`external_source_system`,`external_reference`),
  CONSTRAINT `fk_drivers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `entity_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entity_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `entity_type` varchar(30) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `address_type` varchar(30) NOT NULL DEFAULT 'LEGAL',
  `country_code` varchar(2) DEFAULT NULL,
  `short_address` varchar(100) DEFAULT NULL,
  `building_no` varchar(50) DEFAULT NULL,
  `street_name` varchar(200) DEFAULT NULL,
  `district` varchar(150) DEFAULT NULL,
  `city` varchar(150) DEFAULT NULL,
  `state_region` varchar(150) DEFAULT NULL,
  `postal_code` varchar(50) DEFAULT NULL,
  `additional_no` varchar(50) DEFAULT NULL,
  `unit_no` varchar(50) DEFAULT NULL,
  `address_line1` varchar(500) DEFAULT NULL,
  `address_line2` varchar(500) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entity_address_owner` (`company_id`,`entity_type`,`entity_id`),
  KEY `idx_entity_address_geo` (`company_id`,`country_code`,`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exchange_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `currency_code` varchar(10) NOT NULL,
  `rate_to_base` decimal(24,10) NOT NULL,
  `valid_from` date NOT NULL,
  `source` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exchange_rate_date` (`company_id`,`currency_code`,`valid_from`),
  KEY `idx_exchange_rate_company_date` (`company_id`,`valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_types` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `type_name` varchar(150) NOT NULL,
  `type_code` varchar(50) DEFAULT NULL,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `default_scope` varchar(50) DEFAULT 'GENERAL',
  `affects_cost` tinyint(1) DEFAULT 1,
  `usage_type` enum('GENERAL','SHIPMENT','BOTH') DEFAULT 'GENERAL',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) DEFAULT NULL,
  `expense_type_id` bigint(20) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `scope_type` varchar(50) DEFAULT 'GENERAL',
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `car_id` bigint(20) DEFAULT NULL,
  `purchase_invoice_id` bigint(20) DEFAULT NULL,
  `sales_invoice_id` bigint(20) DEFAULT NULL,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `driver_id` bigint(20) DEFAULT NULL,
  `worker_id` bigint(20) DEFAULT NULL,
  `amount` decimal(15,3) DEFAULT 0.000,
  `payment_status` varchar(30) DEFAULT 'PAID',
  `payment_method` varchar(50) DEFAULT NULL,
  `voucher_id` bigint(20) unsigned DEFAULT NULL,
  `expense_effect` varchar(50) DEFAULT 'COST',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) DEFAULT NULL,
  `foreign_amount` decimal(18,3) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feature_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feature_catalog` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `feature_code` varchar(100) NOT NULL,
  `feature_name` varchar(150) NOT NULL,
  `feature_type` varchar(20) NOT NULL DEFAULT 'BOOLEAN',
  `module_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feature_catalog_feature_code_unique` (`feature_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `account_code` varchar(80) NOT NULL,
  `account_name` varchar(200) NOT NULL,
  `account_type` varchar(30) NOT NULL,
  `gl_account_id` bigint(20) unsigned NOT NULL,
  `currency_code` varchar(10) NOT NULL,
  `bank_name` varchar(150) DEFAULT NULL,
  `account_number` varchar(120) DEFAULT NULL,
  `iban` varchar(120) DEFAULT NULL,
  `wallet_provider` varchar(120) DEFAULT NULL,
  `is_default_receipt` tinyint(1) NOT NULL DEFAULT 0,
  `is_default_payment` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_financial_account_code` (`company_id`,`account_code`),
  KEY `idx_financial_account_branch` (`company_id`,`branch_id`,`account_type`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_year_closures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_year_closures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `financial_year_id` bigint(20) unsigned NOT NULL,
  `closure_number` varchar(100) NOT NULL,
  `close_date` date NOT NULL,
  `revenue_total` decimal(18,3) NOT NULL DEFAULT 0.000,
  `expense_total` decimal(18,3) NOT NULL DEFAULT 0.000,
  `net_result` decimal(18,3) NOT NULL DEFAULT 0.000,
  `profit_loss_entry_id` bigint(20) unsigned DEFAULT NULL,
  `retained_earnings_entry_id` bigint(20) unsigned DEFAULT NULL,
  `next_financial_year_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'CLOSED',
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `reopened_by` bigint(20) unsigned DEFAULT NULL,
  `reopened_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fy_closure_number` (`company_id`,`closure_number`),
  KEY `idx_fy_closure_status` (`company_id`,`financial_year_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_years` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `year_name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_financial_year_company_name` (`company_id`,`year_name`),
  KEY `idx_financial_year_company_dates` (`company_id`,`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fixed_asset_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fixed_asset_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `category_code` varchar(50) NOT NULL,
  `category_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `depreciation_method` enum('STRAIGHT_LINE','DECLINING_BALANCE','NO_DEPRECIATION') NOT NULL DEFAULT 'STRAIGHT_LINE',
  `useful_life_months` int(10) unsigned DEFAULT NULL,
  `annual_depreciation_rate` decimal(8,4) DEFAULT NULL,
  `default_salvage_percentage` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `asset_account_id` bigint(20) unsigned DEFAULT NULL,
  `accumulated_depreciation_account_id` bigint(20) unsigned DEFAULT NULL,
  `depreciation_expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `disposal_gain_account_id` bigint(20) unsigned DEFAULT NULL,
  `disposal_loss_account_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_category_company_code` (`company_id`,`category_code`),
  KEY `idx_asset_category_company_active` (`company_id`,`is_active`),
  KEY `fixed_asset_categories_asset_account_id_index` (`asset_account_id`),
  KEY `fixed_asset_categories_accumulated_depreciation_account_id_index` (`accumulated_depreciation_account_id`),
  KEY `fixed_asset_categories_depreciation_expense_account_id_index` (`depreciation_expense_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fixed_asset_depreciation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fixed_asset_depreciation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `asset_id` bigint(20) unsigned NOT NULL,
  `depreciation_month` date NOT NULL,
  `opening_book_value` decimal(15,3) NOT NULL,
  `depreciation_amount` decimal(15,3) NOT NULL,
  `accumulated_depreciation` decimal(15,3) NOT NULL,
  `closing_book_value` decimal(15,3) NOT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('DRAFT','POSTED','REVERSED') NOT NULL DEFAULT 'DRAFT',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_month` (`asset_id`,`depreciation_month`),
  KEY `fixed_asset_depreciation_company_id_index` (`company_id`),
  KEY `fixed_asset_depreciation_journal_entry_id_index` (`journal_entry_id`),
  KEY `fixed_asset_depreciation_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fixed_asset_maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fixed_asset_maintenance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `asset_id` bigint(20) unsigned NOT NULL,
  `maintenance_date` date NOT NULL,
  `maintenance_type` varchar(100) DEFAULT NULL,
  `supplier_name` varchar(200) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `maintenance_cost` decimal(15,3) NOT NULL DEFAULT 0.000,
  `cost_treatment` enum('EXPENSE','CAPITALIZE') NOT NULL DEFAULT 'EXPENSE',
  `status` enum('DRAFT','APPROVED','PAID','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `payment_account_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `voucher_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset_maintenance_company_asset` (`company_id`,`asset_id`),
  KEY `fixed_asset_maintenance_maintenance_date_index` (`maintenance_date`),
  KEY `fixed_asset_maintenance_status_index` (`status`),
  KEY `fixed_asset_maintenance_journal_entry_id_index` (`journal_entry_id`),
  KEY `fixed_asset_maintenance_voucher_id_index` (`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fixed_asset_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fixed_asset_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `asset_id` bigint(20) unsigned NOT NULL,
  `movement_type` enum('PURCHASE','TRANSFER','MAINTENANCE','REVALUATION','DEPRECIATION','SALE','DISPOSAL') NOT NULL,
  `movement_date` date NOT NULL,
  `amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `from_branch_id` bigint(20) unsigned DEFAULT NULL,
  `to_branch_id` bigint(20) unsigned DEFAULT NULL,
  `worker_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fixed_asset_movements_asset_id_index` (`asset_id`),
  KEY `fixed_asset_movements_movement_type_index` (`movement_type`),
  KEY `fixed_asset_movements_movement_date_index` (`movement_date`),
  KEY `fixed_asset_movements_journal_entry_id_index` (`journal_entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fixed_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fixed_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `asset_code` varchar(100) NOT NULL,
  `asset_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `serial_number` varchar(150) DEFAULT NULL,
  `barcode` varchar(150) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `cost_center_id` bigint(20) unsigned DEFAULT NULL,
  `responsible_worker_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(15,3) NOT NULL DEFAULT 0.000,
  `salvage_value` decimal(15,3) NOT NULL DEFAULT 0.000,
  `current_book_value` decimal(15,3) NOT NULL DEFAULT 0.000,
  `depreciation_method` enum('STRAIGHT_LINE','DECLINING_BALANCE','NO_DEPRECIATION') NOT NULL DEFAULT 'STRAIGHT_LINE',
  `useful_life_months` int(11) DEFAULT NULL,
  `annual_depreciation_rate` decimal(8,4) DEFAULT NULL,
  `accumulated_depreciation` decimal(15,3) NOT NULL DEFAULT 0.000,
  `depreciation_start_date` date DEFAULT NULL,
  `last_depreciation_date` date DEFAULT NULL,
  `asset_account_id` bigint(20) unsigned DEFAULT NULL,
  `accumulated_account_id` bigint(20) unsigned DEFAULT NULL,
  `expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `asset_status` enum('ACTIVE','UNDER_MAINTENANCE','SOLD','DISPOSED') NOT NULL DEFAULT 'ACTIVE',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `acquisition_type` varchar(30) NOT NULL DEFAULT 'PURCHASE',
  `opening_accumulated_depreciation` decimal(18,3) NOT NULL DEFAULT 0.000,
  `opening_balance_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixed_assets_company_id_asset_code_unique` (`company_id`,`asset_code`),
  KEY `fixed_assets_category_id_index` (`category_id`),
  KEY `fixed_assets_branch_id_index` (`branch_id`),
  KEY `fixed_assets_asset_status_index` (`asset_status`),
  KEY `fixed_assets_purchase_date_index` (`purchase_date`),
  KEY `fixed_assets_responsible_worker_id_index` (`responsible_worker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_lot_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_lot_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `inventory_lot_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `movement_type` varchar(40) NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `movement_at` datetime NOT NULL,
  `qty_kg` decimal(18,3) NOT NULL,
  `unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lot_movement_item` (`company_id`,`branch_id`,`item_id`,`movement_at`),
  KEY `idx_lot_movement_lot` (`inventory_lot_id`,`movement_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_lots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_lot_id` bigint(20) unsigned DEFAULT NULL,
  `origin_lot_id` bigint(20) unsigned DEFAULT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_item_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_invoice_line_id` bigint(20) unsigned DEFAULT NULL,
  `lot_number` varchar(120) NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_operation_id` bigint(20) unsigned DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `qty_received_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `qty_remaining_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `qty_sold_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `allocated_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `lot_status` varchar(30) NOT NULL DEFAULT 'OPEN',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `opening_balance_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_lot_number` (`company_id`,`lot_number`),
  KEY `idx_inventory_lot_balance` (`company_id`,`branch_id`,`item_id`,`lot_status`),
  KEY `idx_inventory_lot_shipment_item` (`company_id`,`shipment_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_operation_costs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_operation_costs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `cost_type` varchar(60) NOT NULL,
  `amount` decimal(18,3) NOT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `base_amount` decimal(18,3) NOT NULL,
  `payment_status` varchar(20) NOT NULL DEFAULT 'UNPAID',
  `financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_operation_cost` (`company_id`,`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_operation_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_operation_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `line_type` enum('FROM','TO') NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_item_id` bigint(20) unsigned DEFAULT NULL,
  `input_lot_id` bigint(20) unsigned DEFAULT NULL,
  `output_lot_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(15,3) DEFAULT 0.000,
  `unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total_cost` decimal(15,3) DEFAULT 0.000,
  `allocation_percent` decimal(9,4) DEFAULT NULL,
  `market_value_per_kg` decimal(18,6) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv_op_lines_company` (`company_id`),
  KEY `idx_inv_op_lines_operation` (`operation_id`),
  KEY `idx_inv_op_lines_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_operation_lot_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_operation_lot_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `operation_line_id` bigint(20) unsigned DEFAULT NULL,
  `direction` varchar(10) NOT NULL,
  `source_lot_id` bigint(20) unsigned DEFAULT NULL,
  `produced_lot_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `qty_kg` decimal(18,3) NOT NULL,
  `unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invop_link_operation` (`company_id`,`operation_id`),
  KEY `idx_invop_link_source_lot` (`company_id`,`source_lot_id`),
  KEY `idx_invop_link_produced_lot` (`company_id`,`produced_lot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `from_branch_id` bigint(20) unsigned DEFAULT NULL,
  `to_branch_id` bigint(20) unsigned DEFAULT NULL,
  `operation_number` varchar(100) DEFAULT NULL,
  `operation_type` varchar(40) NOT NULL,
  `allocation_method` varchar(30) NOT NULL DEFAULT 'RELATIVE_VALUE',
  `operation_date` date NOT NULL,
  `input_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `output_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `loss_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `gain_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `loss_gain_reason` text DEFAULT NULL,
  `status` enum('DRAFT','APPROVED','POSTED','CANCELLED') DEFAULT 'DRAFT',
  `notes` text DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv_op_company` (`company_id`),
  KEY `idx_inv_op_type` (`operation_type`),
  KEY `idx_inv_op_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_shipment_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_shipment_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `invoice_type` varchar(20) NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `allocated_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `allocated_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_shipment` (`company_id`,`invoice_type`,`invoice_id`,`shipment_id`),
  KEY `idx_shipment_invoice_lookup` (`company_id`,`shipment_id`,`invoice_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `item_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `item_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `category_name` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `group_id` bigint(20) unsigned DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `category_code` varchar(60) DEFAULT NULL,
  `inventory_account_id` bigint(20) unsigned DEFAULT NULL,
  `sales_account_id` bigint(20) unsigned DEFAULT NULL,
  `cogs_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `sales_return_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_return_account_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `item_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `item_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `group_code` varchar(60) DEFAULT NULL,
  `group_name` varchar(180) NOT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `inventory_account_id` bigint(20) unsigned DEFAULT NULL,
  `sales_account_id` bigint(20) unsigned DEFAULT NULL,
  `cogs_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `sales_return_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_return_account_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_group_code` (`company_id`,`group_code`),
  KEY `idx_item_group_active` (`company_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `item_code` varchar(50) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_grade` varchar(100) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT 'طن',
  `default_buy_price` decimal(15,3) DEFAULT 0.000,
  `default_sell_price` decimal(15,3) DEFAULT 0.000,
  `min_sell_price` decimal(15,3) DEFAULT 0.000,
  `color_notes` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `group_id` bigint(20) unsigned DEFAULT NULL,
  `item_type` varchar(20) NOT NULL DEFAULT 'STOCK',
  `track_inventory` tinyint(1) NOT NULL DEFAULT 1,
  `allow_negative_stock` tinyint(1) NOT NULL DEFAULT 0,
  `can_purchase` tinyint(1) NOT NULL DEFAULT 1,
  `can_sell` tinyint(1) NOT NULL DEFAULT 1,
  `base_unit_code` varchar(20) NOT NULL DEFAULT 'KG',
  `commercial_unit_code` varchar(20) NOT NULL DEFAULT 'TON',
  `commercial_to_base_factor` decimal(18,6) NOT NULL DEFAULT 1000.000000,
  `costing_method` varchar(20) NOT NULL DEFAULT 'FIFO',
  `is_waste_item` tinyint(1) NOT NULL DEFAULT 0,
  `is_byproduct` tinyint(1) NOT NULL DEFAULT 0,
  `inventory_account_id` bigint(20) unsigned DEFAULT NULL,
  `sales_account_id` bigint(20) unsigned DEFAULT NULL,
  `cogs_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `sales_return_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_return_account_id` bigint(20) unsigned DEFAULT NULL,
  `external_source_system` varchar(120) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `migration_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_code` (`item_code`),
  KEY `fk_items_category` (`category_id`),
  KEY `idx_items_external_ref` (`company_id`,`external_source_system`,`external_reference`),
  CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `item_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `financial_year_id` bigint(20) unsigned DEFAULT NULL,
  `cost_center_id` bigint(20) unsigned DEFAULT NULL,
  `entry_number` varchar(100) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `reversal_of_id` bigint(20) unsigned DEFAULT NULL,
  `reversed_by_id` bigint(20) unsigned DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversal_reason` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(30) DEFAULT 'POSTED',
  `is_closing_entry` tinyint(1) NOT NULL DEFAULT 0,
  `is_system_generated` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal_company` (`company_id`),
  KEY `idx_journal_source` (`source_type`,`source_id`),
  KEY `idx_journal_entries_dimensions` (`company_id`,`branch_id`,`financial_year_id`),
  KEY `idx_journal_company_reference` (`company_id`,`reference_no`),
  KEY `idx_journal_company_source` (`company_id`,`source_type`,`source_id`),
  KEY `idx_journal_company_year_number` (`company_id`,`financial_year_id`,`entry_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `journal_entry_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entry_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `financial_year_id` bigint(20) unsigned DEFAULT NULL,
  `cost_center_id` bigint(20) unsigned DEFAULT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `party_type` varchar(30) DEFAULT NULL,
  `party_id` bigint(20) unsigned DEFAULT NULL,
  `debit` decimal(15,3) DEFAULT 0.000,
  `credit` decimal(15,3) DEFAULT 0.000,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `counterparty_branch_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `foreign_debit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `foreign_credit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `exchange_rate` decimal(24,10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal_lines_entry` (`journal_entry_id`),
  KEY `idx_journal_lines_account` (`account_id`),
  KEY `idx_journal_lines_dimensions` (`company_id`,`branch_id`,`financial_year_id`,`cost_center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `official_document_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `official_document_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `document_id` bigint(20) unsigned NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `official_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `official_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `doc_title` varchar(255) NOT NULL,
  `doc_type` varchar(100) DEFAULT 'GENERAL',
  `doc_content` longtext DEFAULT NULL,
  `status` varchar(50) DEFAULT 'DRAFT',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_branch` (`branch_id`),
  KEY `idx_type` (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `opening_balance_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `opening_balance_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `financial_year_id` bigint(20) unsigned NOT NULL,
  `opening_date` date NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `total_debit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_credit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opening_batch_number` (`company_id`,`batch_number`),
  KEY `idx_opening_batch_year` (`company_id`,`financial_year_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `opening_balance_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `opening_balance_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `cost_center_id` bigint(20) unsigned DEFAULT NULL,
  `party_type` varchar(30) DEFAULT NULL,
  `party_id` bigint(20) unsigned DEFAULT NULL,
  `debit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `credit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `currency_code` varchar(10) DEFAULT NULL,
  `foreign_debit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `foreign_credit` decimal(18,3) NOT NULL DEFAULT 0.000,
  `exchange_rate` decimal(24,10) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_opening_line_batch` (`company_id`,`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `opening_fixed_asset_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `opening_fixed_asset_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `asset_code` varchar(100) NOT NULL,
  `asset_name` varchar(255) NOT NULL,
  `acquisition_date` date DEFAULT NULL,
  `depreciation_start_date` date DEFAULT NULL,
  `historical_cost` decimal(18,3) NOT NULL,
  `opening_accumulated_depreciation` decimal(18,3) NOT NULL DEFAULT 0.000,
  `salvage_value` decimal(18,3) NOT NULL DEFAULT 0.000,
  `depreciation_method` varchar(30) NOT NULL DEFAULT 'STRAIGHT_LINE',
  `useful_life_months` int(11) DEFAULT NULL,
  `annual_depreciation_rate` decimal(9,4) DEFAULT NULL,
  `asset_account_id` bigint(20) unsigned DEFAULT NULL,
  `accumulated_account_id` bigint(20) unsigned DEFAULT NULL,
  `expense_account_id` bigint(20) unsigned DEFAULT NULL,
  `fixed_asset_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opening_asset_code` (`company_id`,`asset_code`),
  KEY `idx_opening_asset_batch` (`company_id`,`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `opening_inventory_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `opening_inventory_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `qty_kg` decimal(18,3) NOT NULL,
  `total_cost` decimal(18,3) NOT NULL,
  `unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `lot_number` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `inventory_lot_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_opening_inventory_batch` (`company_id`,`batch_id`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(150) NOT NULL,
  `permission_code` varchar(150) NOT NULL,
  `module_name` varchar(100) DEFAULT NULL,
  `permission_scope` varchar(20) NOT NULL DEFAULT 'COMPANY',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_code` (`permission_code`),
  KEY `idx_permissions_scope_code` (`permission_scope`,`permission_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plan_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plan_features` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` bigint(20) NOT NULL,
  `feature_code` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT NULL,
  `limit_value` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_feature` (`plan_id`,`feature_code`),
  KEY `idx_plan_feature_code` (`feature_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plans` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `plan_name` varchar(150) NOT NULL,
  `plan_code` varchar(50) NOT NULL,
  `monthly_price` decimal(15,3) DEFAULT 0.000,
  `yearly_price` decimal(15,3) DEFAULT NULL,
  `max_branches` int(11) DEFAULT 1,
  `max_users` int(11) DEFAULT 2,
  `max_cars` int(11) DEFAULT NULL,
  `max_invoices` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_code` (`plan_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_invoice_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `purchase_invoice_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(15,3) NOT NULL DEFAULT 0.000,
  `discount_amount` decimal(15,3) DEFAULT 0.000,
  `vat_percent` decimal(5,2) DEFAULT 0.00,
  `vat_amount` decimal(15,3) DEFAULT 0.000,
  `total_before_vat` decimal(15,3) DEFAULT 0.000,
  `total_after_vat` decimal(15,3) DEFAULT 0.000,
  `line_total` decimal(15,3) DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `tax_code_snapshot` varchar(50) DEFAULT NULL,
  `tax_name_snapshot` varchar(150) DEFAULT NULL,
  `tax_rate_snapshot` decimal(9,4) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `base_total_before_vat` decimal(18,3) DEFAULT NULL,
  `base_vat_amount` decimal(18,3) DEFAULT NULL,
  `base_total_after_vat` decimal(18,3) DEFAULT NULL,
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `item_type_snapshot` varchar(20) NOT NULL DEFAULT 'STOCK',
  `track_inventory_snapshot` tinyint(1) NOT NULL DEFAULT 1,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_item_id` bigint(20) unsigned DEFAULT NULL,
  `price_unit` varchar(20) NOT NULL DEFAULT 'KG',
  `unit_price_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `quantity` decimal(18,6) DEFAULT NULL,
  `unit_code` varchar(20) DEFAULT NULL,
  `unit_factor_to_base` decimal(18,6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_purchase_line_invoice` (`purchase_invoice_id`),
  KEY `fk_purchase_line_item` (`item_id`),
  KEY `fk_purchase_line_car` (`car_id`),
  CONSTRAINT `fk_purchase_line_car` FOREIGN KEY (`car_id`) REFERENCES `cars` (`id`),
  CONSTRAINT `fk_purchase_line_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_line_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_invoice_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_invoice_shipments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `purchase_invoice_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `allocated_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `supplier_amount_base` decimal(18,3) NOT NULL DEFAULT 0.000,
  `capitalized_cost_base` decimal(18,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_invoice_shipment` (`purchase_invoice_id`,`shipment_id`),
  KEY `idx_purchase_shipment_source` (`company_id`,`shipment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `total_qty` decimal(15,3) DEFAULT 0.000,
  `total_before_discount` decimal(15,3) DEFAULT 0.000,
  `discount_amount` decimal(15,3) DEFAULT 0.000,
  `vat_amount` decimal(15,3) DEFAULT 0.000,
  `total_before_vat` decimal(15,3) DEFAULT 0.000,
  `total_after_vat` decimal(15,3) DEFAULT 0.000,
  `transport_cost` decimal(15,3) DEFAULT 0.000,
  `extra_cost` decimal(15,3) DEFAULT 0.000,
  `total_amount` decimal(15,3) DEFAULT 0.000,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `payment_status` varchar(50) DEFAULT 'UNPAID',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `document_type` varchar(40) NOT NULL DEFAULT 'TAX_INVOICE',
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `base_total_before_vat` decimal(18,3) DEFAULT NULL,
  `base_vat_amount` decimal(18,3) DEFAULT NULL,
  `base_total_amount` decimal(18,3) DEFAULT NULL,
  `seller_snapshot_json` longtext DEFAULT NULL,
  `buyer_snapshot_json` longtext DEFAULT NULL,
  `tax_summary_json` longtext DEFAULT NULL,
  `source_mode` varchar(30) NOT NULL DEFAULT 'MANUAL',
  `source_shipment_count` int(10) unsigned NOT NULL DEFAULT 0,
  `document_status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `posted_at` datetime DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `external_source_system` varchar(120) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `migration_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `fk_purchase_branch` (`branch_id`),
  KEY `fk_purchase_supplier` (`supplier_id`),
  KEY `fk_purchase_car` (`car_id`),
  KEY `fk_purchase_user` (`created_by`),
  KEY `idx_purchase_invoices_external_ref` (`company_id`,`external_source_system`,`external_reference`),
  CONSTRAINT `fk_purchase_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_purchase_car` FOREIGN KEY (`car_id`) REFERENCES `cars` (`id`),
  CONSTRAINT `fk_purchase_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_purchase_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `unit_code` varchar(20) NOT NULL,
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `price_unit` varchar(10) NOT NULL,
  `unit_price` decimal(18,6) NOT NULL,
  `unit_price_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `discount_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `tax_code_snapshot` varchar(50) DEFAULT NULL,
  `tax_name_snapshot` varchar(150) DEFAULT NULL,
  `tax_rate_snapshot` decimal(9,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(18,3) NOT NULL,
  `tax_amount` decimal(18,3) NOT NULL,
  `total_amount` decimal(18,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_order_lines_parent` (`company_id`,`purchase_order_id`),
  KEY `purchase_order_lines_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `purchase_order_lines_item_id_foreign` (`item_id`),
  KEY `purchase_order_lines_tax_code_id_foreign` (`tax_code_id`),
  CONSTRAINT `purchase_order_lines_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `purchase_order_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `purchase_order_lines_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_lines_tax_code_id_foreign` FOREIGN KEY (`tax_code_id`) REFERENCES `tax_codes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `document_number` varchar(100) NOT NULL,
  `document_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `currency_code` varchar(10) NOT NULL,
  `exchange_rate` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `subtotal` decimal(18,3) NOT NULL DEFAULT 0.000,
  `discount_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `tax_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `tax_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tax_summary_json`)),
  `notes` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `converted_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_order_company_number` (`company_id`,`document_number`),
  KEY `idx_purchase_order_scope_status_date` (`company_id`,`branch_id`,`status`,`document_date`),
  KEY `idx_purchase_order_supplier_date` (`company_id`,`supplier_id`,`document_date`),
  KEY `purchase_orders_branch_id_foreign` (`branch_id`),
  KEY `purchase_orders_supplier_id_foreign` (`supplier_id`),
  KEY `purchase_orders_converted_invoice_id_foreign` (`converted_invoice_id`),
  KEY `purchase_orders_created_by_foreign` (`created_by`),
  KEY `purchase_orders_updated_by_foreign` (`updated_by`),
  CONSTRAINT `purchase_orders_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `purchase_orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `purchase_orders_converted_invoice_id_foreign` FOREIGN KEY (`converted_invoice_id`) REFERENCES `purchase_invoices` (`id`),
  CONSTRAINT `purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `purchase_orders_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `fk_role_permissions_role` (`role_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) NOT NULL,
  `role_code` varchar(100) NOT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_code` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_invoice_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `sales_invoice_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_item_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(15,3) DEFAULT 0.000,
  `discount_amount` decimal(15,3) DEFAULT 0.000,
  `vat_percent` decimal(5,2) DEFAULT 0.00,
  `vat_amount` decimal(15,3) DEFAULT 0.000,
  `total_before_vat` decimal(15,3) DEFAULT 0.000,
  `total_after_vat` decimal(15,3) DEFAULT 0.000,
  `line_total` decimal(15,3) DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `tax_code_snapshot` varchar(50) DEFAULT NULL,
  `tax_name_snapshot` varchar(150) DEFAULT NULL,
  `tax_rate_snapshot` decimal(9,4) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `base_total_before_vat` decimal(18,3) DEFAULT NULL,
  `base_vat_amount` decimal(18,3) DEFAULT NULL,
  `base_total_after_vat` decimal(18,3) DEFAULT NULL,
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `item_type_snapshot` varchar(20) NOT NULL DEFAULT 'STOCK',
  `track_inventory_snapshot` tinyint(1) NOT NULL DEFAULT 1,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `price_unit` varchar(20) NOT NULL DEFAULT 'KG',
  `unit_price_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `quantity` decimal(18,6) DEFAULT NULL,
  `unit_code` varchar(20) DEFAULT NULL,
  `unit_factor_to_base` decimal(18,6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sales_line_invoice` (`sales_invoice_id`),
  KEY `fk_sales_line_item` (`item_id`),
  CONSTRAINT `fk_sales_line_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_line_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `total_qty` decimal(15,3) DEFAULT 0.000,
  `total_before_discount` decimal(15,3) DEFAULT 0.000,
  `discount_amount` decimal(15,3) DEFAULT 0.000,
  `vat_amount` decimal(15,3) DEFAULT 0.000,
  `total_before_vat` decimal(15,3) DEFAULT 0.000,
  `total_after_vat` decimal(15,3) DEFAULT 0.000,
  `commission_amount` decimal(15,3) DEFAULT 0.000,
  `transport_cost` decimal(15,3) DEFAULT 0.000,
  `extra_cost` decimal(15,3) DEFAULT 0.000,
  `total_amount` decimal(15,3) DEFAULT 0.000,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `payment_status` varchar(50) DEFAULT 'UNPAID',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `document_type` varchar(40) NOT NULL DEFAULT 'TAX_INVOICE',
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `base_total_before_vat` decimal(18,3) DEFAULT NULL,
  `base_vat_amount` decimal(18,3) DEFAULT NULL,
  `base_total_amount` decimal(18,3) DEFAULT NULL,
  `seller_snapshot_json` longtext DEFAULT NULL,
  `buyer_snapshot_json` longtext DEFAULT NULL,
  `tax_summary_json` longtext DEFAULT NULL,
  `weighbridge_card_id` bigint(20) unsigned DEFAULT NULL,
  `total_loaded_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_empty_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_deduction_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_net_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `weight_variance_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `weight_status` varchar(30) NOT NULL DEFAULT 'NOT_WEIGHED',
  `document_status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `posted_at` datetime DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `external_source_system` varchar(120) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `migration_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `fk_sales_branch` (`branch_id`),
  KEY `fk_sales_customer` (`customer_id`),
  KEY `fk_sales_user` (`created_by`),
  KEY `idx_sales_invoices_external_ref` (`company_id`,`external_source_system`,`external_reference`),
  CONSTRAINT `fk_sales_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_line_lot_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_line_lot_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `sales_invoice_line_id` bigint(20) unsigned NOT NULL,
  `inventory_lot_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_item_id` bigint(20) unsigned DEFAULT NULL,
  `qty_kg` decimal(18,3) NOT NULL,
  `unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sale_lot_source_line` (`company_id`,`sales_invoice_line_id`),
  KEY `idx_sale_lot_source_lot` (`company_id`,`inventory_lot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_line_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_line_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `sales_invoice_line_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(15,3) NOT NULL DEFAULT 0.000,
  `cost_price` decimal(15,3) DEFAULT 0.000,
  `total_cost` decimal(15,3) DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sales_source_line` (`sales_invoice_line_id`),
  KEY `fk_sales_source_car` (`car_id`),
  CONSTRAINT `fk_sales_source_car` FOREIGN KEY (`car_id`) REFERENCES `cars` (`id`),
  CONSTRAINT `fk_sales_source_line` FOREIGN KEY (`sales_invoice_line_id`) REFERENCES `sales_invoice_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_quotation_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_quotation_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `sales_quotation_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `unit_code` varchar(20) NOT NULL,
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `price_unit` varchar(10) NOT NULL,
  `unit_price` decimal(18,6) NOT NULL,
  `unit_price_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `discount_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `tax_code_snapshot` varchar(50) DEFAULT NULL,
  `tax_name_snapshot` varchar(150) DEFAULT NULL,
  `tax_rate_snapshot` decimal(9,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(18,3) NOT NULL,
  `tax_amount` decimal(18,3) NOT NULL,
  `total_amount` decimal(18,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sales_quotation_lines_parent` (`company_id`,`sales_quotation_id`),
  KEY `sales_quotation_lines_sales_quotation_id_foreign` (`sales_quotation_id`),
  KEY `sales_quotation_lines_item_id_foreign` (`item_id`),
  KEY `sales_quotation_lines_tax_code_id_foreign` (`tax_code_id`),
  CONSTRAINT `sales_quotation_lines_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `sales_quotation_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `sales_quotation_lines_sales_quotation_id_foreign` FOREIGN KEY (`sales_quotation_id`) REFERENCES `sales_quotations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_quotation_lines_tax_code_id_foreign` FOREIGN KEY (`tax_code_id`) REFERENCES `tax_codes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_quotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_quotations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `document_number` varchar(100) NOT NULL,
  `document_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `currency_code` varchar(10) NOT NULL,
  `exchange_rate` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `subtotal` decimal(18,3) NOT NULL DEFAULT 0.000,
  `discount_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `tax_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `tax_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tax_summary_json`)),
  `notes` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `converted_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_quotation_company_number` (`company_id`,`document_number`),
  KEY `idx_sales_quotation_scope_status_date` (`company_id`,`branch_id`,`status`,`document_date`),
  KEY `idx_sales_quotation_customer_date` (`company_id`,`customer_id`,`document_date`),
  KEY `sales_quotations_branch_id_foreign` (`branch_id`),
  KEY `sales_quotations_customer_id_foreign` (`customer_id`),
  KEY `sales_quotations_converted_invoice_id_foreign` (`converted_invoice_id`),
  KEY `sales_quotations_created_by_foreign` (`created_by`),
  KEY `sales_quotations_updated_by_foreign` (`updated_by`),
  CONSTRAINT `sales_quotations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `sales_quotations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `sales_quotations_converted_invoice_id_foreign` FOREIGN KEY (`converted_invoice_id`) REFERENCES `sales_invoices` (`id`),
  CONSTRAINT `sales_quotations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_quotations_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `sales_quotations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
DROP TABLE IF EXISTS `shipment_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_adjustments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `shipment_item_id` bigint(20) unsigned NOT NULL,
  `adjustment_type` varchar(30) DEFAULT NULL,
  `qty` decimal(15,3) DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipment_cost_distribution`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_cost_distribution` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `shipment_item_id` bigint(20) unsigned NOT NULL,
  `expense_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(15,3) DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipment_costs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_costs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `expense_type_id` bigint(20) unsigned NOT NULL,
  `expense_date` date NOT NULL,
  `amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `payment_status` enum('UNPAID','PAID') DEFAULT 'PAID',
  `payment_method` varchar(50) DEFAULT NULL,
  `voucher_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `distributed` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) DEFAULT NULL,
  `foreign_amount` decimal(18,3) DEFAULT NULL,
  `beneficiary_type` varchar(30) DEFAULT NULL,
  `beneficiary_id` bigint(20) unsigned DEFAULT NULL,
  `beneficiary_name` varchar(255) DEFAULT NULL,
  `allocation_method` varchar(30) DEFAULT NULL,
  `cost_status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `capitalizable` tinyint(1) NOT NULL DEFAULT 1,
  `payee_type` varchar(30) DEFAULT NULL,
  `payee_id` bigint(20) unsigned DEFAULT NULL,
  `cost_code` varchar(60) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `recreated_from_cost_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_shipment` (`shipment_id`),
  KEY `idx_expense` (`expense_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipment_item_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_item_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `shipment_item_id` bigint(20) unsigned NOT NULL,
  `sales_invoice_line_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(15,3) DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `gross_weight` decimal(15,3) DEFAULT 0.000,
  `tare_weight` decimal(15,3) DEFAULT 0.000,
  `deduction_weight` decimal(15,3) DEFAULT 0.000,
  `net_weight` decimal(15,3) DEFAULT 0.000,
  `remaining_qty` decimal(15,3) DEFAULT 0.000,
  `sold_qty` decimal(15,3) DEFAULT 0.000,
  `unit_price` decimal(15,3) DEFAULT 0.000,
  `discount_amount` decimal(15,3) DEFAULT 0.000,
  `vat_percent` decimal(5,2) DEFAULT 0.00,
  `vat_amount` decimal(15,3) DEFAULT 0.000,
  `total_before_vat` decimal(15,3) DEFAULT 0.000,
  `total_after_vat` decimal(15,3) DEFAULT 0.000,
  `line_total` decimal(15,3) DEFAULT 0.000,
  `average_cost` decimal(15,3) DEFAULT 0.000,
  `extra_cost` decimal(15,3) DEFAULT 0.000,
  `final_cost` decimal(15,3) DEFAULT 0.000,
  `profit_amount` decimal(15,3) DEFAULT 0.000,
  `distributed_cost` decimal(15,3) DEFAULT 0.000,
  `profit` decimal(15,3) DEFAULT 0.000,
  `inventory_created` tinyint(1) DEFAULT 0,
  `purchase_line_id` bigint(20) unsigned DEFAULT NULL,
  `sorting_order` int(11) DEFAULT 0,
  `status` varchar(30) DEFAULT 'OPEN',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `remaining_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `sold_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `base_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `allocated_cost` decimal(18,3) NOT NULL DEFAULT 0.000,
  `final_unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `cost_share_percent` decimal(9,4) DEFAULT NULL,
  `manual_allocated_cost` decimal(18,3) DEFAULT NULL,
  `inventory_lot_id` bigint(20) unsigned DEFAULT NULL,
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `tax_code_snapshot` varchar(50) DEFAULT NULL,
  `tax_name_snapshot` varchar(150) DEFAULT NULL,
  `tax_rate_snapshot` decimal(9,4) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `base_total_before_vat` decimal(18,3) DEFAULT NULL,
  `base_vat_amount` decimal(18,3) DEFAULT NULL,
  `base_total_after_vat` decimal(18,3) DEFAULT NULL,
  `weighed_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `item_deduction_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `deduction_reason` varchar(255) DEFAULT NULL,
  `invoiced_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `gross_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `deduction_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `accepted_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `inventory_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `price_unit` varchar(20) NOT NULL DEFAULT 'KG',
  `unit_price_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  PRIMARY KEY (`id`),
  KEY `idx_shipment_lines_company` (`company_id`),
  KEY `idx_shipment_lines_shipment` (`shipment_id`),
  KEY `idx_shipment_lines_item` (`item_id`),
  CONSTRAINT `fk_shipment_lines_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipment_weights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_weights` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `weighbridge_card_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(30) NOT NULL,
  `effective_weight_type` varchar(20) NOT NULL,
  `weight_kg` decimal(18,3) NOT NULL,
  `recorded_at` datetime NOT NULL,
  `scale_name` varchar(120) DEFAULT NULL,
  `ticket_number` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `sales_invoice_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_weight_card_time` (`company_id`,`weighbridge_card_id`,`recorded_at`),
  KEY `idx_weight_shipment_type` (`company_id`,`shipment_id`,`event_type`),
  KEY `idx_weight_sales_type` (`company_id`,`sales_invoice_id`,`event_type`),
  KEY `idx_weight_card_active_effective_latest` (`weighbridge_card_id`,`cancelled_at`,`effective_weight_type`,`recorded_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `driver_id` bigint(20) unsigned DEFAULT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_number` varchar(100) DEFAULT NULL,
  `shipment_date` date NOT NULL,
  `plate_number` varchar(100) DEFAULT NULL,
  `weight_card_number` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'DRAFT',
  `total_gross_weight` decimal(15,3) DEFAULT 0.000,
  `total_tare_weight` decimal(15,3) DEFAULT 0.000,
  `total_deduction_weight` decimal(15,3) DEFAULT 0.000,
  `total_net_weight` decimal(15,3) DEFAULT 0.000,
  `total_before_discount` decimal(15,3) DEFAULT 0.000,
  `discount_amount` decimal(15,3) DEFAULT 0.000,
  `transport_cost` decimal(15,3) DEFAULT 0.000,
  `extra_cost` decimal(15,3) DEFAULT 0.000,
  `vat_percent` decimal(5,2) DEFAULT 0.00,
  `vat_amount` decimal(15,3) DEFAULT 0.000,
  `total_amount` decimal(15,3) DEFAULT 0.000,
  `purchase_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `finished_by` bigint(20) unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `distributed_cost` decimal(15,3) DEFAULT 0.000,
  `profit` decimal(15,3) DEFAULT 0.000,
  `flow_type` varchar(40) NOT NULL DEFAULT 'PURCHASE_INBOUND',
  `weighbridge_card_id` bigint(20) unsigned DEFAULT NULL,
  `total_loaded_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_empty_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_deduction_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `total_net_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `cost_allocation_method` varchar(30) NOT NULL DEFAULT 'RELATIVE_VALUE',
  `costing_status` varchar(30) NOT NULL DEFAULT 'PENDING',
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT 1.0000000000,
  `base_total_before_vat` decimal(18,3) DEFAULT NULL,
  `base_vat_amount` decimal(18,3) DEFAULT NULL,
  `base_total_amount` decimal(18,3) DEFAULT NULL,
  `tax_summary_json` longtext DEFAULT NULL,
  `invoice_status` varchar(30) NOT NULL DEFAULT 'UNINVOICED',
  `ready_for_invoice_at` timestamp NULL DEFAULT NULL,
  `shipment_type` varchar(20) NOT NULL DEFAULT 'PURCHASE',
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `commercial_status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `physical_net_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `accepted_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `item_deduction_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `weight_variance_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `ready_at` datetime DEFAULT NULL,
  `ready_by` bigint(20) unsigned DEFAULT NULL,
  `invoiced_at` datetime DEFAULT NULL,
  `invoiced_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_shipments_company` (`company_id`),
  KEY `idx_shipments_branch` (`branch_id`),
  KEY `idx_shipments_supplier` (`supplier_id`),
  KEY `idx_shipments_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_item_id` bigint(20) unsigned DEFAULT NULL,
  `movement_type` varchar(20) NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `movement_date` datetime NOT NULL,
  `qty` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(15,3) DEFAULT 0.000,
  `total_cost` decimal(15,3) DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `inventory_lot_id` bigint(20) unsigned DEFAULT NULL,
  `qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `unit_cost_per_kg` decimal(18,6) NOT NULL DEFAULT 0.000000,
  PRIMARY KEY (`id`),
  KEY `fk_stock_branch` (`branch_id`),
  KEY `fk_stock_item` (`item_id`),
  KEY `fk_stock_car` (`car_id`),
  KEY `fk_stock_user` (`created_by`),
  CONSTRAINT `fk_stock_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_stock_car` FOREIGN KEY (`car_id`) REFERENCES `cars` (`id`),
  CONSTRAINT `fk_stock_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_stock_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_entitlement_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_entitlement_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) NOT NULL,
  `company_id` bigint(20) NOT NULL,
  `plan_id` bigint(20) NOT NULL,
  `feature_code` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT NULL,
  `limit_value` bigint(20) unsigned DEFAULT NULL,
  `effective_from` datetime NOT NULL,
  `effective_to` datetime DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'PLAN',
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_snapshot_effective_source` (`subscription_id`,`feature_code`,`effective_from`,`source`),
  KEY `idx_snapshot_company_effective` (`company_id`,`effective_from`,`effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `subscription_id` bigint(20) DEFAULT NULL,
  `plan_id` bigint(20) DEFAULT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(15,3) NOT NULL DEFAULT 0.000,
  `discount_amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `tax_rate` decimal(8,3) NOT NULL DEFAULT 15.000,
  `tax_amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `paid_amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `remaining_amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `currency_code` varchar(10) NOT NULL DEFAULT 'SAR',
  `status` varchar(30) NOT NULL DEFAULT 'UNPAID',
  `billing_period` varchar(30) NOT NULL DEFAULT 'MONTHLY',
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_invoices_invoice_number_unique` (`invoice_number`),
  KEY `subscription_invoices_company_id_status_index` (`company_id`,`status`),
  KEY `subscription_invoices_invoice_date_due_date_index` (`invoice_date`,`due_date`),
  KEY `subscription_invoices_company_id_index` (`company_id`),
  KEY `subscription_invoices_subscription_id_index` (`subscription_id`),
  KEY `subscription_invoices_plan_id_index` (`plan_id`),
  KEY `subscription_invoices_status_index` (`status`),
  KEY `subscription_invoices_created_by_index` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `subscription_id` bigint(20) DEFAULT NULL,
  `invoice_id` bigint(20) DEFAULT NULL,
  `payment_number` varchar(100) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `currency_code` varchar(10) NOT NULL DEFAULT 'SAR',
  `payment_method` varchar(50) NOT NULL DEFAULT 'BANK_TRANSFER',
  `payment_status` varchar(30) NOT NULL DEFAULT 'PAID',
  `reference_number` varchar(150) DEFAULT NULL,
  `gateway_name` varchar(100) DEFAULT NULL,
  `gateway_transaction_id` varchar(200) DEFAULT NULL,
  `bank_name` varchar(150) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `received_by` bigint(20) DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_payments_payment_number_unique` (`payment_number`),
  KEY `subscription_payments_company_id_payment_date_index` (`company_id`,`payment_date`),
  KEY `subscription_payments_invoice_id_payment_status_index` (`invoice_id`,`payment_status`),
  KEY `subscription_payments_company_id_index` (`company_id`),
  KEY `subscription_payments_subscription_id_index` (`subscription_id`),
  KEY `subscription_payments_invoice_id_index` (`invoice_id`),
  KEY `subscription_payments_payment_method_index` (`payment_method`),
  KEY `subscription_payments_payment_status_index` (`payment_status`),
  KEY `subscription_payments_received_by_index` (`received_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscriptions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) NOT NULL,
  `plan_id` bigint(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` varchar(50) DEFAULT 'ACTIVE',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_subscriptions_company_lifecycle_dates` (`company_id`,`status`,`start_date`,`end_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sulb_document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sulb_document_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `document_type` varchar(50) NOT NULL,
  `document_year` smallint(5) unsigned NOT NULL,
  `next_number` bigint(20) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sulb_document_sequence` (`company_id`,`branch_id`,`document_type`,`document_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_branch_scope` (`company_id`,`supplier_id`,`branch_id`),
  KEY `idx_supplier_branch_lookup` (`company_id`,`branch_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_code` varchar(50) DEFAULT NULL,
  `supplier_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `opening_balance` decimal(15,3) DEFAULT 0.000,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `legal_name` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `registration_number` varchar(120) DEFAULT NULL,
  `tax_number` varchar(120) DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `scope_all_branches` tinyint(1) NOT NULL DEFAULT 0,
  `default_branch_id` bigint(20) unsigned DEFAULT NULL,
  `is_system_default` tinyint(1) NOT NULL DEFAULT 0,
  `ledger_account_id` bigint(20) unsigned DEFAULT NULL,
  `external_source_system` varchar(120) DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `migration_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `fk_suppliers_branch` (`branch_id`),
  KEY `idx_suppliers_external_ref` (`company_id`,`external_source_system`,`external_reference`),
  CONSTRAINT `fk_suppliers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `support_session_id` char(36) NOT NULL,
  `platform_user_id` bigint(20) NOT NULL,
  `company_id` bigint(20) NOT NULL,
  `branch_id` bigint(20) DEFAULT NULL,
  `personal_access_token_id` bigint(20) unsigned DEFAULT NULL,
  `access_level` varchar(20) NOT NULL DEFAULT 'READ_ONLY',
  `capabilities_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`capabilities_json`)),
  `reason` text NOT NULL,
  `ticket_reference` varchar(150) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `started_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `support_sessions_support_session_id_unique` (`support_session_id`),
  KEY `idx_support_company_status` (`company_id`,`status`),
  KEY `idx_support_actor_created` (`platform_user_id`,`created_at`),
  KEY `idx_support_status_expires` (`status`,`expires_at`),
  KEY `idx_support_token` (`personal_access_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tax_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tax_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `tax_code` varchar(50) NOT NULL,
  `tax_name` varchar(150) NOT NULL,
  `tax_type` varchar(30) NOT NULL DEFAULT 'TAX',
  `rate` decimal(9,4) NOT NULL DEFAULT 0.0000,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `sales_tax_account_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_tax_account_id` bigint(20) unsigned DEFAULT NULL,
  `is_zero_rated` tinyint(1) NOT NULL DEFAULT 0,
  `is_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `is_out_of_scope` tinyint(1) NOT NULL DEFAULT 0,
  `is_default_sales` tinyint(1) NOT NULL DEFAULT 0,
  `is_default_purchase` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_tax_code` (`company_id`,`tax_code`),
  KEY `idx_company_tax_validity` (`company_id`,`is_active`,`valid_from`,`valid_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_permission_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permission_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `effect` varchar(10) NOT NULL DEFAULT 'ALLOW',
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_permission_override` (`company_id`,`user_id`,`permission_id`),
  KEY `idx_user_permission_effect` (`company_id`,`user_id`,`effect`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `fk_user_roles_user` (`user_id`),
  KEY `fk_user_roles_role` (`role_id`),
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `fk_users_branch` (`branch_id`),
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voucher_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `voucher_types` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) DEFAULT NULL,
  `type_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vouchers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) DEFAULT NULL,
  `voucher_type_id` bigint(20) DEFAULT NULL,
  `voucher_number` varchar(100) DEFAULT NULL,
  `voucher_date` date DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) DEFAULT NULL,
  `amount` decimal(15,3) DEFAULT 0.000,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `cash_account_id` bigint(20) unsigned DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `financial_account_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(24,10) DEFAULT NULL,
  `foreign_amount` decimal(18,3) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weighbridge_card_item_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `weighbridge_card_item_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `weighbridge_card_id` bigint(20) unsigned NOT NULL,
  `shipment_item_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `gross_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `deduction_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `accepted_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `deduction_reason` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wb_alloc_shipment_card` (`company_id`,`shipment_id`,`weighbridge_card_id`),
  KEY `idx_wb_alloc_item` (`company_id`,`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weighbridge_card_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `weighbridge_card_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `weighbridge_card_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `weighed_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `deduction_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `accepted_qty_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `deduction_reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wb_items_card` (`company_id`,`weighbridge_card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weighbridge_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `weighbridge_cards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `car_id` bigint(20) unsigned DEFAULT NULL,
  `card_number` varchar(120) NOT NULL,
  `flow_type` varchar(40) NOT NULL DEFAULT 'PURCHASE_INBOUND',
  `status` varchar(30) NOT NULL DEFAULT 'OPEN',
  `loaded_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `empty_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `deduction_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `net_weight_kg` decimal(18,3) NOT NULL DEFAULT 0.000,
  `scale_name` varchar(120) DEFAULT NULL,
  `external_ticket_number` varchar(120) DEFAULT NULL,
  `opened_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `opened_by` bigint(20) unsigned DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `sales_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `movement_direction` varchar(20) NOT NULL DEFAULT 'IN',
  `party_type` varchar(30) DEFAULT NULL,
  `party_id` bigint(20) unsigned DEFAULT NULL,
  `driver_id` bigint(20) unsigned DEFAULT NULL,
  `party_name_snapshot` varchar(255) DEFAULT NULL,
  `driver_name_snapshot` varchar(255) DEFAULT NULL,
  `plate_number_snapshot` varchar(120) DEFAULT NULL,
  `weight_tolerance_kg` decimal(18,3) NOT NULL DEFAULT 5.000,
  `direction` varchar(20) NOT NULL DEFAULT 'INBOUND',
  `entry_at` datetime DEFAULT NULL,
  `exit_at` datetime DEFAULT NULL,
  `duration_minutes` int(10) unsigned DEFAULT NULL,
  `plate_snapshot` varchar(120) DEFAULT NULL,
  `driver_snapshot` varchar(255) DEFAULT NULL,
  `party_snapshot` varchar(255) DEFAULT NULL,
  `unassigned_reason` varchar(500) DEFAULT NULL,
  `linked_at` datetime DEFAULT NULL,
  `linked_by` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `item_code_snapshot` varchar(80) DEFAULT NULL,
  `item_name_snapshot` varchar(255) DEFAULT NULL,
  `item_assigned_at` datetime DEFAULT NULL,
  `item_assigned_by` bigint(20) unsigned DEFAULT NULL,
  `item_assignment_note` varchar(500) DEFAULT NULL,
  `transport_mode` varchar(30) NOT NULL DEFAULT 'VEHICLE',
  `transport_label` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weighbridge_card_number` (`company_id`,`card_number`),
  UNIQUE KEY `uq_weighbridge_sales_invoice` (`company_id`,`sales_invoice_id`),
  KEY `idx_weighbridge_status` (`company_id`,`branch_id`,`status`),
  KEY `idx_wb_sales_invoice` (`company_id`,`sales_invoice_id`),
  KEY `idx_weighbridge_shipment_cards` (`company_id`,`shipment_id`,`status`),
  KEY `idx_wb_item_status` (`company_id`,`item_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worker_attendance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `worker_id` bigint(20) unsigned NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `work_hours` decimal(6,2) DEFAULT 0.00,
  `overtime_hours` decimal(6,2) DEFAULT 0.00,
  `status` varchar(30) DEFAULT 'PRESENT',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_worker_attendance_worker` (`worker_id`),
  KEY `idx_worker_attendance_date` (`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worker_commissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `worker_id` bigint(20) unsigned NOT NULL,
  `shipment_id` bigint(20) unsigned DEFAULT NULL,
  `sales_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `commission_date` date NOT NULL,
  `amount` decimal(15,3) DEFAULT 0.000,
  `status` varchar(20) DEFAULT 'PENDING',
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `voucher_id` bigint(20) unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_worker_commissions_worker` (`worker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worker_loans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `worker_id` bigint(20) unsigned NOT NULL,
  `loan_date` date NOT NULL,
  `amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `payment_method` varchar(50) DEFAULT NULL,
  `voucher_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `salary_run_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_worker_loans_company` (`company_id`),
  KEY `idx_worker_loans_worker` (`worker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_salary_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worker_salary_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `salary_run_id` bigint(20) unsigned NOT NULL,
  `worker_id` bigint(20) unsigned NOT NULL,
  `salary_type` varchar(30) NOT NULL DEFAULT 'MONTHLY',
  `rate_amount` decimal(15,3) NOT NULL DEFAULT 0.000,
  `work_units` decimal(15,3) NOT NULL DEFAULT 1.000,
  `basic_amount` decimal(15,3) DEFAULT 0.000,
  `overtime_amount` decimal(15,3) DEFAULT 0.000,
  `allowance_amount` decimal(15,3) DEFAULT 0.000,
  `bonus_amount` decimal(15,3) DEFAULT 0.000,
  `commission_amount` decimal(15,3) DEFAULT 0.000,
  `loan_deduction` decimal(15,3) DEFAULT 0.000,
  `other_deduction` decimal(15,3) DEFAULT 0.000,
  `net_salary` decimal(15,3) DEFAULT 0.000,
  `payment_status` varchar(30) DEFAULT 'UNPAID',
  `payment_method` varchar(50) DEFAULT NULL,
  `voucher_id` bigint(20) unsigned DEFAULT NULL,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_salary_lines_run` (`salary_run_id`),
  KEY `idx_salary_lines_worker` (`worker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_salary_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worker_salary_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `salary_month` date NOT NULL,
  `run_number` varchar(100) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'DRAFT',
  `total_amount` decimal(15,3) DEFAULT 0.000,
  `paid_amount` decimal(15,3) DEFAULT 0.000,
  `journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salary_run_company_month` (`company_id`,`salary_month`),
  KEY `idx_salary_runs_company` (`company_id`),
  KEY `idx_salary_runs_month` (`salary_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_no` varchar(50) DEFAULT NULL,
  `salary_type` varchar(20) DEFAULT 'MONTHLY',
  `salary_rate` decimal(15,3) DEFAULT 0.000,
  `hire_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `job_title` varchar(150) DEFAULT NULL,
  `department` varchar(150) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `iqama_number` varchar(50) DEFAULT NULL,
  `passport_number` varchar(50) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `bank_name` varchar(150) DEFAULT NULL,
  `iban` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(150) DEFAULT NULL,
  `emergency_phone` varchar(50) DEFAULT NULL,
  `contract_type` varchar(30) DEFAULT 'FULL_TIME',
  `worker_status` varchar(30) DEFAULT 'ACTIVE',
  `photo` varchar(255) DEFAULT NULL,
  `company_id` bigint(20) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `worker_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2026_07_12_140407_create_fixed_asset_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2026_07_12_140417_create_fixed_assets_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2026_07_12_140425_create_fixed_asset_movements_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_07_12_140431_create_fixed_asset_depreciation_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_07_12_140437_create_fixed_asset_maintenance_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_07_22_205130_create_subscription_invoices_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_07_22_205139_create_subscription_payments_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_07_23_103759_add_yearly_price_to_plans_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_07_29_000001_create_financial_years_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_07_29_000002_create_cost_centers_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_07_29_000003_extend_journals_for_dimensions',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_07_29_000004_fix_branch_code_unique_per_company',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_06_11_153129_create_personal_access_tokens_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_06_000005_fix_roles_branch_permissions',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_12_000007_final_rbac_tenant_branch_hardening',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_08_13_000009_complete_accounting_core',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_08_13_000010_journal_entry_guardrails',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_08_13_000011_inventory_lots_weighbridge_core',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_08_13_000012_inventory_operations_reports_imports',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_08_13_000013_global_erp_financial_architecture',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_08_15_000014_flexible_logistics_weighbridge_invoicing',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_08_16_000014_sulb_scrap_enterprise_core',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_08_17_000015_sulb_enterprise_accounting_completion',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_08_17_000016_sulb_migration_center',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_08_17_000017_sulb_weighbridge_material_workflow',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_08_24_000018_add_wave0b_query_indexes',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_08_24_000019_create_missing_session_and_queue_runtime_tables',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_08_24_000020_add_subscription_lifecycle_lookup_index',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_08_24_000021_create_entitlement_control_plane',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_08_24_000022_create_company_provisioning_requests',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_08_24_000023_harden_platform_support_and_privileged_audit',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_08_25_000024_ensure_company_owner_role_baseline',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_08_29_000025_create_sales_quotations_and_purchase_orders',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'0001_01_01_000000_create_users_table',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'0001_01_01_000001_create_cache_table',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'0001_01_01_000002_create_jobs_table',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_06_20_163416_create_shipments_table',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_08_12_000008_fix_store_inventory_permission',34);
