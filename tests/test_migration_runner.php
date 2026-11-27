<?php
/**
 * Test Suite: Automatic Migration Engine & Schema Synchronizer
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/migration_runner.php';

echo "========================================================\n";
echo "TESTING AUTOMATIC DATABASE MIGRATION ENGINE\n";
echo "========================================================\n\n";

$db = Database::getConnection();

// 1. Test ensure_migrations_table()
echo "1. Testing ensure_migrations_table():\n";
ensure_migrations_table();
$tblExists = db_fetch_val("SHOW TABLES LIKE 'migrations'");
assert($tblExists === 'migrations', "migrations table must exist");
echo "  [PASS] migrations table exists and verified\n\n";

// 2. Test get_available_migration_files()
echo "2. Testing get_available_migration_files():\n";
$available = get_available_migration_files();
assert(count($available) >= 12, "Should have at least 12 migration files");
assert(in_array('2026_11_21_add_dual_discount_columns.php', $available), "Should contain dual discount migration");
assert(in_array('2026_11_27_mobile_app.php', $available), "Should contain mobile app migration");
echo "  [PASS] Successfully discovered " . count($available) . " migration files on disk\n\n";

// 3. Test get_applied_migrations()
echo "3. Testing get_applied_migrations():\n";
$applied = get_applied_migrations();
assert(count($applied) >= 12, "Should have all standard migrations applied");
echo "  [PASS] " . count($applied) . " migrations recorded as successfully applied in database\n\n";

// 4. Test get_pending_migrations() when up to date
echo "4. Testing get_pending_migrations() (Zero pending when up to date):\n";
$pending = get_pending_migrations();
assert(empty($pending), "Pending migrations should be empty when fully up to date");
echo "  [PASS] Zero pending migrations detected when schema is synchronized\n\n";

// 5. Test Automatic Detection & Execution of a New Migration File
echo "5. Testing Automatic Execution of New Migration File (Simulating update/pull):\n";
$mockFile = DMS_MIGRATIONS_PATH . DIRECTORY_SEPARATOR . '2099_12_31_test_auto_migration_mock.php';
$mockContent = <<<'PHP'
<?php
define('DMS_RUNNING', true);
require_once __DIR__ . '/../../config/config.php';

$db = Database::getConnection();
$db->exec("
    CREATE TABLE IF NOT EXISTS `_test_auto_migration_log` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `test_val` varchar(50) NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$db->exec("INSERT INTO `_test_auto_migration_log` (`test_val`) VALUES ('auto_migration_worked')");
echo "[SUCCESS] Auto migration mock executed cleanly.\n";
PHP;

file_put_contents($mockFile, $mockContent);
echo "  [+] Created simulated new migration: " . basename($mockFile) . "\n";

// Verify it is immediately detected as pending
$newPending = get_pending_migrations();
assert(in_array(basename($mockFile), $newPending), "New migration file must be detected as pending");
echo "  [PASS] New migration detected as pending by engine\n";

// Run auto-migration check (force re-check in same process)
$executed = auto_run_pending_migrations(true);
assert($executed === true, "auto_run_pending_migrations() must return true when new migrations are applied");

// Verify that the table was created and row inserted
$chkTable = db_fetch_val("SHOW TABLES LIKE '_test_auto_migration_log'");
assert($chkTable === '_test_auto_migration_log', "Mock migration table must have been created automatically");

$rowVal = db_fetch_val("SELECT test_val FROM _test_auto_migration_log LIMIT 1");
assert($rowVal === 'auto_migration_worked', "Mock migration data must be present");

// Verify it was recorded in migrations table
$recorded = db_fetch_val("SELECT status FROM migrations WHERE migration = ?", [basename($mockFile)]);
assert($recorded === 'success', "Migration must be recorded in migrations table as success");
echo "  [PASS] New migration was automatically executed and recorded in database!\n\n";

// 6. Cleanup mock test artifacts
echo "6. Cleaning up mock test artifacts:\n";
@unlink($mockFile);
$db->exec("DROP TABLE IF EXISTS `_test_auto_migration_log`");
$db->exec("DELETE FROM `migrations` WHERE `migration` = '2099_12_31_test_auto_migration_mock.php'");

$finalPending = get_pending_migrations();
assert(empty($finalPending), "Pending migrations should be empty after cleanup");
echo "  [PASS] Cleaned up temporary test artifacts. System restored cleanly.\n\n";

echo "========================================================\n";
echo "ALL AUTOMATIC MIGRATION ENGINE TESTS PASSED (100%)!\n";
echo "========================================================\n";
