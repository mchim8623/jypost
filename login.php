<?php
require_once 'config.php';
if (isLoggedIn()) { header('Location: index.php'); exit; }
$error = '';
$site_name = getConfig('site_name') ?: '集邮记';

// 安全加固：判断当前登录会话是否被锁定
if (isset($_SESSION['login_locked_until']) && $_SESSION['login_locked_until'] > time()) {
    $remain = $_SESSION['login_locked_until'] - time();
    $error = "登录错误次数过多，请在 {$remain} 秒后再试。";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) { $error = '请输入用户名和密码'; }
    else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            // 登录成功，清除错误计数器
            unset($_SESSION['login_attempts']);
            unset($_SESSION['login_locked_until']);

            $_SESSION['logged_in'] = true; 
            $_SESSION['user_id'] = $user['id']; 
            $_SESSION['username'] = $user['username']; 
            $_SESSION['role'] = $user['role'];
            header('Location: index.php'); exit;
        } else { 
            // 登录失败，进入防爆破锁死计数
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_locked_until'] = time() + 900; // 封禁锁死 15 分钟
                $error = '由于连续登录失败次数过多，系统已自动限制登录 15 分钟。';
            } else {
                $remain_attempts = 5 - $_SESSION['login_attempts'];
                $error = "用户名或密码错误，你还可以尝试 {$remain_attempts} 次。";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><title>登录 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>body{background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;display:flex;align-items:center}.login-card{background:#fff;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden}.login-header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:30px;text-align:center}.login-body{padding:40px}</style>
</head>
<body>
<div class="container"><div class="row justify-content-center"><div class="col-md-5"><div class="login-card">
    <div class="login-header"><h2><i class="bi bi-stamp"></i> <?= htmlspecialchars($site_name) ?></h2><p class="mb-0">管理面板</p></div>
    <div class="login-body">
        <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3"><label class="form-label">用户名</label><input type="text" class="form-control" name="username" required autofocus></div>
            <div class="mb-3"><label class="form-label">密码</label><input type="password" class="form-control" name="password" required></div>
            <button type="submit" class="btn btn-primary w-100 py-2">登 录</button>
        </form>
        <div class="text-center mt-3"><a href="public.php" class="text-muted"><i class="bi bi-eye"></i> 查看公开监控站</a></div>
    </div>
</div></div></div></div>
</body>
</html>