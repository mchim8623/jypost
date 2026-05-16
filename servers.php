<?php
require_once 'config.php';
requireLogin();
$db = getDB();
$message = $error = '';
$site_name = getConfig('site_name') ?: '集邮记';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? ''); $url = trim($_POST['url'] ?? ''); $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? ''); $icon_url = trim($_POST['icon_url'] ?? ''); $is_public = isset($_POST['is_public']) ? 1 : 0; $check_interval = intval($_POST['check_interval'] ?? 60); $use_custom_interval = isset($_POST['use_custom_interval']) ? 1 : 0;
        if (empty($name) || empty($url) || empty($username) || empty($password)) { $error = '请填写所有必填项'; }
        else {
            $url = rtrim($url, '/') . '/';
            $stmt = $db->prepare("INSERT INTO emby_servers (name, url, username, password, icon_url, is_public, check_interval, use_custom_interval) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $url, $username, encryptPassword($password), $icon_url, $is_public, $check_interval, $use_custom_interval])) $message = '服务器添加成功'; else $error = '添加失败';
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? ''); $url = trim($_POST['url'] ?? ''); $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? ''); $icon_url = trim($_POST['icon_url'] ?? ''); $is_public = isset($_POST['is_public']) ? 1 : 0; $check_interval = intval($_POST['check_interval'] ?? 60); $use_custom_interval = isset($_POST['use_custom_interval']) ? 1 : 0; $status = intval($_POST['status'] ?? 1);
        if ($id > 0 && !empty($name) && !empty($url) && !empty($username)) {
            $url = rtrim($url, '/') . '/';
            if (!empty($password)) {
                $stmt = $db->prepare("UPDATE emby_servers SET name=?, url=?, username=?, password=?, icon_url=?, is_public=?, check_interval=?, use_custom_interval=?, status=? WHERE id=?");
                $stmt->execute([$name, $url, $username, encryptPassword($password), $icon_url, $is_public, $check_interval, $use_custom_interval, $status, $id]);
            } else {
                $stmt = $db->prepare("UPDATE emby_servers SET name=?, url=?, username=?, icon_url=?, is_public=?, check_interval=?, use_custom_interval=?, status=? WHERE id=?");
                $stmt->execute([$name, $url, $username, $icon_url, $is_public, $check_interval, $use_custom_interval, $status, $id]);
            }
            $message = '服务器更新成功';
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) { $db->prepare("DELETE FROM emby_servers WHERE id = ?")->execute([$id]); $message = '服务器删除成功'; }
    } elseif ($action === 'test') {
        $url = trim($_POST['url'] ?? ''); $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? '');
        if (!empty($url) && !empty($username) && !empty($password)) {
            $url = rtrim($url, '/');
            $payload = json_encode(['Username' => $username, 'Pw' => $password]);
            $auth_header = 'Emby UserId="", Client="Hills", Device="Hills Monitor", DeviceId="HillsMonitor", Version="1.0.0"';
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL => $url . '/Users/AuthenticateByName', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Emby-Authorization: ' . $auth_header, 'User-Agent: Hills/1.0.0'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
            $start = microtime(true); $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $time = round((microtime(true) - $start) * 1000); curl_close($ch);
            if ($httpCode == 200) {
                $data = json_decode($response, true);
                $user_name = $data['User']['Name'] ?? $username;
                $server_name = $data['ServerName'] ?? 'Unknown';
                if ($server_name == 'Unknown' && !empty($data['AccessToken'])) {
                    $ch2 = curl_init();
                    curl_setopt_array($ch2, [CURLOPT_URL => $url . '/System/Info', CURLOPT_HTTPHEADER => ['X-Emby-Token: ' . $data['AccessToken'], 'User-Agent: Hills/1.0.0'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
                    $info = curl_exec($ch2); curl_close($ch2);
                    $infoData = json_decode($info, true);
                    if (!empty($infoData['ServerName'])) $server_name = $infoData['ServerName'];
                }
                $message = "连接成功！响应: {$time}ms，服务器: {$server_name}，用户: {$user_name}";
            } else { $error = "认证失败！HTTP: {$httpCode}"; }
        }
    }
}

$servers = $db->query("
    SELECT s.*,
        CASE WHEN (SELECT COUNT(*) FROM (SELECT is_online FROM monitor_data WHERE server_id=s.id ORDER BY created_at DESC LIMIT 3) t WHERE t.is_online=1) > 0 THEN 1 WHEN (SELECT COUNT(*) FROM monitor_data WHERE server_id=s.id)=0 THEN NULL ELSE 0 END as last_status,
        (SELECT response_time FROM monitor_data WHERE server_id=s.id ORDER BY created_at DESC LIMIT 1) as last_response,
        (SELECT created_at FROM monitor_data WHERE server_id=s.id ORDER BY created_at DESC LIMIT 1) as last_check
    FROM emby_servers s ORDER BY s.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务器管理 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        .sidebar{min-height:100vh;background:linear-gradient(135deg,#667eea,#764ba2)}.sidebar .nav-link{color:rgba(255,255,255,.8);padding:12px 20px;border-radius:8px;margin:4px 0}.sidebar .nav-link:hover,.sidebar .nav-link.active{color:#fff;background:rgba(255,255,255,.2)}
        .status-badge{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px}.status-online{background:#10b981}.status-offline{background:#ef4444}
        .server-icon-preview{width:40px;height:40px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;overflow:hidden}.server-icon-preview img{width:100%;height:100%;object-fit:cover}
        .public-badge{background:#10b981;color:#fff;padding:2px 8px;border-radius:20px;font-size:11px}
    </style>
</head>
<body>
<div class="container-fluid"><div class="row">
    <nav class="col-md-2 d-md-block sidebar"><div class="position-sticky pt-4">
        <h4 class="text-white text-center mb-4"><i class="bi bi-stamp"></i> <?= htmlspecialchars($site_name) ?></h4>
        <div class="nav flex-column">
            <a class="nav-link" href="index.php"><i class="bi bi-speedometer2"></i> 仪表盘</a>
            <a class="nav-link active" href="servers.php"><i class="bi bi-server"></i> 服务器管理</a>
            <a class="nav-link" href="monitors.php"><i class="bi bi-hdd-network"></i> 监控机管理</a>
            <a class="nav-link" href="history.php"><i class="bi bi-clock-history"></i> 历史数据</a>
            <a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> 系统设置</a>
            <a class="nav-link" href="public.php" target="_blank"><i class="bi bi-eye"></i> 公开监控站</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> 退出登录</a>
        </div>
    </div></nav>
    <main class="col-md-10 ms-sm-auto px-md-4 py-4">
        <div class="d-flex justify-content-between mb-4"><h2>Emby服务器管理</h2><div><a href="public.php" class="btn btn-outline-success me-2" target="_blank"><i class="bi bi-eye"></i> 查看公开站</a><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#serverModal" onclick="resetForm()"><i class="bi bi-plus-circle"></i> 添加服务器</button></div></div>
        <?php if($message): ?><div class="alert alert-success alert-dismissible"><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger alert-dismissible"><?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0">
            <thead><tr><th></th><th>ID</th><th>名称</th><th>地址</th><th>用户名</th><th>间隔</th><th>状态</th><th>公开</th><th>最后检查</th><th>响应</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach($servers as $s): ?>
                <tr>
                    <td><div class="server-icon-preview"><?= !empty($s['icon_url']) ? '<img src="'.htmlspecialchars($s['icon_url']).'">' : '<i class="bi bi-hdd-stack"></i>' ?></div></td>
                    <td><?= $s['id'] ?></td><td><?= htmlspecialchars($s['name']) ?></td><td><span title="<?= htmlspecialchars($s['url']) ?>"><?= htmlspecialchars(substr($s['url'],0,30)) ?>...</span></td><td><?= htmlspecialchars($s['username']) ?></td>
                    <td><?= $s['check_interval'] ?>s<?= $s['use_custom_interval']?' <small class="text-success">独立</small>':' <small class="text-muted">全局</small>' ?></td>
                    <td><?= $s['status'] ? (isset($s['last_status']) ? '<span class="status-badge '.($s['last_status']?'status-online':'status-offline').'"></span>'.($s['last_status']?'在线':'离线') : '<span class="status-badge" style="background:#9ca3af"></span>未知') : '<span class="badge bg-secondary">已禁用</span>' ?></td>
                    <td><?= $s['is_public'] ? '<span class="public-badge"><i class="bi bi-eye"></i> 公开</span>' : '-' ?></td>
                    <td><?= $s['last_check'] ?? '-' ?></td>
                    <td class="<?= getLatencyClass($s['last_response']) ?>"><?= isset($s['last_response']) ? $s['last_response'].'ms' : '-' ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick='editServer(<?= json_encode($s) ?>)'><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-success" onclick="testServer('<?= $s['url'] ?>','<?= $s['username'] ?>')"><i class="bi bi-check-circle"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteServer(<?= $s['id'] ?>)"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div></div></div>
    </main>
</div></div>
<div class="modal fade" id="serverModal"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="modalTitle">添加服务器</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post"><div class="modal-body">
        <input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="id" id="serverId">
        <div class="row"><div class="col-md-8"><div class="mb-3"><label class="form-label">服务器名称 *</label><input type="text" class="form-control" name="name" id="serverName" required></div></div><div class="col-md-4"><div class="mb-3"><label class="form-label">检查间隔(秒)</label><input type="number" class="form-control" name="check_interval" id="serverInterval" value="60" min="10" max="3600"></div></div></div>
        <div class="mb-3"><label class="form-label">Emby地址 *</label><input type="url" class="form-control" name="url" id="serverUrl" placeholder="http://localhost:8096" required></div>
        <div class="row"><div class="col-md-6"><div class="mb-3"><label class="form-label">Emby用户名 *</label><input type="text" class="form-control" name="username" id="serverUsername" required></div></div><div class="col-md-6"><div class="mb-3"><label class="form-label">Emby密码 *</label><input type="password" class="form-control" name="password" id="serverPassword"><small class="text-muted" id="passwordHint">编辑时留空则保持原密码</small></div></div></div>
        <div class="mb-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="use_custom_interval" id="serverUseCustomInterval" value="1"><label class="form-check-label" for="serverUseCustomInterval">使用独立检查间隔</label></div><small class="text-muted">关闭则使用全局默认间隔</small></div>
        <div class="mb-3"><label class="form-label">图标链接</label><div class="input-group"><input type="url" class="form-control" name="icon_url" id="serverIconUrl" placeholder="https://example.com/icon.png"><button class="btn btn-outline-secondary" type="button" onclick="previewIcon()"><i class="bi bi-eye"></i></button></div><div id="iconPreview" class="mt-2" style="display:none"><img id="previewImg" src="" style="width:60px;height:60px;border-radius:10px;object-fit:cover"></div></div>
        <div class="mb-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_public" id="serverIsPublic" value="1"><label class="form-check-label" for="serverIsPublic"><i class="bi bi-eye"></i> 在公开监控站显示</label></div></div>
        <div class="mb-3" id="statusGroup" style="display:none"><label class="form-label">状态</label><select class="form-control" name="status" id="serverStatus"><option value="1">启用</option><option value="0">禁用</option></select></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-info" onclick="testConnection()"><i class="bi bi-database"></i> 测试连接</button><button type="submit" class="btn btn-primary">保存</button></div></form>
</div></div></div>
<form method="post" id="deleteForm" style="display:none"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
<form method="post" id="testForm" style="display:none"><input type="hidden" name="action" value="test"><input type="hidden" name="url" id="testUrl"><input type="hidden" name="username" id="testUsername"><input type="hidden" name="password" id="testPassword"></form>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var modal = new bootstrap.Modal(document.getElementById('serverModal'));
    function resetForm(){document.getElementById('modalTitle').textContent='添加服务器';document.getElementById('formAction').value='add';document.getElementById('serverId').value='';['serverName','serverUrl','serverUsername','serverPassword','serverIconUrl'].forEach(function(id){document.getElementById(id).value=''});document.getElementById('serverPassword').required=true;document.getElementById('passwordHint').style.display='inline';document.getElementById('serverInterval').value='60';document.getElementById('serverUseCustomInterval').checked=false;document.getElementById('serverIsPublic').checked=false;document.getElementById('statusGroup').style.display='none';document.getElementById('iconPreview').style.display='none';}
    function editServer(s){document.getElementById('modalTitle').textContent='编辑服务器';document.getElementById('formAction').value='edit';document.getElementById('serverId').value=s.id;document.getElementById('serverName').value=s.name;document.getElementById('serverUrl').value=s.url;document.getElementById('serverUsername').value=s.username;document.getElementById('serverPassword').value='';document.getElementById('serverPassword').required=false;document.getElementById('serverIconUrl').value=s.icon_url||'';document.getElementById('serverInterval').value=s.check_interval;document.getElementById('serverUseCustomInterval').checked=s.use_custom_interval==1;document.getElementById('serverIsPublic').checked=s.is_public==1;document.getElementById('serverStatus').value=s.status;document.getElementById('statusGroup').style.display='block';if(s.icon_url)previewIcon();modal.show();}
    function deleteServer(id){if(confirm('确定删除？')){document.getElementById('deleteId').value=id;document.getElementById('deleteForm').submit();}}
    function testServer(url,username){var p=prompt('请输入Emby密码:');if(!p)return;document.getElementById('testUrl').value=url;document.getElementById('testUsername').value=username;document.getElementById('testPassword').value=p;document.getElementById('testForm').submit();}
    function testConnection(){var u=document.getElementById('serverUrl').value,un=document.getElementById('serverUsername').value,pw=document.getElementById('serverPassword').value;if(!u||!un||!pw){alert('请填写地址、用户名和密码');return;}document.getElementById('testUrl').value=u;document.getElementById('testUsername').value=un;document.getElementById('testPassword').value=pw;document.getElementById('testForm').submit();}
    function previewIcon(){var u=document.getElementById('serverIconUrl').value;if(u){document.getElementById('previewImg').src=u;document.getElementById('iconPreview').style.display='block';}else{document.getElementById('iconPreview').style.display='none';}}
</script>
</body>
</html>