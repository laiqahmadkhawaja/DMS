<?php
/**
 * DMS - Distribution Management System
 * Partners Action Handler (Add, Edit, Toggle, Delete)
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

require_login();
require_permission('partners_manage');

$action = sanitize_input($_POST['action'] ?? $_GET['action'] ?? '');
$redirectUrl = APP_URL . '/modules/partners/index.php';

// Only allowed in partnership mode
if (!is_partner_expense_sharing_enabled()) {
    set_flash('danger', 'Partner operations are disabled in Standard Business Mode. Enable Partnership Mode in Settings first.');
    redirect($redirectUrl);
}

try {
    switch ($action) {
        case 'add':
            $partnerName = sanitize_input($_POST['partner_name'] ?? '');
            $urduName    = sanitize_input($_POST['urdu_name'] ?? '');
            $role        = sanitize_input($_POST['role'] ?? 'partner');
            $supplierId  = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $phone       = sanitize_input($_POST['phone'] ?? '');
            $shareType   = sanitize_input($_POST['expense_share_type'] ?? 'equal');
            $shareVal    = (float)($_POST['expense_share_value'] ?? 0);
            $openingBal  = (float)($_POST['opening_balance'] ?? 0);
            $notes       = sanitize_input($_POST['notes'] ?? '');
            $isActive    = isset($_POST['is_active']) ? 1 : 1;

            if (empty($partnerName)) {
                set_flash('danger', 'Please enter a valid partner name.');
                redirect($redirectUrl);
            }

            $newId = create_partner([
                'partner_name'        => $partnerName,
                'urdu_name'           => $urduName,
                'role'                => $role,
                'supplier_id'         => $supplierId,
                'phone'               => $phone,
                'expense_share_type'  => $shareType,
                'expense_share_value' => $shareVal,
                'opening_balance'     => $openingBal,
                'notes'               => $notes,
                'is_active'           => $isActive
            ]);

            set_flash('success', "New partner <strong>" . htmlspecialchars($partnerName) . "</strong> added successfully with Khata initialized.");
            redirect($redirectUrl);
            break;

        case 'edit':
            $partnerId   = (int)($_POST['partner_id'] ?? 0);
            $partnerName = sanitize_input($_POST['partner_name'] ?? '');
            $urduName    = sanitize_input($_POST['urdu_name'] ?? '');
            $role        = sanitize_input($_POST['role'] ?? 'partner');
            $supplierId  = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $phone       = sanitize_input($_POST['phone'] ?? '');
            $shareType   = sanitize_input($_POST['expense_share_type'] ?? 'equal');
            $shareVal    = (float)($_POST['expense_share_value'] ?? 0);
            $notes       = sanitize_input($_POST['notes'] ?? '');
            $isActive    = isset($_POST['is_active']) ? 1 : 0;

            if ($partnerId <= 0 || empty($partnerName)) {
                set_flash('danger', 'Invalid partner details provided.');
                redirect($redirectUrl);
            }

            update_partner($partnerId, [
                'partner_name'        => $partnerName,
                'urdu_name'           => $urduName,
                'role'                => $role,
                'supplier_id'         => $supplierId,
                'phone'               => $phone,
                'expense_share_type'  => $shareType,
                'expense_share_value' => $shareVal,
                'notes'               => $notes,
                'is_active'           => $isActive
            ]);

            // Re-audit balance to make sure it's 100% synchronized
            recalculate_partner_balance($partnerId);

            set_flash('success', "Partner <strong>" . htmlspecialchars($partnerName) . "</strong> details updated successfully.");
            redirect($redirectUrl);
            break;

        case 'toggle_status':
            $partnerId = (int)($_GET['id'] ?? 0);
            $partner = get_partner_by_id($partnerId);
            if (!$partner) {
                set_flash('danger', 'Partner not found.');
                redirect($redirectUrl);
            }

            $newStatus = $partner['is_active'] ? 0 : 1;
            db()->prepare("UPDATE partners SET is_active = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newStatus, $partnerId]);

            $statusLabel = $newStatus ? 'activated' : 'deactivated';
            set_flash('info', "Partner <strong>" . htmlspecialchars($partner['partner_name']) . "</strong> {$statusLabel}.");
            redirect($redirectUrl);
            break;

        case 'delete':
            $partnerId = (int)($_POST['partner_id'] ?? $_GET['id'] ?? 0);
            $partner = get_partner_by_id($partnerId);
            if (!$partner) {
                set_flash('danger', 'Partner not found.');
                redirect($redirectUrl);
            }

            $txnCount = (int)db_fetch_val("SELECT COUNT(*) FROM partner_transactions WHERE partner_id = ?", [$partnerId]);
            if ($txnCount > 0) {
                // To preserve accounting ledger integrity, do not delete if transactions exist
                db()->prepare("UPDATE partners SET is_active = 0, updated_at = NOW() WHERE id = ?")
                    ->execute([$partnerId]);
                set_flash('warning', "Partner <strong>" . htmlspecialchars($partner['partner_name']) . "</strong> has {$txnCount} accounting records. To protect financial integrity, the partner was deactivated instead of deleted.");
            } else {
                db()->prepare("DELETE FROM partners WHERE id = ?")->execute([$partnerId]);
                set_flash('success', "Partner <strong>" . htmlspecialchars($partner['partner_name']) . "</strong> deleted successfully.");
            }

            redirect($redirectUrl);
            break;

        default:
            set_flash('danger', 'Invalid action requested.');
            redirect($redirectUrl);
            break;
    }
} catch (Exception $e) {
    set_flash('danger', 'Error: ' . $e->getMessage());
    redirect($redirectUrl);
}
