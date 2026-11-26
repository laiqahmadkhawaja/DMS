<?php
/**
 * Migration: Add system_features table with whatsapp ENUM('on', 'off') and customer whatsapp_status
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

try {
    $db = Database::getConnection();

    // 1. Create system_features table with status ENUM('on', 'off')
    $db->exec("
        CREATE TABLE IF NOT EXISTS `system_features` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `feature_key` varchar(50) NOT NULL,
            `feature_name` varchar(100) NOT NULL,
            `status` enum('on','off') NOT NULL DEFAULT 'on',
            `description` varchar(255) DEFAULT NULL,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_feature_key` (`feature_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Insert or update default row for whatsapp feature
    $stmt = $db->prepare("
        INSERT INTO `system_features` (`feature_key`, `feature_name`, `status`, `description`)
        VALUES ('whatsapp', 'WhatsApp Gateway & Automated Messaging', 'on', 'Client-wise master switch to enable or disable entire WhatsApp integration (ENUM: on, off)')
        ON DUPLICATE KEY UPDATE `feature_name` = VALUES(`feature_name`), `description` = VALUES(`description`)
    ");
    $stmt->execute();

    // 2. Add whatsapp_status ENUM('on', 'off') to customers table if not exists
    $colCheck = $db->query("SHOW COLUMNS FROM `customers` LIKE 'whatsapp_status'")->fetch();
    if (!$colCheck) {
        $db->exec("ALTER TABLE `customers` ADD COLUMN `whatsapp_status` enum('on','off') NOT NULL DEFAULT 'on' AFTER `notes`");
        echo "[SUCCESS] Added whatsapp_status ENUM('on', 'off') to customers table.\n";
    }

    // 3. Ensure settings table has whatsapp_feature_status
    $stmtSettings = $db->prepare("
        INSERT INTO `settings` (`setting_group`, `setting_key`, `setting_value`, `data_type`, `description`, `updated_at`)
        VALUES ('system', 'whatsapp_feature_status', 'on', 'string', 'Client-wise Master WhatsApp Integration Switch (on/off)', NOW())
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)
    ");
    $stmtSettings->execute();

    echo "[SUCCESS] Migration 2026_11_26_add_system_features_whatsapp_enum executed successfully.\n";

} catch (Exception $e) {
    echo "[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
