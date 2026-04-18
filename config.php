<?php
// config.php - 集邮记配置文件
// 生成时间: 2026-04-17 22:06:06
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'jypost');
define('DB_USER', 'jypost');
define('DB_PASS', 'test');
define('SITE_URL', 'http://161.129.35.23:1315');
define('SITE_NAME', '集邮记');
define('SECRET_KEY', 'd6d95ceddd59fc816b64f1326076f37549a2c1a9504f2bac2f754f6c6845b456');

session_start();

function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $db = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    return $db;
}

function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
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

function getConfig($key) {
    static $config = null;
    if ($config === null) {
        $db = getDB();
        $stmt = $db->query("SELECT config_key, config_value FROM system_config");
        $config = [];
        while ($row = $stmt->fetch()) {
            $config[$row['config_key']] = $row['config_value'];
        }
    }
    return $config[$key] ?? null;
}

function formatNumber($num) {
    if ($num === null) return '0';
    if ($num >= 10000000) return round($num / 10000000, 1) . 'kw';
    if ($num >= 10000) return round($num / 10000, 1) . 'w';
    if ($num >= 1000) return round($num / 1000, 1) . 'k';
    return $num;
}

function getLatencyClass($ms) {
    if ($ms === null) return 'latency-unknown';
    if ($ms < 100) return 'latency-excellent';
    if ($ms < 200) return 'latency-good';
    if ($ms < 500) return 'latency-normal';
    if ($ms < 1000) return 'latency-slow';
    return 'latency-very-slow';
}

function getLatencyText($ms) {
    if ($ms === null) return '未知';
    if ($ms < 100) return '极快';
    if ($ms < 200) return '良好';
    if ($ms < 500) return '一般';
    if ($ms < 1000) return '较慢';
    return '很慢';
}

function encryptPassword($plaintext) {
    $key = substr(hash('sha256', SECRET_KEY, true), 0, 32);
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ciphertext);
}

function decryptPassword($ciphertext) {
    $key = substr(hash('sha256', SECRET_KEY, true), 0, 32);
    $data = base64_decode($ciphertext);
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}