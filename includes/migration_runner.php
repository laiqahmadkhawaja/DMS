<?php
/**
 * DMS - Distribution Management System
 * Automatic Database Migration & Schema Synchronization Engine
 *
 * Automatically detects, executes, and records pending migrations in database/migrations/.
 * Designed to execute automatically on every update, deployment, git pull, or page load.
 */

if (!defined('DMS_RUNNING')) {
    define('DMS_RUNNING', true);
}

require_once __DIR__ . '/../config/config.php';

/**
 * Path to migrations directory
 */
define('DMS_MIGRATIONS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');

/**
 * Ensure migrations table exists in database
 */
function ensure_migrations_table(): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $db = Database::getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS `migrations` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `migration` varchar(255) NOT NULL,
                `batch` int(10) unsigned NOT NULL DEFAULT 1,
                `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `execution_time_ms` int(10) unsigned DEFAULT 0,
                `status` enum('success','failed') NOT NULL DEFAULT 'success',
                `output` text DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_migration` (`migration`),
                KEY `idx_batch` (`batch`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $ensured = true;
    } catch (Throwable $e) {
        error_log("Failed to ensure migrations table: " . $e->getMessage());
    }
}

/**
 * Get list of all applied migration filenames
 *
 * @return array List of migration filenames
 */
function get_applied_migrations(): array {
    ensure_migrations_table();
    try {
        $rows = db_fetch_all("SELECT migration FROM migrations WHERE status = 'success' ORDER BY id ASC");
        return array_column($rows, 'migration');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get list of all available migration files on disk, sorted chronologically
 *
 * @return array List of filenames (basename only)
 */
function get_available_migration_files(): array {
    if (!is_dir(DMS_MIGRATIONS_PATH)) {
        return [];
    }

    $files = glob(DMS_MIGRATIONS_PATH . DIRECTORY_SEPARATOR . '*.php');
    if (!$files) {
        return [];
    }

    $migrationFiles = [];
    foreach ($files as $filePath) {
        $migrationFiles[] = basename($filePath);
    }

    // Sort naturally so 2026_11_21 comes before 2026_11_22
    natsort($migrationFiles);
    return array_values($migrationFiles);
}

/**
 * Get list of pending migration files that have not yet succeeded
 *
 * @return array List of pending migration filenames
 */
function get_pending_migrations(): array {
    $available = get_available_migration_files();
    $applied   = get_applied_migrations();

    return array_values(array_diff($available, $applied));
}

/**
 * Execute a single migration file safely
 *
 * @param string $filename Migration filename (e.g. 2026_11_27_mobile_app.php)
 * @return array ['success' => bool, 'output' => string, 'duration_ms' => int]
 */
function execute_migration_file(string $filename): array {
    $filePath = DMS_MIGRATIONS_PATH . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($filePath)) {
        return [
            'success'     => false,
            'output'      => "File not found: {$filePath}",
            'duration_ms' => 0
        ];
    }

    $startTime = microtime(true);
    $output = '';
    $success = false;

    // Strategy 1: Subprocess execution via PHP CLI binary (Isolates memory, errors & prevents exit() killing main process)
    $phpBinary = PHP_BINARY;
    if (str_contains(basename($phpBinary), 'php-cgi')) {
        $cliCandidate = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'php.exe';
        if (file_exists($cliCandidate)) {
            $phpBinary = $cliCandidate;
        }
    }

    if (!empty($phpBinary) && file_exists($phpBinary) && function_exists('proc_open')) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($filePath);
        $process = @proc_open($cmd, $descriptors, $pipes, dirname($filePath));
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $output = trim($stdout . ($stderr ? "\n" . $stderr : ''));
            $success = ($exitCode === 0);
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);

            return [
                'success'     => $success,
                'output'      => $output,
                'duration_ms' => $durationMs
            ];
        }
    }

    // Strategy 2: In-process execution with output buffering (Fallback)
    ob_start();
    try {
        if (!defined('DMS_RUNNING')) {
            define('DMS_RUNNING', true);
        }
        include $filePath;
        $output = ob_get_clean();
        $success = true;
    } catch (Throwable $e) {
        $output = ob_get_clean();
        $output .= "\nException: " . $e->getMessage();
        $success = false;
    }

    $durationMs = (int)round((microtime(true) - $startTime) * 1000);
    return [
        'success'     => $success,
        'output'      => trim($output),
        'duration_ms' => $durationMs
    ];
}

/**
 * Run all pending migrations in chronological order
 *
 * @param callable|null $progressCallback Optional callback for real-time progress logging
 * @return array Results summary
 */
function run_pending_migrations(?callable $progressCallback = null): array {
    ensure_migrations_table();

    $pending = get_pending_migrations();
    if (empty($pending)) {
        return [
            'total_pending' => 0,
            'applied'       => [],
            'failed'        => []
        ];
    }

    // Determine next batch number
    $maxBatch = (int)db_fetch_val("SELECT COALESCE(MAX(batch), 0) FROM migrations");
    $batch = $maxBatch + 1;

    $applied = [];
    $failed  = [];
    $db = Database::getConnection();

    foreach ($pending as $migrationFile) {
        if ($progressCallback) {
            $progressCallback('starting', $migrationFile);
        }

        $res = execute_migration_file($migrationFile);

        if ($res['success']) {
            $stmt = $db->prepare("
                INSERT INTO migrations (migration, batch, applied_at, execution_time_ms, status, output)
                VALUES (?, ?, NOW(), ?, 'success', ?)
                ON DUPLICATE KEY UPDATE 
                    batch = VALUES(batch),
                    applied_at = NOW(),
                    execution_time_ms = VALUES(execution_time_ms),
                    status = 'success',
                    output = VALUES(output)
            ");
            $stmt->execute([
                $migrationFile,
                $batch,
                $res['duration_ms'],
                substr($res['output'], 0, 65535)
            ]);

            $applied[] = [
                'migration'   => $migrationFile,
                'duration_ms' => $res['duration_ms'],
                'output'      => $res['output']
            ];

            if ($progressCallback) {
                $progressCallback('success', $migrationFile, $res);
            }
        } else {
            $stmt = $db->prepare("
                INSERT INTO migrations (migration, batch, applied_at, execution_time_ms, status, output)
                VALUES (?, ?, NOW(), ?, 'failed', ?)
                ON DUPLICATE KEY UPDATE 
                    batch = VALUES(batch),
                    applied_at = NOW(),
                    execution_time_ms = VALUES(execution_time_ms),
                    status = 'failed',
                    output = VALUES(output)
            ");
            $stmt->execute([
                $migrationFile,
                $batch,
                $res['duration_ms'],
                substr($res['output'], 0, 65535)
            ]);

            $failed[] = [
                'migration'   => $migrationFile,
                'duration_ms' => $res['duration_ms'],
                'output'      => $res['output']
            ];

            if ($progressCallback) {
                $progressCallback('failed', $migrationFile, $res);
            }

            // Halt further migrations on failure to prevent inconsistent schema states
            break;
        }
    }

    // Log to migrations log file
    $logFile = LOGS_PATH . DIRECTORY_SEPARATOR . 'migrations.log';
    $logMsg = date('[Y-m-d H:i:s]') . " Batch {$batch}: " . count($applied) . " applied, " . count($failed) . " failed." . PHP_EOL;
    foreach ($applied as $a) {
        $logMsg .= "  [+] {$a['migration']} ({$a['duration_ms']}ms)" . PHP_EOL;
    }
    foreach ($failed as $f) {
        $logMsg .= "  [-] FAILED: {$f['migration']} ({$f['duration_ms']}ms): {$f['output']}" . PHP_EOL;
    }
    @file_put_contents($logFile, $logMsg, FILE_APPEND);

    return [
        'batch'         => $batch,
        'total_pending' => count($pending),
        'applied'       => $applied,
        'failed'        => $failed
    ];
}

/**
 * Automatically check and run pending migrations on every update / page load.
 * Ultra-fast execution: takes < 0.1ms when schema is already up to date.
 *
 * @param bool $force Force re-check even if already checked in current request
 * @return bool True if migrations were applied, False if already up to date
 */
function auto_run_pending_migrations(bool $force = false): bool {
    static $hasRun = false;
    if ($hasRun && !$force) {
        return false;
    }
    $hasRun = true;

    try {
        ensure_migrations_table();

        // Fast count comparison
        $availableFiles = glob(DMS_MIGRATIONS_PATH . DIRECTORY_SEPARATOR . '*.php');
        $availableCount = $availableFiles ? count($availableFiles) : 0;
        if ($availableCount === 0) {
            return false;
        }

        $appliedCount = (int)db_fetch_val("SELECT COUNT(*) FROM migrations WHERE status = 'success'");

        // If file count equals applied count, database is already 100% synchronized!
        if ($availableCount <= $appliedCount) {
            return false;
        }

        // New migration file(s) detected! Automatically apply them
        $result = run_pending_migrations();
        return !empty($result['applied']);
    } catch (Throwable $e) {
        error_log("Auto-migration error: " . $e->getMessage());
        return false;
    }
}
