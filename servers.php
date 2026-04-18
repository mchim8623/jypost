<?php
// servers.php - 完整版（修复测试连接服务器名称显示问题）
require_once 'config.php';
requireLogin();

$db = getDB();
$message = $error = '';
$site_name = getConfig('site_name') ?: '集邮记';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $icon_url = trim($_POST['icon_url'] ?? '');
        $is_public = isset($_POST['is_public']) ? 1 : 0;
        $check_interval = intval($_POST['check_interval'] ?? 60);
        
        if (empty($name) || empty($url) || empty($username) || empty($password)) {
            $error = '请填写所有必填项';
        } else {
            $url = rtrim($url, '/') . '/';
            $encrypted_password = encryptPassword($password);
            $stmt = $db->prepare("INSERT INTO emby_servers (name, url, username, password, icon_url, is_public, check_interval) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $url, $username, $encrypted_password, $icon_url, $is_public, $check_interval])) {
                $message = '服务器添加成功';
            } else {
                $error = '添加失败';
            }
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $icon_url = trim($_POST['icon_url'] ?? '');
        $is_public = isset($_POST['is_public']) ? 1 : 0;
        $check_interval = intval($_POST['check_interval'] ?? 60);
        $status = intval($_POST['status'] ?? 1);
        
        if ($id > 0 && !empty($name) && !empty($url) && !empty($username)) {
            $url = rtrim($url, '/') . '/';
            if (!empty($password)) {
                $encrypted_password = encryptPassword($password);
                $stmt = $db->prepare("UPDATE emby_servers SET name=?, url=?, username=?, password=?, icon_url=?, is_public=?, check_interval=?, status=? WHERE id=?");
                $stmt->execute([$name, $url, $username, $encrypted_password, $icon_url, $is_public, $check_interval, $status, $id]);
            } else {
                $stmt = $db->prepare("UPDATE emby_servers SET name=?, url=?, username=?, icon_url=?, is_public=?, check_interval=?, status=? WHERE id=?");
                $stmt->execute([$name, $url, $username, $icon_url, $is_public, $check_interval, $status, $id]);
            }
            $message = '服务器更新成功';
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM emby_servers WHERE id = ?");
            $stmt->execute([$id]);
            $message = '服务器删除成功';
        }
    } elseif ($action === 'test') {
        $url = trim($_POST['url'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (!empty($url) && !empty($username) && !empty($password)) {
            $url = rtrim($url, '/');
            $auth_url = $url . '/Users/AuthenticateByName';
            
            $payload = json_encode([
                'Username' => $username,
                'Pw' => $password
            ]);
            
            $auth_header = 'Emby UserId="", Client="Hills", Device="Hills Monitor", DeviceId="HillsMonitor", Version="1.0.0"';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $auth_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'X-Emby-Authorization: ' . $auth_header,
                'User-Agent: Hills/1.0.0'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $start = microtime(true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $time = round((microtime(true) - $start) * 1000);
            $error_msg = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode == 200) {
                $data = json_decode($response, true);
                $user_name = $data['User']['Name'] ?? $username;
                
                $server_name = 'Unknown';
                
                if (!empty($data['ServerName'])) {
                    $server_name = $data['ServerName'];
                } elseif (!empty($data['User']['ServerName'])) {
                    $server_name = $data['User']['ServerName'];
                } else {
                    $token = $data['AccessToken'] ?? '';
                    if (!empty($token)) {
                        $info_url = $url . '/System/Info';
                        $ch2 = curl_init();
                        curl_setopt($ch2, CURLOPT_URL, $info_url);
                        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                            'X-Emby-Token: ' . $token,
                            'User-Agent: Hills/1.0.0'
                        ]);
                        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
                        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
                        
                        $info_response = curl_exec($ch2);
                        $info_httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                        curl_close($ch2);
                        
                        if ($info_httpCode == 200) {
                            $info_data = json_decode($info_response, true);
                            if (!empty($info_data['ServerName'])) {
                                $server_name = $info_data['ServerName'];
                            }
                        }
                    }
                }
                
                $message = "连接成功！响应时间: {$time}ms，服务器: {$server_name}，用户: {$user_name}";
            } else {
                $error_data = json_decode($response, true);
                if (isset($error_data['error'])) {
                    $error = "认证失败: " . $error_data['error'];
                } elseif (isset($error_data['Message'])) {
                    $error = "认证失败: " . $error_data['Message'];
                } else {
                    $error = "连接失败！HTTP状态码: {$httpCode}";
                }
                if ($error_msg) {
                    $error .= " (cURL错误: {$error_msg})";
                }
            }
        }
    }
}

$servers = $db->query("
    SELECT s.*, 
           (SELECT is_online FROM monitor_data WHERE server_id = s.id ORDER BY created_at DESC LIMIT 1) as last_status,
           (SELECT response_time FROM monitor_data WHERE server_id = s.id ORDER BY created_at DESC LIMIT 1) as last_response,
           (SELECT created_at FROM monitor_data WHERE server_id = s.id ORDER BY created_at DESC LIMIT 1) as last_check
    FROM emby_servers s ORDER BY s.id DESC
")->fetchAll();

$edit_server = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM emby_servers WHERE id = ?");
    $stmt->execute([intval($_GET['edit'])]);
    $edit_server = $stmt->fetch();
    if ($edit_server) {
        $edit_server['password'] = '';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务器管理 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .sidebar .nav-link { color: rgba(255,255,255,.8); padding: 12px 20px; border-radius: 8px; margin: 4px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,.2); }
        .status-badge { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-online { background-color: #10b981; } .status-offline { background-color: #ef4444; }
        .server-icon-preview { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; }
        .public-badge { background: #10b981; color: white; padding: 2px 8px; border-radius: 20px; font-size: 11px; }
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
                        <a class="nav-link active" href="servers.php"><i class="bi bi-server"></i> 服务器管理</a>
                        <a class="nav-link" href="monitors.php"><i class="bi bi-hdd-network"></i> 监控机管理</a>
                        <a class="nav-link" href="history.php"><i class="bi bi-clock-history"></i> 历史数据</a>
                        <a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> 系统设置</a>
                        <a class="nav-link" href="public.php" target="_blank"><i class="bi bi-eye"></i> 公开监控站</a>
                        <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> 退出登录</a>
                    </div>
                </div>
            </nav>

            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Emby服务器管理</h2>
                    <div>
                        <a href="public.php" class="btn btn-outline-success me-2" target="_blank"><i class="bi bi-eye"></i> 查看公开站</a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#serverModal" onclick="resetForm()"><i class="bi bi-plus-circle"></i> 添加服务器</button>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:50px"></th>
                                        <th>ID</th>
                                        <th>名称</th>
                                        <th>地址</th>
                                        <th>用户名</th>
                                        <th>间隔</th>
                                        <th>状态</th>
                                        <th>公开</th>
                                        <th>最后检查</th>
                                        <th>响应</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($servers as $server): ?>
                                    <tr>
                                        <td class="text-center">
                                            <div class="server-icon-preview">
                                                <?php if (!empty($server['icon_url'])): ?>
                                                <img src="<?= htmlspecialchars($server['icon_url']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:8px;" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'bi bi-hdd-stack\'></i>'">
                                                <?php else: ?>
                                                <i class="bi bi-hdd-stack"></i>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= $server['id'] ?></td>
                                        <td><?= htmlspecialchars($server['name']) ?></td>
                                        <td><span title="<?= htmlspecialchars($server['url']) ?>"><?= htmlspecialchars(substr($server['url'], 0, 30)) ?>...</span></td>
                                        <td><?= htmlspecialchars($server['username']) ?></td>
                                        <td><?= $server['check_interval'] ?>秒</td>
                                        <td>
                                            <?php if ($server['status']): ?>
                                                <?php if (isset($server['last_status'])): ?>
                                                    <span class="status-badge <?= $server['last_status'] ? 'status-online' : 'status-offline' ?>"></span>
                                                    <?= $server['last_status'] ? '在线' : '离线' ?>
                                                <?php else: ?>
                                                    <span class="status-badge" style="background:#9ca3af"></span>未知
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">已禁用</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $server['is_public'] ? '<span class="public-badge"><i class="bi bi-eye"></i> 公开</span>' : '<span class="text-muted">-</span>' ?></td>
                                        <td><?= $server['last_check'] ?? '-' ?></td>
                                        <td class="<?= getLatencyClass($server['last_response']) ?>"><?= isset($server['last_response']) ? $server['last_response'].'ms' : '-' ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick='editServer(<?= json_encode($server) ?>)'><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-success" onclick="testServer('<?= $server['url'] ?>', '<?= $server['username'] ?>')"><i class="bi bi-check-circle"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteServer(<?= $server['id'] ?>)"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($servers)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4 text-muted">
                                            暂无服务器，点击"添加服务器"开始监控
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="serverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">添加服务器</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="serverForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="serverId">
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">服务器名称 *</label>
                                    <input type="text" class="form-control" name="name" id="serverName" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">检查间隔 (秒)</label>
                                    <input type="number" class="form-control" name="check_interval" id="serverInterval" value="60" min="10" max="3600">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Emby地址 *</label>
                            <input type="url" class="form-control" name="url" id="serverUrl" placeholder="http://localhost:8096" required>
                            <small class="text-muted">例如: http://192.168.1.100:8096</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Emby 用户名 *</label>
                                    <input type="text" class="form-control" name="username" id="serverUsername" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Emby 密码 *</label>
                                    <input type="password" class="form-control" name="password" id="serverPassword">
                                    <small class="text-muted" id="passwordHint">编辑时留空则保持原密码</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">图标链接</label>
                            <div class="input-group">
                                <input type="url" class="form-control" name="icon_url" id="serverIconUrl" placeholder="https://example.com/icon.png">
                                <button class="btn btn-outline-secondary" type="button" onclick="previewIcon()">
                                    <i class="bi bi-eye"></i> 预览
                                </button>
                            </div>
                            <small class="text-muted">可选，支持图片URL，建议正方形图片</small>
                            <div id="iconPreview" class="mt-2" style="display:none;">
                                <img id="previewImg" src="" style="width:60px;height:60px;border-radius:10px;object-fit:cover;" onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'60\' height=\'60\'%3E%3Crect width=\'60\' height=\'60\' fill=\'%23ddd\'/%3E%3Ctext x=\'30\' y=\'35\' text-anchor=\'middle\' fill=\'%23999\' font-size=\'12\'%3E错误%3C/text%3E%3C/svg%3E'">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_public" id="serverIsPublic" value="1">
                                <label class="form-check-label" for="serverIsPublic">
                                    <i class="bi bi-eye"></i> 在公开监控站显示
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3" id="statusGroup" style="display:none;">
                            <label class="form-label">状态</label>
                            <select class="form-control" name="status" id="serverStatus">
                                <option value="1">启用</option>
                                <option value="0">禁用</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-info" onclick="testConnection()">
                            <i class="bi bi-database"></i> 测试连接
                        </button>
                        <button type="submit" class="btn btn-primary">保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="post" id="deleteForm" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <form method="post" id="testForm" style="display:none;">
        <input type="hidden" name="action" value="test">
        <input type="hidden" name="url" id="testUrl">
        <input type="hidden" name="username" id="testUsername">
        <input type="hidden" name="password" id="testPassword">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const modal = new bootstrap.Modal(document.getElementById('serverModal'));
        
        function resetForm() {
            document.getElementById('modalTitle').textContent = '添加服务器';
            document.getElementById('formAction').value = 'add';
            document.getElementById('serverId').value = '';
            document.getElementById('serverName').value = '';
            document.getElementById('serverUrl').value = '';
            document.getElementById('serverUsername').value = '';
            document.getElementById('serverPassword').value = '';
            document.getElementById('serverPassword').required = true;
            document.getElementById('passwordHint').style.display = 'inline';
            document.getElementById('serverIconUrl').value = '';
            document.getElementById('serverInterval').value = '60';
            document.getElementById('serverIsPublic').checked = false;
            document.getElementById('statusGroup').style.display = 'none';
            document.getElementById('iconPreview').style.display = 'none';
        }
        
        function editServer(server) {
            document.getElementById('modalTitle').textContent = '编辑服务器';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('serverId').value = server.id;
            document.getElementById('serverName').value = server.name;
            document.getElementById('serverUrl').value = server.url;
            document.getElementById('serverUsername').value = server.username;
            document.getElementById('serverPassword').value = '';
            document.getElementById('serverPassword').required = false;
            document.getElementById('passwordHint').style.display = 'inline';
            document.getElementById('serverIconUrl').value = server.icon_url || '';
            document.getElementById('serverInterval').value = server.check_interval;
            document.getElementById('serverIsPublic').checked = server.is_public == 1;
            document.getElementById('serverStatus').value = server.status;
            document.getElementById('statusGroup').style.display = 'block';
            if (server.icon_url) previewIcon();
            modal.show();
        }
        
        function deleteServer(id) {
            if (confirm('确定要删除这个服务器吗？所有相关监控数据也将被删除。')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
        
        function testServer(url, username) {
            const password = prompt('请输入 Emby 密码进行测试:');
            if (!password) return;
            
            document.getElementById('testUrl').value = url;
            document.getElementById('testUsername').value = username;
            document.getElementById('testPassword').value = password;
            document.getElementById('testForm').submit();
        }
        
        function testConnection() {
            const url = document.getElementById('serverUrl').value;
            const username = document.getElementById('serverUsername').value;
            const password = document.getElementById('serverPassword').value;
            
            if (!url || !username || !password) {
                alert('请填写 Emby 地址、用户名和密码');
                return;
            }
            
            document.getElementById('testUrl').value = url;
            document.getElementById('testUsername').value = username;
            document.getElementById('testPassword').value = password;
            document.getElementById('testForm').submit();
        }
        
        function previewIcon() {
            const url = document.getElementById('serverIconUrl').value;
            if (url) {
                document.getElementById('previewImg').src = url;
                document.getElementById('iconPreview').style.display = 'block';
            } else {
                document.getElementById('iconPreview').style.display = 'none';
            }
        }
    </script>
</body>
</html>