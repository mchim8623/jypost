<?php
require_once 'config.php';

header('Content-Type: application/json');

$db = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function authenticateMonitor() {
    $headers = getallheaders();
    $token = $headers['X-Monitor-Token'] ?? $_GET['token'] ?? '';
    
    if (empty($token)) {
        http_response_code(401);
        die(json_encode(['error' => '缺少认证令牌']));
    }
    
    global $db;
    $stmt = $db->prepare("SELECT id, name FROM monitors WHERE token = ? AND status = 1");
    $stmt->execute([$token]);
    $monitor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$monitor) {
        http_response_code(401);
        die(json_encode(['error' => '无效的认证令牌']));
    }
    
    return $monitor;
}

function sendWebhookAlert($server_id, $type, $message) {
    global $db;
    
    $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = 'alert_webhook'");
    $stmt->execute();
    $webhook = $stmt->fetchColumn();
    if (empty($webhook)) return;
    
    $stmt = $db->prepare("SELECT name FROM emby_servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_name = $stmt->fetchColumn();
    
    $payload = ['title' => '集邮记监控告警', 'content' => "服务器: {$server_name}\n类型: {$type}\n信息: {$message}\n时间: " . date('Y-m-d H:i:s')];
    
    $ch = curl_init($webhook);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'User-Agent: Hills/1.0.0']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

switch ($action) {
    case 'config':
        $monitor = authenticateMonitor();
        
        $stmt = $db->prepare("UPDATE monitors SET last_heartbeat = NOW(), ip = ? WHERE id = ?");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $monitor['id']]);
        
        $servers = $db->query("SELECT id, name, url, username, password, check_interval FROM emby_servers WHERE status = 1")->fetchAll(PDO::FETCH_ASSOC);
        
        // 解密密码
        foreach ($servers as &$server) {
            $server['password'] = decryptPassword($server['password']);
        }
        
        $config = [];
        $result = $db->query("SELECT config_key, config_value FROM system_config");
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $config[$row['config_key']] = $row['config_value'];
        }
        
        echo json_encode(['success' => true, 'monitor_id' => $monitor['id'], 'servers' => $servers, 'config' => $config]);
        break;
        
    case 'report':
        if ($method !== 'POST') {
            http_response_code(405);
            die(json_encode(['error' => 'Method not allowed']));
        }
        
        $monitor = authenticateMonitor();
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['server_id']) || !isset($input['is_online'])) {
            http_response_code(400);
            die(json_encode(['error' => '缺少必要参数']));
        }
        
        $stmt = $db->prepare("INSERT INTO monitor_data (monitor_id, server_id, is_online, response_time, library_count, library_details, item_counts, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$monitor['id'], $input['server_id'], $input['is_online'] ? 1 : 0, $input['response_time'] ?? null, $input['library_count'] ?? null, $input['library_details'] ?? null, $input['item_counts'] ?? null, $input['error_message'] ?? null]);
        
        if (!$input['is_online']) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM alert_logs WHERE server_id = ? AND alert_type = 'offline' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
$stmt->execute([$input['server_id']]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $db->prepare("INSERT INTO alert_logs (server_id, monitor_id, alert_type, message) VALUES (?, ?, 'offline', ?)");
                $stmt->execute([$input['server_id'], $monitor['id'], $input['error_message'] ?? '服务器离线']);
                sendWebhookAlert($input['server_id'], 'offline', $input['error_message']);
            }
        }
        
        echo json_encode(['success' => true]);
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action']);
}