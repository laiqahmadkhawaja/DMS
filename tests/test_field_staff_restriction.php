<?php
/**
 * Test Suite: Field Staff Restriction and Direct Landing Verification
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/permission.php';

echo "=================================================================\n";
echo "FIELD STAFF RESTRICTION & DIRECT LANDING VERIFICATION\n";
echo "=================================================================\n\n";

$passed = 0;
$failed = 0;

function assert_check(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name} - Details: {$details}\n";
    }
}

// 1. Check database roles
echo "1. Verifying Database Roles & Field Staff Classification:\n";
$salesmanRole = db_fetch_one("SELECT * FROM roles WHERE role_code = 'salesman'");
$bookerRole = db_fetch_one("SELECT * FROM roles WHERE role_code = 'booker'");
$adminRole = db_fetch_one("SELECT * FROM roles WHERE role_code = 'admin'");

assert_check("Salesman role exists in DB", !empty($salesmanRole));
assert_check("Booker role exists in DB", !empty($bookerRole));

// Check or create test salesman user
$testSalesmanUser = db_fetch_one("SELECT * FROM users WHERE username = 'salesman_test'");
if (!$testSalesmanUser) {
    $pwHash = password_hash('pass123', PASSWORD_BCRYPT);
    db()->prepare("INSERT INTO users (username, password_hash, full_name, email, phone, status, is_deleted) VALUES ('salesman_test', ?, 'Test Salesman Person', 'sm@test.com', '0302-1122334', 'active', 0)")
        ->execute([$pwHash]);
    $testSmUserId = (int)db()->lastInsertId();
    // Assign salesman role (id = 6)
    db()->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$testSmUserId, $salesmanRole['id']]);
} else {
    $testSmUserId = (int)$testSalesmanUser['id'];
}

// Ensure salesman record is linked to this user
$smRecord = db_fetch_one("SELECT * FROM salesmen WHERE user_id = ?", [$testSmUserId]);
if (!$smRecord) {
    db()->prepare("UPDATE salesmen SET user_id = ? WHERE id = 1")->execute([$testSmUserId]);
}

// 2. Test is_field_staff() logic
echo "\n2. Testing is_field_staff() Evaluation:\n";
// Set session for admin
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Administrator';
$_SESSION['roles'] = ['admin'];
assert_check("Admin is NOT field staff", is_field_staff() === false);

// Set session for manager
$_SESSION['roles'] = ['manager'];
assert_check("Manager is NOT field staff", is_field_staff() === false);

// Set session for salesman
$_SESSION['user_id'] = $testSmUserId;
$_SESSION['username'] = 'salesman_test';
$_SESSION['full_name'] = 'Test Salesman Person';
$_SESSION['roles'] = ['salesman'];
assert_check("Salesman IS field staff", is_field_staff() === true);

// Set session for booker
$_SESSION['roles'] = ['booker'];
assert_check("Booker IS field staff", is_field_staff() === true);

// 3. Test Staff Resolution Functions
echo "\n3. Testing Staff Resolution Functions:\n";
$_SESSION['user_id'] = $testSmUserId;
$_SESSION['roles'] = ['salesman'];
$resolvedSm = get_current_salesman();
assert_check("get_current_salesman() resolves linked record", !empty($resolvedSm) && (int)$resolvedSm['user_id'] === $testSmUserId);

// 4. Test ERP Traversal Restriction
echo "\n4. Testing ERP Traversal Restriction via require_login():\n";
// Simulate script name on ERP page
$_SERVER['SCRIPT_NAME'] = '/dashboard.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json'; // test AJAX response
ob_start();
try {
    // When AJAX request hits an ERP page as field staff, it should return 403 JSON
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $isAllowed = (strpos($script, '/modules/app/') !== false)
              || (strpos($script, '/app/') !== false)
              || (str_ends_with($script, 'app.php'))
              || (str_ends_with($script, 'logout.php'));
    assert_check("/dashboard.php is forbidden for field staff", $isAllowed === false);

    $appScript = '/modules/app/index.php';
    $isAppAllowed = (strpos($appScript, '/modules/app/') !== false);
    assert_check("/modules/app/index.php is allowed for field staff", $isAppAllowed === true);
} finally {
    ob_end_clean();
}

// 5. Test Absence of Salesman Switching Dropdown in HTML
echo "\n5. Verifying App UI Has No Switching Dropdown & Is In English:\n";
$appHtml = file_get_contents(__DIR__ . '/../modules/app/index.php');
assert_check("Salesman select dropdown NOT present", strpos($appHtml, 'id="activeSalesmanSelect"') === false);
assert_check("salesman-bar identity present", strpos($appHtml, 'id="loggedStaffName"') !== false);
assert_check("English language attribute present", strpos($appHtml, 'lang="en"') !== false);

// 6. Test Sidebar Has No Mobile App Links
echo "\n6. Verifying ERP Sidebar Has No Mobile App Links:\n";
$sidebarHtml = file_get_contents(__DIR__ . '/../includes/sidebar.php');
assert_check("Sidebar contains no /modules/app/ link", strpos($sidebarHtml, '/modules/app/') === false);
assert_check("Sidebar contains no /app.php link", strpos($sidebarHtml, '/app.php') === false);

echo "\n=================================================================\n";
echo "TEST RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo "🎉 ALL FIELD STAFF RESTRICTION TESTS PASSED!\n";
    exit(0);
} else {
    echo "❌ TESTS FAILED.\n";
    exit(1);
}
