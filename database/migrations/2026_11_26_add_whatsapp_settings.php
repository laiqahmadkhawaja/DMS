<?php
/**
 * DMS - Migration: Add Open-WA WhatsApp Settings
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

try {
    $db = Database::getConnection();

    $settings = [
        ['whatsapp', 'whatsapp_enabled', '1', 'boolean', 'Enable or disable WhatsApp messaging via Open-WA'],
        ['whatsapp', 'whatsapp_api_url', '', 'string', 'Open-WA REST API Base URL (e.g., https://wa.domain.com or http://ip:port)'],
        ['whatsapp', 'whatsapp_session_id', 'default', 'string', 'Open-WA WhatsApp Session ID or Name'],
        ['whatsapp', 'whatsapp_api_key', '', 'string', 'Open-WA API Secret Key (if configured on your server)'],
        ['whatsapp', 'whatsapp_auto_send_invoice', '0', 'boolean', 'Automatically send WhatsApp bill to customer on invoice finalization'],
        ['whatsapp', 'whatsapp_owner_phones', '', 'string', 'Comma-separated mobile numbers of owners/partners to receive daily closing reports'],
        ['whatsapp', 'whatsapp_default_country_code', '92', 'string', 'Default international country code for phone number formatting (e.g., 92 for Pakistan)']
    ];

    $stmt = $db->prepare("
        INSERT INTO `settings` (`setting_group`, `setting_key`, `setting_value`, `data_type`, `description`, `updated_at`)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)
    ");

    foreach ($settings as $s) {
        $stmt->execute($s);
    }

    echo "WhatsApp settings seeded successfully.\n";

} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
