<?php
session_start();

require_once __DIR__ . '/config.php';

if (needs_initial_setup()) {
    header('Location: init.php');
    exit;
}

$PASSWORD = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($PASSWORD !== '' && !empty($_POST['password']) && $_POST['password'] === $PASSWORD) {
        $_SESSION['admin'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = '密码错误';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>管理员登录</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
<div class="login-card">
    <h2>管理员登录</h2>
    <p>请输入管理员密码以管理图书与上传。</p>
    <?php if (!empty($error)): ?>
        <p class="notice-row notice-error"><?=$error?></p>
    <?php endif; ?>
    <form method="post" class="login-form">
        <input type="password" name="password" placeholder="输入密码" required>
        <button type="submit">登录</button>
    </form>
</div>
</body>
</html>