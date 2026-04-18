<?php
require_once 'config.php';
requireLogin();

$db = getDB();
$message = $error = '';
$site_name = getConfig('site_name') ?: '集邮记';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) { $error = '请填写监控机名称'; }
        else {
            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("INSERT INTO monitors (name, token) VALUES (?, ?)");
            if ($stmt->execute([$name, $token])) $message = '监控机添加成功'; else $error = '添加失败';
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? ''); $status = intval($_POST['status'] ?? 1);
        if ($id > 0 && !empty($name)) {
            $stmt = $db->prepare("UPDATE monitors SET name = ?, status = ? WHERE id = ?");
            if ($stmt->execute([$name, $status, $id])) $message = '监控机更新成功'; else $error = '更新失败';
        }
    } elseif ($action === 'regenerate_token') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("UPDATE monitors SET token = ? WHERE id = ?");
            if ($stmt->execute([$token, $id])) $message = 'Token已重新生成'; else $error = '操作失败';
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM monitors WHERE id = ?");
            if ($stmt->execute([$id])) $message = '监控机删除成功'; else $error = '删除失败';
        }
    }
}

$monitors = $db->query("SELECT m.*, (SELECT COUNT(*) FROM monitor_data WHERE monitor_id = m.id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as checks_1h, (SELECT COUNT(*) FROM monitor_data WHERE monitor_id = m.id) as total_checks FROM monitors m ORDER BY m.id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>监控机管理 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .sidebar .nav-link { color: rgba(255,255,255,.8); padding: 12px 20px; border-radius: 8px; margin: 4px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,.2); }
        .status-badge { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-online { background-color: #10b981; } .status-offline { background-color: #ef4444; }
        .token-display { font-family: monospace; background: #f3f4f6; padding: 5px 10px; border-radius: 5px; font-size: 12px; }
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
                        <a class="nav-link active" href="monitors.php"><i class="bi bi-hdd-network"></i> 监控机管理</a>
                        <a class="nav-link" href="history.php"><i class="bi bi-clock-history"></i> 历史数据</a>
                        <a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> 系统设置</a>
                        <a class="nav-link" href="public.php" target="_blank"><i class="bi bi-eye"></i> 公开监控站</a>
                        <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> 退出登录</a>
                    </div>
                </div>
            </nav>
            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4"><h2>监控机管理</h2><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#monitorModal" onclick="resetForm()"><i class="bi bi-plus-circle"></i> 添加监控机</button></div>
                <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show"><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
                <div class="alert alert-info"><i class="bi bi-info-circle"></i> <strong>使用说明：</strong> 添加监控机后，将生成的Token配置到Python监控程序中。<br>运行命令：<code>python monitor.py setup</code> 然后输入API地址和Token。</div>
                <div class="card"><div class="card-body p-0"><div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>ID</th><th>名称</th><th>IP地址</th><th>状态</th><th>最后心跳</th><th>1小时检查数</th><th>总检查数</th><th>Token</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach ($monitors as $monitor): $is_online = $monitor['status'] && $monitor['last_heartbeat'] && strtotime($monitor['last_heartbeat']) > time() - 300; ?>
                            <tr>
                                <td><?= $monitor['id'] ?></td><td><?= htmlspecialchars($monitor['name']) ?></td><td><?= $monitor['ip'] ?? '-' ?></td>
                                <td><span class="status-badge <?= $is_online ? 'status-online' : 'status-offline' ?>"></span><?= $monitor['status'] ? ($is_online ? '在线' : '离线') : '已禁用' ?></td>
                                <td><?= $monitor['last_heartbeat'] ?? '-' ?></td><td><?= $monitor['checks_1h'] ?></td><td><?= $monitor['total_checks'] ?></td>
                                <td><span class="token-display" title="<?= $monitor['token'] ?>"><?= substr($monitor['token'], 0, 8) ?>...</span><button class="btn btn-sm btn-outline-secondary" onclick="copyToken('<?= $monitor['token'] ?>')"><i class="bi bi-clipboard"></i></button></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick='editMonitor(<?= json_encode($monitor) ?>)'><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-warning" onclick="regenerateToken(<?= $monitor['id'] ?>)"><i class="bi bi-arrow-repeat"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteMonitor(<?= $monitor['id'] ?>)"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div></div></div>
            </main>
        </div>
    </div>
    <div class="modal fade" id="monitorModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="modalTitle">添加监控机</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="id" id="monitorId"><div class="mb-3"><label class="form-label">监控机名称 *</label><input type="text" class="form-control" name="name" id="monitorName" required></div><div class="mb-3" id="statusGroup" style="display:none"><label class="form-label">状态</label><select class="form-control" name="status" id="monitorStatus"><option value="1">启用</option><option value="0">禁用</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="submit" class="btn btn-primary">保存</button></div></form></div></div></div>
    <form method="post" id="regenerateForm" style="display:none"><input type="hidden" name="action" value="regenerate_token"><input type="hidden" name="id" id="regenerateId"></form>
    <form method="post" id="deleteForm" style="display:none"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const modal = new bootstrap.Modal(document.getElementById('monitorModal'));
        function resetForm() { document.getElementById('modalTitle').textContent = '添加监控机'; document.getElementById('formAction').value = 'add'; document.getElementById('monitorId').value = ''; document.getElementById('monitorName').value = ''; document.getElementById('statusGroup').style.display = 'none'; }
        function editMonitor(monitor) { document.getElementById('modalTitle').textContent = '编辑监控机'; document.getElementById('formAction').value = 'edit'; document.getElementById('monitorId').value = monitor.id; document.getElementById('monitorName').value = monitor.name; document.getElementById('monitorStatus').value = monitor.status; document.getElementById('statusGroup').style.display = 'block'; modal.show(); }
        function regenerateToken(id) { if (confirm('确定重新生成Token？')) { document.getElementById('regenerateId').value = id; document.getElementById('regenerateForm').submit(); } }
        function deleteMonitor(id) { if (confirm('确定删除？')) { document.getElementById('deleteId').value = id; document.getElementById('deleteForm').submit(); } }
        function copyToken(token) { navigator.clipboard.writeText(token).then(() => alert('Token已复制')).catch(() => prompt('请手动复制:', token)); }
    </script>
</body>
</html>