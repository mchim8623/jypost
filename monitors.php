<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$message = $error = '';
$site_name = getConfig('site_name') ?: '集邮记';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') { $name = trim($_POST['name'] ?? ''); if (empty($name)) $error = '请填写监控机名称'; else { $token = bin2hex(random_bytes(32)); $db->prepare("INSERT INTO monitors (name, token) VALUES (?, ?)")->execute([$name, $token]); $message = '监控机添加成功'; } }
    elseif ($action === 'edit') { $id = intval($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? ''); $status = intval($_POST['status'] ?? 1); if ($id > 0 && !empty($name)) { $db->prepare("UPDATE monitors SET name=?, status=? WHERE id=?")->execute([$name, $status, $id]); $message = '更新成功'; } }
    elseif ($action === 'regenerate_token') { $id = intval($_POST['id'] ?? 0); if ($id > 0) { $token = bin2hex(random_bytes(32)); $db->prepare("UPDATE monitors SET token=? WHERE id=?")->execute([$token, $id]); $message = 'Token已重新生成'; } }
    elseif ($action === 'delete') { $id = intval($_POST['id'] ?? 0); if ($id > 0) { $db->prepare("DELETE FROM monitors WHERE id=?")->execute([$id]); $message = '删除成功'; } }
}
$monitors = $db->query("SELECT m.*, (SELECT COUNT(*) FROM monitor_data WHERE monitor_id=m.id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as c1h, (SELECT COUNT(*) FROM monitor_data WHERE monitor_id=m.id) as total FROM monitors m ORDER BY m.id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><title>监控机管理 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>.sidebar{min-height:100vh;background:linear-gradient(135deg,#667eea,#764ba2)}.sidebar .nav-link{color:rgba(255,255,255,.8);padding:12px 20px;border-radius:8px;margin:4px 0}.sidebar .nav-link:hover,.sidebar .nav-link.active{color:#fff;background:rgba(255,255,255,.2)}.status-badge{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px}.status-online{background:#10b981}.status-offline{background:#ef4444}.token-display{font-family:monospace;background:#f3f4f6;padding:5px 10px;border-radius:5px;font-size:12px}</style>
</head>
<body>
<div class="container-fluid"><div class="row">
    <nav class="col-md-2 d-md-block sidebar"><div class="position-sticky pt-4">
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
    </div></nav>
    <main class="col-md-10 ms-sm-auto px-md-4 py-4">
        <div class="d-flex justify-content-between mb-4"><h2>监控机管理</h2><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#monitorModal" onclick="resetForm()"><i class="bi bi-plus-circle"></i> 添加监控机</button></div>
        <?php if($message): ?><div class="alert alert-success alert-dismissible"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger alert-dismissible"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <div class="card"><div class="card-body p-0"><table class="table table-hover mb-0"><thead><tr><th>ID</th><th>名称</th><th>IP</th><th>状态</th><th>最后心跳</th><th>1h检查</th><th>总检查</th><th>Token</th><th>操作</th></tr></thead><tbody>
            <?php foreach($monitors as $m): $online = $m['status'] && $m['last_heartbeat'] && strtotime($m['last_heartbeat']) > time() - 600; ?>
            <tr><td><?= $m['id'] ?></td><td><?= htmlspecialchars($m['name']) ?></td><td><?= htmlspecialchars($m['ip'] ?? '-') ?></td><td><span class="status-badge <?= $online ? 'status-online' : 'status-offline' ?>"></span><?= $m['status'] ? ($online ? '在线' : '离线') : '已禁用' ?></td><td><?= $m['last_heartbeat'] ?? '-' ?></td><td><?= $m['c1h'] ?></td><td><?= $m['total'] ?></td><td><span class="token-display">已隐藏保护...</span><button class="btn btn-sm btn-outline-secondary" onclick="copyToken('<?= htmlspecialchars($m['token'], ENT_QUOTES, 'UTF-8') ?>')"><i class="bi bi-clipboard"></i> 复制</button></td><td><button class="btn btn-sm btn-outline-primary" onclick="editMonitor(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?>', <?= $m['status'] ?>)"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-outline-warning" onclick="regenerateToken(<?= $m['id'] ?>)"><i class="bi bi-arrow-repeat"></i></button><button class="btn btn-sm btn-outline-danger" onclick="deleteMonitor(<?= $m['id'] ?>)"><i class="bi bi-trash"></i></button></td></tr>
            <?php endforeach; ?>
        </tbody></table></div></div>
    </main>
</div></div>
<div class="modal fade" id="monitorModal"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="modalTitle">添加监控机</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="id" id="monitorId"><div class="mb-3"><label class="form-label">名称 *</label><input type="text" class="form-control" name="name" id="monitorName" required></div><div class="mb-3" id="statusGroup" style="display:none"><label class="form-label">状态</label><select class="form-control" name="status" id="monitorStatus"><option value="1">启用</option><option value="0">禁用</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="submit" class="btn btn-primary">保存</button></div></form></div></div></div>
<form method="post" id="regenerateForm" style="display:none"><input type="hidden" name="action" value="regenerate_token"><input type="hidden" name="id" id="regenerateId"></form>
<form method="post" id="deleteForm" style="display:none"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var modal=new bootstrap.Modal(document.getElementById('monitorModal'));
    function resetForm(){document.getElementById('modalTitle').textContent='添加监控机';document.getElementById('formAction').value='add';document.getElementById('monitorId').value='';document.getElementById('monitorName').value='';document.getElementById('statusGroup').style.display='none';}
    // 修复点：显式拆解传参，不使用 json_encode 整行吐回前端，防御凭证窥探
    function editMonitor(id, name, status){document.getElementById('modalTitle').textContent='编辑监控机';document.getElementById('formAction').value='edit';document.getElementById('monitorId').value=id;document.getElementById('monitorName').value=name;document.getElementById('monitorStatus').value=status;document.getElementById('statusGroup').style.display='block';modal.show();}
    function regenerateToken(id){if(confirm('确定重新生成？')){document.getElementById('regenerateId').value=id;document.getElementById('regenerateForm').submit();}}
    function deleteMonitor(id){if(confirm('确定删除？')){document.getElementById('deleteId').value=id;document.getElementById('deleteForm').submit();}}
    function copyToken(t){if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(function(){alert('已复制')}).catch(function(){fallbackCopy(t)});}else{fallbackCopy(t);}}
    function fallbackCopy(text){var ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.left='-9999px';document.body.appendChild(ta);ta.select();try{document.execCommand('copy');alert('已复制');}catch(e){prompt('手动复制:',text);}document.body.removeChild(ta);}
</script>
</body>
</html>