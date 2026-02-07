<?php
session_start();

$PASSWORD = 'y0117x299823669c'; // 改成你自己的密码

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['password']) && $_POST['password'] === $PASSWORD) {
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
</head>
<body>
<h2>管理员登录</h2>
<?php if (!empty($error)): ?>
    <p style="color:red;"><?=$error?></p>
<?php endif; ?>
<form method="post">
    <input type="password" name="password" placeholder="输入密码">
    <button type="submit">登录</button>
</form>
</body>
</html>