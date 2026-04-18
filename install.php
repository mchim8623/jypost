<?php
// install.php - 自动安装程序
session_start();

define('INSTALL_LOCK', __DIR__ . '/install.lock');

if (file_exists(INSTALL_LOCK)) {
    die('系统已安装，如需重新安装请删除 install.lock 文件');
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'test_connection') {
        $host = $_POST['db_host'] ?? '';
        $port = $_POST['db_port'] ?? '3306';
        $name = $_POST['db_name'] ?? '';
        $user = $_POST['db_user'] ?? '';
        $pass = $_POST['db_pass'] ?? '';
        
        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$name'");
            $exists = $stmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'db_exists' => !empty($exists),
                'message' => '数据库连接成功'
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => '数据库连接失败: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($action === 'install') {
        $host = $_POST['db_host'] ?? '';
        $port = $_POST['db_port'] ?? '3306';
        $name = $_POST['db_name'] ?? '';
        $user = $_POST['db_user'] ?? '';
        $pass = $_POST['db_pass'] ?? '';
        $admin_user = $_POST['admin_user'] ?? 'admin';
        $admin_pass = $_POST['admin_pass'] ?? '';
        $site_url = $_POST['site_url'] ?? '';
        $site_name = $_POST['site_name'] ?? '集邮记';
        
        if (strlen($admin_pass) < 6) {
            $error = '管理员密码至少6位';
        } else {
            try {
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $pdo->exec("CREATE DATABASE IF NOT EXISTS $name DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE $name");
                
                $sql = getDatabaseSchema();
                $statements = parseSQL($sql);
                
                foreach ($statements as $statement) {
                    if (trim($statement)) {
                        $pdo->exec($statement);
                    }
                }
                
                $admin_pass_hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
                $stmt->execute([$admin_user, $admin_pass_hash]);
                
                $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = 'site_url'");
                $stmt->execute([$site_url]);
                $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = 'site_name'");
                $stmt->execute([$site_name]);
                
                $configContent = getConfigTemplate($host, $port, $name, $user, $pass, $site_url);
                file_put_contents(__DIR__ . '/config.php', $configContent);
                
                file_put_contents(INSTALL_LOCK, date('Y-m-d H:i:s'));
                
                $success = '安装成功！';
                header("Refresh: 3; url=login.php");
                
            } catch (PDOException $e) {
                $error = '安装失败: ' . $e->getMessage();
            }
        }
    }
}

function getDatabaseSchema() {
    return <<<'SQL'
-- 用户表
CREATE TABLE IF NOT EXISTS users (
  id int(11) NOT NULL AUTO_INCREMENT,
  username varchar(50) NOT NULL,
  password varchar(255) NOT NULL,
  role varchar(20) DEFAULT 'user',
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 监控机表
CREATE TABLE IF NOT EXISTS monitors (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  token varchar(64) NOT NULL,
  ip varchar(45) DEFAULT NULL,
  status tinyint(1) DEFAULT '1',
  last_heartbeat datetime DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Emby服务器配置表（使用用户名密码）
CREATE TABLE IF NOT EXISTS emby_servers (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  url varchar(255) NOT NULL,
  username varchar(100) NOT NULL,
  password varchar(255) NOT NULL,
  icon_url varchar(500) DEFAULT NULL,
  is_public tinyint(1) DEFAULT '0',
  check_interval int(11) DEFAULT '60',
  status tinyint(1) DEFAULT '1',
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 监控数据表
CREATE TABLE IF NOT EXISTS monitor_data (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  monitor_id int(11) NOT NULL,
  server_id int(11) NOT NULL,
  is_online tinyint(1) DEFAULT '0',
  response_time int(11) DEFAULT NULL,
  library_count int(11) DEFAULT NULL,
  library_details text,
  item_counts text,
  error_message varchar(255) DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_server_time (server_id,created_at),
  KEY idx_monitor_time (monitor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 系统配置表
CREATE TABLE IF NOT EXISTS system_config (
  id int(11) NOT NULL AUTO_INCREMENT,
  config_key varchar(50) NOT NULL,
  config_value text,
  description varchar(255) DEFAULT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 告警记录表
CREATE TABLE IF NOT EXISTS alert_logs (
  id int(11) NOT NULL AUTO_INCREMENT,
  server_id int(11) NOT NULL,
  monitor_id int(11) NOT NULL,
  alert_type varchar(20) NOT NULL,
  message varchar(255) DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_server_alert (server_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO system_config (config_key, config_value, description) VALUES
('site_name', '集邮记', '站点名称'),
('site_url', '', '站点地址'),
('data_retention_days', '30', '数据保留天数'),
('alert_webhook', '', '告警Webhook地址'),
('default_check_interval', '60', '默认检查间隔(秒)');

SQL;
}

function parseSQL($sql) {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/--.*$/m', '', $sql);
    $statements = [];
    $current = '';
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $current .= $line . "\n";
        if (substr($line, -1) == ';') {
            $statements[] = trim($current);
            $current = '';
        }
    }
    
    if (!empty(trim($current))) {
        $statements[] = trim($current);
    }
    
    return $statements;
}

function getConfigTemplate($host, $port, $name, $user, $pass, $site_url) {
    $secret_key = bin2hex(random_bytes(32));
    $date = date('Y-m-d H:i:s');
    return <<<PHP
<?php
// config.php - 集邮记配置文件
// 生成时间: {$date}
define('DB_HOST', '{$host}');
define('DB_PORT', '{$port}');
define('DB_NAME', '{$name}');
define('DB_USER', '{$user}');
define('DB_PASS', '{$pass}');
define('SITE_URL', '{$site_url}');
define('SITE_NAME', '集邮记');
define('SECRET_KEY', '{$secret_key}');

session_start();

function getDB() {
    static \$db = null;
    if (\$db === null) {
        try {
            \$dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            \$db = new PDO(\$dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException \$e) {
            die("数据库连接失败: " . \$e->getMessage());
        }
    }
    return \$db;
}

function isLoggedIn() {
    return isset(\$_SESSION['logged_in']) && \$_SESSION['logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function getConfig(\$key) {
    static \$config = null;
    if (\$config === null) {
        \$db = getDB();
        \$stmt = \$db->query("SELECT config_key, config_value FROM system_config");
        \$config = [];
        while (\$row = \$stmt->fetch()) {
            \$config[\$row['config_key']] = \$row['config_value'];
        }
    }
    return \$config[\$key] ?? null;
}

function formatNumber(\$num) {
    if (\$num === null) return '0';
    if (\$num >= 10000000) return round(\$num / 10000000, 1) . 'kw';
    if (\$num >= 10000) return round(\$num / 10000, 1) . 'w';
    if (\$num >= 1000) return round(\$num / 1000, 1) . 'k';
    return \$num;
}

function getLatencyClass(\$ms) {
    if (\$ms === null) return 'latency-unknown';
    if (\$ms < 100) return 'latency-excellent';
    if (\$ms < 200) return 'latency-good';
    if (\$ms < 500) return 'latency-normal';
    if (\$ms < 1000) return 'latency-slow';
    return 'latency-very-slow';
}

function getLatencyText(\$ms) {
    if (\$ms === null) return '未知';
    if (\$ms < 100) return '极快';
    if (\$ms < 200) return '良好';
    if (\$ms < 500) return '一般';
    if (\$ms < 1000) return '较慢';
    return '很慢';
}

function encryptPassword(\$plaintext) {
    \$key = substr(hash('sha256', SECRET_KEY, true), 0, 32);
    \$iv = openssl_random_pseudo_bytes(16);
    \$ciphertext = openssl_encrypt(\$plaintext, 'AES-256-CBC', \$key, OPENSSL_RAW_DATA, \$iv);
    return base64_encode(\$iv . \$ciphertext);
}

function decryptPassword(\$ciphertext) {
    \$key = substr(hash('sha256', SECRET_KEY, true), 0, 32);
    \$data = base64_decode(\$ciphertext);
    \$iv = substr(\$data, 0, 16);
    \$ciphertext = substr(\$data, 16);
    return openssl_decrypt(\$ciphertext, 'AES-256-CBC', \$key, OPENSSL_RAW_DATA, \$iv);
}
PHP;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集邮记 - 安装向导</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; padding: 20px 0; }
        .install-card { background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        .install-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .install-body { padding: 30px; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 30px; }
.step { width: 40px; height: 40px; border-radius: 50%; background: #e9ecef; color: #6c757d; display: flex; align-items: center; justify-content: center; font-weight: bold; margin: 0 10px; }
        .step.active { background: #667eea; color: white; }
        .step.completed { background: #10b981; color: white; }
        .step-line { width: 60px; height: 2px; background: #e9ecef; align-self: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="install-card">
                    <div class="install-header">
                        <h2><i class="bi bi-stamp"></i> 集邮记</h2>
                        <p class="mb-0">Emby监控系统 - 安装向导</p>
                    </div>
                    <div class="install-body">
                        <div class="step-indicator">
                            <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'completed' : '' ?>"><?= $step > 1 ? '<i class="bi bi-check"></i>' : '1' ?></div>
                            <div class="step-line"></div>
                            <div class="step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'completed' : '' ?>"><?= $step > 2 ? '<i class="bi bi-check"></i>' : '2' ?></div>
                            <div class="step-line"></div>
                            <div class="step <?= $step >= 3 ? 'active' : '' ?> <?= $step > 3 ? 'completed' : '' ?>"><?= $step > 3 ? '<i class="bi bi-check"></i>' : '3' ?></div>
                        </div>
                        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                        <?php if ($success): ?><div class="alert alert-success"><?= $success ?><br>正在跳转到登录页面...</div><?php endif; ?>
                        <?php if (!$success): ?>
                        <?php if ($step == 1): ?>
                        <h4 class="mb-4">环境检查</h4>
                        <table class="table">
                            <thead><tr><th>检查项</th><th>要求</th><th>当前状态</th></tr></thead>
                            <tbody>
                                <?php
                                $php_ok = version_compare(phpversion(), '7.4.0', '>=');
                                $pdo_ok = extension_loaded('pdo') && extension_loaded('pdo_mysql');
                                $curl_ok = extension_loaded('curl');
                                $json_ok = extension_loaded('json');
                                $mbstring_ok = extension_loaded('mbstring');
                                $openssl_ok = extension_loaded('openssl');
                                $writable_ok = is_writable(__DIR__);
                                $all_ok = $php_ok && $pdo_ok && $curl_ok && $json_ok && $mbstring_ok && $openssl_ok && $writable_ok;
                                ?>
                                <tr><td>PHP版本</td><td>≥ 7.4.0</td><td><span class="badge bg-<?= $php_ok ? 'success' : 'danger' ?>"><?= phpversion() ?></span></td></tr>
                                <tr><td>PDO扩展</td><td>已安装</td><td><span class="badge bg-<?= $pdo_ok ? 'success' : 'danger' ?>"><?= $pdo_ok ? '已安装' : '未安装' ?></span></td></tr>
                                <tr><td>cURL扩展</td><td>已安装</td><td><span class="badge bg-<?= $curl_ok ? 'success' : 'danger' ?>"><?= $curl_ok ? '已安装' : '未安装' ?></span></td></tr>
                                <tr><td>JSON扩展</td><td>已安装</td><td><span class="badge bg-<?= $json_ok ? 'success' : 'danger' ?>"><?= $json_ok ? '已安装' : '未安装' ?></span></td></tr>
                                <tr><td>Mbstring扩展</td><td>已安装</td><td><span class="badge bg-<?= $mbstring_ok ? 'success' : 'danger' ?>"><?= $mbstring_ok ? '已安装' : '未安装' ?></span></td></tr>
                                <tr><td>OpenSSL扩展</td><td>已安装</td><td><span class="badge bg-<?= $openssl_ok ? 'success' : 'danger' ?>"><?= $openssl_ok ? '已安装' : '未安装' ?></span></td></tr>
<tr><td>目录写入权限</td><td>可写</td><td><span class="badge bg-<?= $writable_ok ? 'success' : 'danger' ?>"><?= $writable_ok ? '可写' : '不可写' ?></span></td></tr>
                            </tbody>
                        </table>
                        <div class="d-grid gap-2">
                            <?php if ($all_ok): ?><a href="?step=2" class="btn btn-primary btn-lg">下一步 <i class="bi bi-arrow-right"></i></a>
                            <?php else: ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> 请先满足所有环境要求后再继续安装</div><?php endif; ?>
                        </div>
                        <?php elseif ($step == 2): ?>
                        <h4 class="mb-4">数据库配置</h4>
                        <form id="dbForm">
                            <div class="row mb-3"><div class="col-md-8"><label class="form-label">数据库主机</label><input type="text" class="form-control" name="db_host" value="localhost" required></div><div class="col-md-4"><label class="form-label">端口</label><input type="text" class="form-control" name="db_port" value="3306" required></div></div>
                            <div class="mb-3"><label class="form-label">数据库名称</label><input type="text" class="form-control" name="db_name" value="jypost" required></div>
                            <div class="mb-3"><label class="form-label">数据库用户名</label><input type="text" class="form-control" name="db_user" value="root" required></div>
                            <div class="mb-3"><label class="form-label">数据库密码</label><input type="password" class="form-control" name="db_pass"></div>
                            <div class="mb-3"><button type="button" class="btn btn-outline-primary" id="testConnection"><i class="bi bi-database"></i> 测试连接</button><span id="testResult" class="ms-2"></span></div>
                            <hr><h5 class="mb-3">站点设置</h5>
                            <div class="mb-3"><label class="form-label">站点名称</label><input type="text" class="form-control" name="site_name" value="集邮记" required></div>
                            <div class="mb-3"><label class="form-label">站点地址</label><input type="url" class="form-control" name="site_url" id="site_url" required></div>
                            <hr><h5 class="mb-3">管理员账户</h5>
                            <div class="mb-3"><label class="form-label">用户名</label><input type="text" class="form-control" name="admin_user" value="admin" required></div>
                            <div class="mb-3"><label class="form-label">密码</label><input type="password" class="form-control" name="admin_pass" required></div>
                            <div class="mb-3"><label class="form-label">确认密码</label><input type="password" class="form-control" name="admin_pass_confirm" required></div>
                            <div class="d-flex justify-content-between"><a href="?step=1" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> 上一步</a><button type="submit" class="btn btn-primary">开始安装 <i class="bi bi-arrow-right"></i></button></div>
                        </form>
                        <form id="installForm" method="post" style="display:none;"><input type="hidden" name="action" value="install"><input type="hidden" name="db_host" id="install_db_host"><input type="hidden" name="db_port" id="install_db_port"><input type="hidden" name="db_name" id="install_db_name"><input type="hidden" name="db_user" id="install_db_user"><input type="hidden" name="db_pass" id="install_db_pass"><input type="hidden" name="site_name" id="install_site_name"><input type="hidden" name="admin_user" id="install_admin_user"><input type="hidden" name="admin_pass" id="install_admin_pass"><input type="hidden" name="site_url" id="install_site_url"></form>
                        <script>
                        document.getElementById('site_url').value = window.location.origin;
document.getElementById('testConnection').addEventListener('click', function() {
                            const formData = new FormData(document.getElementById('dbForm')); formData.append('action', 'test_connection');
                            const resultSpan = document.getElementById('testResult'); resultSpan.innerHTML = '<span class="text-info"><i class="bi bi-hourglass-split"></i> 正在测试...</span>';
                            fetch('install.php', { method: 'POST', body: formData }).then(r => r.json()).then(d => {
                                if (d.success) resultSpan.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> ' + d.message + '</span>';
                                else resultSpan.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + d.message + '</span>';
                            }).catch(() => resultSpan.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> 请求失败</span>');
                        });
                        document.getElementById('dbForm').addEventListener('submit', function(e) {
                            e.preventDefault();
                            const pwd = this.querySelector('[name="admin_pass"]').value, confirm = this.querySelector('[name="admin_pass_confirm"]').value;
                            if (pwd !== confirm) { alert('两次输入的密码不一致'); return; }
                            if (pwd.length < 6) { alert('密码至少6位'); return; }
                            document.getElementById('install_db_host').value = this.querySelector('[name="db_host"]').value;
                            document.getElementById('install_db_port').value = this.querySelector('[name="db_port"]').value;
                            document.getElementById('install_db_name').value = this.querySelector('[name="db_name"]').value;
                            document.getElementById('install_db_user').value = this.querySelector('[name="db_user"]').value;
                            document.getElementById('install_db_pass').value = this.querySelector('[name="db_pass"]').value;
                            document.getElementById('install_site_name').value = this.querySelector('[name="site_name"]').value;
                            document.getElementById('install_admin_user').value = this.querySelector('[name="admin_user"]').value;
                            document.getElementById('install_admin_pass').value = pwd;
                            document.getElementById('install_site_url').value = this.querySelector('[name="site_url"]').value;
                            document.getElementById('installForm').submit();
                        });
                        </script>
                        <?php endif; ?><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>