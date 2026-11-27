<?php
/**
 * DMS - Distribution Management System
 * Migration CLI & Web Runner
 *
 * Usage via CLI:
 *   php database/migrate.php           # Run pending migrations
 *   php database/migrate.php --status  # View migrations status table
 *   php database/migrate.php --force   # Force run pending migrations
 *
 * Usage via Web:
 *   http://localhost/DMS/database/migrate.php
 */

define('DMS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/migration_runner.php';

$isCli = (php_sapi_name() === 'cli');

// ==========================================
// CLI MODE
// ==========================================
if ($isCli) {
    $action = $argv[1] ?? '--run';

    echo "\n========================================================\n";
    echo "  DMS DATABASE MIGRATION ENGINE (آٹومیٹک مائیگریشن رنر)  \n";
    echo "========================================================\n";

    ensure_migrations_table();

    if ($action === '--status' || $action === '-s') {
        $available = get_available_migration_files();
        $appliedRows = db_fetch_all("SELECT * FROM migrations ORDER BY id ASC");
        $appliedMap = [];
        foreach ($appliedRows as $row) {
            $appliedMap[$row['migration']] = $row;
        }

        echo sprintf("%-48s | %-6s | %-8s | %-19s\n", "Migration File", "Batch", "Status", "Applied At");
        echo str_repeat("-", 90) . "\n";

        $pendingCount = 0;
        foreach ($available as $file) {
            if (isset($appliedMap[$file])) {
                $m = $appliedMap[$file];
                $statusStr = ($m['status'] === 'success') ? "\033[32mSUCCESS\033[0m" : "\033[31mFAILED\033[0m";
                echo sprintf("%-48s | %-6d | %-8s | %-19s\n", $file, $m['batch'], $statusStr, $m['applied_at']);
            } else {
                $pendingCount++;
                echo sprintf("%-48s | %-6s | \033[33mPENDING\033[0m  | %-19s\n", $file, "-", "-");
            }
        }

        echo str_repeat("-", 90) . "\n";
        echo "Total: " . count($available) . " migrations, " . (count($available) - $pendingCount) . " applied, {$pendingCount} pending.\n\n";
        exit(0);
    }

    // Default: Run pending migrations
    $pending = get_pending_migrations();
    if (empty($pending)) {
        echo "  [OK] Database schema is already up to date! Zero pending migrations.\n";
        echo "========================================================\n\n";
        exit(0);
    }

    echo "Found " . count($pending) . " pending migration(s). Executing now...\n\n";

    $results = run_pending_migrations(function($event, $file, $res = null) {
        if ($event === 'starting') {
            echo "  >>> Running: {$file} ... ";
        } elseif ($event === 'success') {
            echo "\033[32m[DONE]\033[0m ({$res['duration_ms']}ms)\n";
            if (!empty($res['output'])) {
                $lines = explode("\n", trim($res['output']));
                foreach (array_slice($lines, 0, 3) as $l) {
                    echo "      ↳ " . trim($l) . "\n";
                }
            }
        } elseif ($event === 'failed') {
            echo "\033[31m[FAILED]\033[0m ({$res['duration_ms']}ms)\n";
            echo "      \033[31mError:\033[0m {$res['output']}\n";
        }
    });

    echo "\n========================================================\n";
    echo "Migration Results:\n";
    echo "  - Successfully Applied: " . count($results['applied']) . "\n";
    echo "  - Failed:               " . count($results['failed']) . "\n";
    echo "========================================================\n\n";

    exit(count($results['failed']) > 0 ? 1 : 0);
}

// ==========================================
// WEB BROWSER MODE
// ==========================================
require_once INCLUDES_PATH . '/auth.php';

// Allow logged in administrators or authorized local maintenance
$isAuthorized = is_logged_in() && has_permission('settings_manage');
if (!$isAuthorized && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    $isAuthorized = true; // Local developer mode
}

if (!$isAuthorized) {
    header('HTTP/1.1 403 Forbidden');
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:50px;text-align:center;'><h2>403 Forbidden</h2><p>Please log in as an administrator to manage database migrations.</p><a href='" . APP_URL . "/login.php'>Go to Login</a></body></html>";
    exit;
}

ensure_migrations_table();
$message = '';
$msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run') {
    $res = run_pending_migrations();
    if (!empty($res['failed'])) {
        $msgType = 'danger';
        $message = "Migration run finished with errors. Applied: " . count($res['applied']) . ", Failed: " . count($res['failed']);
    } elseif (!empty($res['applied'])) {
        $msgType = 'success';
        $message = "Successfully applied " . count($res['applied']) . " pending migration(s)! Database is now up to date.";
    } else {
        $msgType = 'info';
        $message = "Database is already up to date. No pending migrations.";
    }
}

$available = get_available_migration_files();
$appliedRows = db_fetch_all("SELECT * FROM migrations ORDER BY id ASC");
$appliedMap = [];
foreach ($appliedRows as $row) {
    $appliedMap[$row['migration']] = $row;
}
$pending = get_pending_migrations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMS Database Migrations Engine</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/icons/bootstrap-icons.css">
    <style>
        body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card-navy-header { background: #0f172a; color: #fff; }
    </style>
</head>
<body class="py-4">
    <div class="container" style="max-width: 960px;">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
            <div>
                <h4 class="fw-bold text-dark mb-1">
                    <i class="bi bi-database-gear text-primary me-2"></i>Database Migrations & Schema Engine
                </h4>
                <p class="text-muted small mb-0">Automatic synchronization of schema updates and migrations</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/modules/settings/index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> System Settings
                </a>
                <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-house me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show shadow-sm" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 border-start border-primary border-4 bg-white">
                    <div class="text-muted small fw-semibold">Available Migrations</div>
                    <div class="fs-3 fw-bold text-dark"><?= count($available) ?></div>
                    <div class="text-muted small">In <code>database/migrations/</code></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 border-start border-success border-4 bg-white">
                    <div class="text-muted small fw-semibold">Applied in Database</div>
                    <div class="fs-3 fw-bold text-success"><?= count($appliedRows) ?></div>
                    <div class="text-muted small">Recorded in <code>migrations</code> table</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm p-3 border-start <?= count($pending) > 0 ? 'border-warning' : 'border-secondary' ?> border-4 bg-white">
                    <div class="text-muted small fw-semibold">Pending Migrations</div>
                    <div class="fs-3 fw-bold <?= count($pending) > 0 ? 'text-warning' : 'text-secondary' ?>"><?= count($pending) ?></div>
                    <div class="text-muted small"><?= count($pending) > 0 ? 'Requires execution' : 'Up to date' ?></div>
                </div>
            </div>
        </div>

        <!-- Action Box -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex justify-content-between align-items-center p-3">
                <div>
                    <h6 class="fw-bold mb-1 text-dark"><i class="bi bi-magic text-primary me-2"></i>Automated Migration Trigger</h6>
                    <small class="text-muted">
                        Pending migrations also run automatically on every update, page load, or when new files are pulled from GitHub.
                    </small>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="run">
                    <button type="submit" class="btn btn-primary fw-semibold px-4 shadow-sm" <?= count($pending) === 0 ? '' : '' ?>>
                        <i class="bi bi-play-circle-fill me-1"></i> <?= count($pending) > 0 ? 'Run Pending Migrations (' . count($pending) . ')' : 'Run / Re-check All' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Migrations Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-columns-reverse me-2 text-primary"></i>All Migration Files & Execution History</h6>
                <span class="badge bg-secondary"><?= count($available) ?> Total</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Migration File</th>
                            <th>Batch</th>
                            <th>Status</th>
                            <th>Execution Time</th>
                            <th>Applied At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($available)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No migration files found in database/migrations/</td></tr>
                        <?php else: ?>
                            <?php foreach ($available as $idx => $file): 
                                $isApplied = isset($appliedMap[$file]);
                                $m = $isApplied ? $appliedMap[$file] : null;
                            ?>
                                <tr>
                                    <td class="text-muted small"><?= $idx + 1 ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark font-monospace"><?= htmlspecialchars($file) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($m): ?>
                                            <span class="badge bg-light text-dark border">Batch <?= $m['batch'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($m && $m['status'] === 'success'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-check-circle-fill me-1"></i> Applied</span>
                                        <?php elseif ($m && $m['status'] === 'failed'): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-x-circle-fill me-1"></i> Failed</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning"><i class="bi bi-clock-fill me-1"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $m ? number_format($m['execution_time_ms']) . ' ms' : '-' ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= $m ? htmlspecialchars($m['applied_at']) : '-' ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
