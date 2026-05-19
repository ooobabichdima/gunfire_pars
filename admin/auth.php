<?php

declare(strict_types=1);

use App\Admin\Auth;

$auth = new Auth($db);

if (($_GET['action'] ?? '') === 'logout') {
    $auth->logout();
    header('Location: index.php?page=login');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (!$auth->hasPassword()) {
        if (strlen($password) < 4) {
            $error = t('password_min_length');
        } elseif ($password !== $passwordConfirm) {
            $error = t('passwords_mismatch');
        } else {
            $auth->setPassword($password);
            $auth->login($password);
            header('Location: index.php');
            exit;
        }
    } else {
        if ($auth->login($password)) {
            header('Location: index.php');
            exit;
        }
        $error = t('invalid_password');
    }
}

$needsSetup = !$auth->hasPassword();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $needsSetup ? 'Setup' : 'Login' ?> - Supplier Aggregator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark d-flex align-items-center justify-content-center" style="min-height: 100vh;">
<div class="card" style="width: 380px;">
    <div class="card-body p-4">
        <h4 class="text-center mb-3"><?= $needsSetup ? t('setup_title') : t('login_title') ?></h4>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= esc($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <input type="password" name="password" class="form-control" placeholder="<?= t('password') ?>" required autofocus>
            </div>
            <?php if ($needsSetup): ?>
                <div class="mb-3">
                    <input type="password" name="password_confirm" class="form-control" placeholder="<?= t('confirm_password') ?>" required>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary w-100"><?= $needsSetup ? t('create_password_btn') : t('login_btn') ?></button>
        </form>
    </div>
</div>
</body>
</html>
