<?php
require_once 'config.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$site_name = getConfig('site_name') ?: '集邮记';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header('Location: index.php');
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
        .login-card { background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        .login-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .login-body { padding: 40px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="login-card">
                    <div class="login-header">
                        <h2><i class="bi bi-stamp"></i> <?= htmlspecialchars($site_name) ?></h2>
                        <p class="mb-0">管理面板</p>
                    </div>
                    <div class="login-body">
                        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                        <form method="post">
                            <div class="mb-3"><label class="form-label">用户名</label><input type="text" class="form-control" name="username" required autofocus></div>
                            <div class="mb-3"><label class="form-label">密码</label><input type="password" class="form-control" name="password" required></div>
                            <button type="submit" class="btn btn-primary w-100 py-2">登 录</button>
                        </form>
                        <div class="text-center mt-3"><a href="public.php" class="text-muted"><i class="bi bi-eye"></i> 查看公开监控站</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>