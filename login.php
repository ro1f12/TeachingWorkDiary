<?php
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/layout.php';

if (user()) {
    header('Location: index.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login_user($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $err = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/app.css">
    <title>Login — <?=htmlspecialchars(APP_NAME)?></title>
</head>
<body style="background:#f0f4f8;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px 0;">
<main class="container" style="max-width:440px;">
    <div class="card" style="padding:28px 24px;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,0.08);">
        <div style="text-align:center;margin-bottom:20px;">
            <h1 style="font-size:20px;margin:0 0 4px;color:#17365d;">Lalit Chandra Bharali College</h1>
            <div style="font-size:13px;font-weight:bold;color:#444;">Department of Information Technology</div>
            <div style="font-size:12px;color:#666;margin-top:2px;">Teaching &amp; Work Diary Portal</div>
        </div>

        <?php if ($err): ?>
            <div class="notice error"><?=esc($err)?></div>
        <?php endif; ?>

        <form method="post">
            <?=csrf_field()?>
            <div class="field">
                <label>Username</label>
                <input name="username" required autofocus placeholder="e.g. admin or mar" value="<?=esc($_POST['username']??'')?>">
            </div>
            <br>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Enter password">
            </div>
            <br>
            <button class="btn success" style="width:100%;padding:11px;font-size:15px;font-weight:bold;">Sign In</button>
        </form>

        <div style="margin-top:20px;padding-top:15px;border-top:1px solid #eef2f6;font-size:12px;color:#666;text-align:center;">
            Administrator: <code>admin</code> / <code>admin123</code><br>
            Teachers: <code>mar</code>, <code>plp</code>, <code>md</code>, <code>ac</code>, <code>mn</code> (pass: <code>[code]123</code>)
        </div>
    </div>
</main>
</body>
</html>
