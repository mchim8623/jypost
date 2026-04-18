<?php
require_once 'config.php';
requireLogin();

$db = getDB();
$message = $error = '';
$site_name = getConfig('site_name') ?: '集邮记';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_config') {
        foreach ($_POST['config'] ?? [] as $key => $value) {
            $stmt = $db->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
            $stmt->execute([$value, $key]);
        }
        $message = '配置保存成功';
    } elseif ($action === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (empty($old) || empty($new) || empty($confirm)) $error = '请填写所有密码字段';
        elseif ($new !== $confirm) $error = '两次输入的新密码不一致';
        elseif (strlen($new) < 6) $error = '新密码至少6位';
        else {
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            if (password_verify($old, $user['password'])) {
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
                $message = '密码修改成功';
            } else $error = '原密码错误';
        }
    } elseif ($action === 'clean_data') {
        $days = intval($_POST['days'] ?? 30);
        $stmt = $db->prepare("DELETE FROM monitor_data WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        $message = "已清理 {$stmt->rowCount()} 条历史数据";
    }
}

$configs = [];
$result = $db->query("SELECT * FROM system_config");
while ($row = $result->fetch()) $configs[$row['config_key']] = $row;

$stats = [
    'total_data' => $db->query("SELECT COUNT(*) FROM monitor_data")->fetchColumn(),
    'oldest_data' => $db->query("SELECT MIN(created_at) FROM monitor_data")->fetchColumn(),
    'total_alerts' => $db->query("SELECT COUNT(*) FROM alert_logs")->fetchColumn()
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .sidebar .nav-link { color: rgba(255,255,255,.8); padding: 12px 20px; border-radius: 8px; margin: 4px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,.2); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 d-md-block sidebar">
                <div class="position-sticky pt-4">
                    <h4 class="text-white text-center mb-4"><i class="bi bi-stamp"></i> <?= htmlspecialchars($site_name) ?></h4>
                    <div class="nav flex-column">
                        <a class="nav-link" href="index.php"><i class="bi bi-speedometer2"></i> 仪表盘</a>
                        <a class="nav-link" href="servers.php"><i class="bi bi-server"></i> 服务器管理</a>
                        <a class="nav-link" href="monitors.php"><i class="bi bi-hdd-network"></i> 监控机管理</a>
                        <a class="nav-link" href="history.php"><i class="bi bi-clock-history"></i> 历史数据</a>
                        <a class="nav-link active" href="settings.php"><i class="bi bi-gear"></i> 系统设置</a>
                        <a class="nav-link" href="public.php" target="_blank"><i class="bi bi-eye"></i> 公开监控站</a>
                        <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> 退出登录</a>
                    </div>
                </div>
            </nav>
            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <h2 class="mb-4">系统设置</h2>
                <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show"><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
                <div class="row">
                    <div class="col-md-8">
                        <div class="card mb-4"><div class="card-header"><h5 class="mb-0">基本设置</h5></div><div class="card-body">
                            <form method="post"><input type="hidden" name="action" value="save_config">
                                <div class="mb-3"><label class="form-label">站点名称</label><input type="text" class="form-control" name="config[site_name]" value="<?= htmlspecialchars($configs['site_name']['config_value'] ?? '集邮记') ?>"></div>
                                <div class="mb-3"><label class="form-label">站点地址</label><input type="url" class="form-control" name="config[site_url]" value="<?= htmlspecialchars($configs['site_url']['config_value'] ?? '') ?>"></div>
                                <div class="mb-3"><label class="form-label">默认检查间隔 (秒)</label><input type="number" class="form-control" name="config[default_check_interval]" value="<?= $configs['default_check_interval']['config_value'] ?? '60' ?>" min="10" max="3600"></div>
                                <div class="mb-3"><label class="form-label">数据保留天数</label><input type="number" class="form-control" name="config[data_retention_days]" value="<?= $configs['data_retention_days']['config_value'] ?? '30' ?>" min="1" max="365"></div>
                                <div class="mb-3"><label class="form-label">告警Webhook地址</label><input type="url" class="form-control" name="config[alert_webhook]" value="<?= htmlspecialchars($configs['alert_webhook']['config_value'] ?? '') ?>" placeholder="支持企业微信、钉钉等Webhook"></div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> 保存设置</button>
                            </form>
                        </div></div>
                        <div class="card mb-4"><div class="card-header"><h5 class="mb-0">数据清理</h5></div><div class="card-body">
                            <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> 当前有 <strong><?= number_format($stats['total_data']) ?></strong> 条监控记录，最早记录: <?= $stats['oldest_data'] ?? '无' ?></div>
                            <form method="post" onsubmit="return confirm('确定清理？')"><input type="hidden" name="action" value="clean_data"><div class="mb-3"><label class="form-label">清理多少天前的数据</label><input type="number" class="form-control" name="days" value="30" min="7" max="365"></div><button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> 清理历史数据</button></form>
                        </div></div>
                    </div>
                    <div class="col-md-4">
                        <div class="card mb-4"><div class="card-header"><h5 class="mb-0">修改密码</h5></div><div class="card-body">
                            <form method="post"><input type="hidden" name="action" value="change_password">
                                <div class="mb-3"><label class="form-label">原密码</label><input type="password" class="form-control" name="old_password" required></div>
                                <div class="mb-3"><label class="form-label">新密码</label><input type="password" class="form-control" name="new_password" required></div>
                                <div class="mb-3"><label class="form-label">确认新密码</label><input type="password" class="form-control" name="confirm_password" required></div>
                                <button type="submit" class="btn btn-warning w-100"><i class="bi bi-key"></i> 修改密码</button>
                            </form>
                        </div></div>
                        <div class="card"><div class="card-header"><h5 class="mb-0">系统信息</h5></div><div class="card-body">
                            <table class="table table-sm">
                                <tr><td>PHP版本</td><td><?= phpversion() ?></td></tr>
                                <tr><td>数据库</td><td>MySQL</td></tr>
                                <tr><td>监控记录数</td><td><?= number_format($stats['total_data']) ?></td></tr>
                                <tr><td>告警记录数</td><td><?= number_format($stats['total_alerts']) ?></td></tr>
                                <tr><td>当前用户</td><td><?= htmlspecialchars($_SESSION['username']) ?></td></tr>
                            </table>
                        </div></div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>