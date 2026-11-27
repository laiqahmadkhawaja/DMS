<?php
/**
 * DMS - Distribution Management System
 * Authentication & Login Screen
 */

require_once __DIR__ . '/config/config.php';

// If user already authenticated, redirect immediately before rendering HTML
if (is_logged_in()) {
    if (is_field_staff()) {
        redirect(APP_URL . '/modules/app/index.php');
    }
    redirect(APP_URL . '/dashboard.php');
}

// Proactively ensure users table has security & lockout columns
if (function_exists('get_users_table_columns')) {
    get_users_table_columns();
}

$error = '';
$usernameVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameVal = clean_input($_POST['username'] ?? '');
    $passwordVal = (string)($_POST['password'] ?? '');

    $result = attempt_login($usernameVal, $passwordVal);
    if ($result['success']) {
        set_flash_message('success', 'Welcome back, ' . e($_SESSION['full_name']));
        if (is_field_staff()) {
            redirect(APP_URL . '/modules/app/index.php');
        }
        redirect(APP_URL . '/dashboard.php');
    } else {
        $error = $result['message'];
    }
}

$pageTitle = 'Sign In — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <!-- Offline Local Bootstrap & Icons -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f1e36 0%, #1e3a6a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 410px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .login-header {
            background-color: #0f1e36;
            color: #ffffff;
            padding: 1.5rem 1.75rem 1.25rem;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .login-body {
            padding: 1.75rem;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <div class="d-inline-flex align-items-center justify-content-center bg-warning text-dark rounded-circle mb-2" style="width:48px; height:48px;">
            <i class="bi bi-box-seam-fill fs-4"></i>
        </div>
        <h5 class="mb-0 fw-bold"><?= e(CLIENT_NAME) ?></h5>
        <small class="text-white-50">Distribution Management System</small>
    </div>

    <div class="login-body">
        <?php foreach (get_flash_messages() as $msg): ?>
            <div class="alert alert-<?= e($msg['type']) ?> py-2 small mb-3">
                <?= e($msg['message']) ?>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small mb-3">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/login.php" autocomplete="off">
            <div class="mb-3">
                <label for="username" class="form-label small fw-semibold text-secondary">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="username" name="username" value="<?= e($usernameVal) ?>" required autofocus placeholder="Enter your username">
                </div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                    <input type="password" class="form-control" id="password" name="password" required placeholder="Enter password">
                </div>
            </div>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-primary fw-semibold py-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to DMS
                </button>
            </div>
        </form>

        <div class="mt-4 pt-3 border-top text-center text-muted small">
            Default credentials: <span class="badge bg-light text-dark border">admin</span> / <span class="badge bg-light text-dark border">admin123</span>
        </div>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
