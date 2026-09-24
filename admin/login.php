<?php
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = '后台登录 - 社区便民留言板';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

// 已登录则跳转
if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../config/database.php';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['username'];
                header('Location: index.php');
                exit;
            } else {
                $error = '用户名或密码错误';
            }
        } catch (Throwable $e) {
            error_log('[admin/login] ' . $e->getMessage());
            $error = '服务暂时不可用，请稍后重试';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= $cssPath ?>">
</head>
<body class="login-body">
<div class="login-card">
    <h2>🔐 后台管理登录</h2>
    <p class="login-subtitle">社区便民留言板管理系统</p>
    <?php if ($error): ?>
    <div class="alert alert-error"><?= cleanInput($error) ?></div>
    <?php endif; ?>
    <form method="POST" class="login-form">
        <div class="form-group">
            <label for="username">用户名</label>
            <input type="text" id="username" name="username" placeholder="请输入管理员账号" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">密码</label>
            <input type="password" id="password" name="password" placeholder="请输入密码" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block">登 录</button>
    </form>
    <div class="login-footer">
        <a href="../index.php">← 返回首页</a>
    </div>
</div>
</body>
</html>
