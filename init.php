<?php
session_start();

require_once __DIR__ . '/config.php';

if (!needs_initial_setup()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim((string)($_POST['openai_api_key'] ?? ''));
    $adminPassword = trim((string)($_POST['admin_password'] ?? ''));

    if ($apiKey === '' || $adminPassword === '') {
        $error = '请填写 OpenAI 兼容 Key 与管理员密码。';
    } else {
        $config = [
            'OPENAI_API_KEY' => $apiKey,
            'ADMIN_PASSWORD' => $adminPassword,
        ];

        $configFile = get_local_config_path();
        $payload = "<?php\nreturn " . var_export($config, true) . ";\n";

        $written = @file_put_contents($configFile, $payload, LOCK_EX);
        if ($written === false) {
            $error = '写入配置失败，请确认站点对项目目录有读写权限。';
        } else {
            $success = '初始化完成，请使用管理员密码登录。';
            header('Location: login.php?init=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>初始化设置</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
<div class="login-card">
    <h2>首次初始化</h2>
    <p>请填写 OpenAI 兼容 Key 与管理员密码后继续使用。</p>
    <p class="notice-row notice-warning">提示：原生运行环境为 PHP 8，并确保项目目录具备读写权限。</p>

    <?php if ($error !== ''): ?>
        <p class="notice-row notice-error"><?=$error?></p>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <p class="notice-row notice-success"><?=$success?></p>
    <?php endif; ?>

    <form method="post" class="login-form">
        <input type="text" name="openai_api_key" placeholder="输入 OpenAI 兼容 Key" required>
        <input type="password" name="admin_password" placeholder="设置管理员密码" required>
        <button type="submit">保存并继续</button>
    </form>
</div>
</body>
</html>