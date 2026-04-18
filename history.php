<?php
require_once 'config.php';
requireLogin();

$db = getDB();
$site_name = getConfig('site_name') ?: '集邮记';

$server_id = intval($_GET['server_id'] ?? 0);
$monitor_id = intval($_GET['monitor_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50; $offset = ($page - 1) * $limit;

$where = ["1=1"]; $params = [];
if ($server_id > 0) { $where[] = "md.server_id = ?"; $params[] = $server_id; }
if ($monitor_id > 0) { $where[] = "md.monitor_id = ?"; $params[] = $monitor_id; }
if (!empty($date)) { $where[] = "DATE(md.created_at) = ?"; $params[] = $date; }
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT COUNT(*) FROM monitor_data md WHERE $whereSql"); $stmt->execute($params); $total = $stmt->fetchColumn(); $totalPages = ceil($total / $limit);
$sql = "SELECT md.*, es.name as server_name, m.name as monitor_name FROM monitor_data md JOIN emby_servers es ON md.server_id = es.id JOIN monitors m ON md.monitor_id = m.id WHERE $whereSql ORDER BY md.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll();

$servers = $db->query("SELECT id, name FROM emby_servers ORDER BY name")->fetchAll();
$monitors = $db->query("SELECT id, name FROM monitors ORDER BY name")->fetchAll();

$stats = [];
if ($server_id > 0 && $monitor_id > 0 && !empty($date)) {
    $stmt = $db->prepare("SELECT COUNT(*) as total_checks, SUM(CASE WHEN is_online = 1 THEN 1 ELSE 0 END) as online_count, AVG(response_time) as avg_response, MIN(response_time) as min_response, MAX(response_time) as max_response FROM monitor_data md WHERE $whereSql");
    $stmt->execute($params); $stats = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>历史数据 - <?= htmlspecialchars($site_name) ?> 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .sidebar .nav-link { color: rgba(255,255,255,.8); padding: 12px 20px; border-radius: 8px; margin: 4px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,.2); }
        .status-badge { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-online { background-color: #10b981; } .status-offline { background-color: #ef4444; }
        .stat-card { border-radius: 10px; border: none; background: #f8f9fa; }
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
                        <a class="nav-link active" href="history.php"><i class="bi bi-clock-history"></i> 历史数据</a>
                        <a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> 系统设置</a>
                        <a class="nav-link" href="public.php" target="_blank"><i class="bi bi-eye"></i> 公开监控站</a>
                        <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> 退出登录</a>
                    </div>
                </div>
            </nav>
            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <h2 class="mb-4">历史监控数据</h2>
                <div class="card mb-4"><div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-3"><label class="form-label">服务器</label><select class="form-select" name="server_id"><option value="">全部服务器</option><?php foreach ($servers as $s): ?><option value="<?= $s['id'] ?>" <?= $server_id == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><label class="form-label">监控机</label><select class="form-select" name="monitor_id"><option value="">全部监控机</option><?php foreach ($monitors as $m): ?><option value="<?= $m['id'] ?>" <?= $monitor_id == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><label class="form-label">日期</label><input type="date" class="form-control" name="date" value="<?= $date ?>"></div>
                        <div class="col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> 查询</button></div>
                    </form>
                </div></div>
                <?php if (!empty($stats)): ?>
                <div class="row mb-4">
                    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>总检查次数</h6><h3><?= number_format($stats['total_checks']) ?></h3></div></div></div>
                    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>在线率</h6><h3><?= $stats['total_checks'] > 0 ? round($stats['online_count'] / $stats['total_checks'] * 100, 2) : 0 ?>%</h3></div></div></div>
                    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>平均响应</h6><h3><?= round($stats['avg_response'] ?? 0) ?>ms</h3></div></div></div>
                    <div class="col-md-3"><div class="card stat-card"><div class="card-body"><h6>响应范围</h6><h3><?= round($stats['min_response'] ?? 0) ?> - <?= round($stats['max_response'] ?? 0) ?>ms</h3></div></div></div>
                </div>
                <?php endif; ?>
                <div class="card"><div class="card-body p-0"><div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>时间</th><th>服务器</th><th>监控机</th><th>状态</th><th>响应时间</th><th>媒体库数</th><th>错误信息</th></tr></thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                            <tr><td><?= $record['created_at'] ?></td><td><?= htmlspecialchars($record['server_name']) ?></td><td><?= htmlspecialchars($record['monitor_name']) ?></td><td><span class="status-badge <?= $record['is_online'] ? 'status-online' : 'status-offline' ?>"></span><?= $record['is_online'] ? '在线' : '离线' ?></td><td class="<?= getLatencyClass($record['response_time']) ?>"><?= $record['response_time'] ? $record['response_time'].'ms' : '-' ?></td><td><?= $record['library_count'] ?? '-' ?></td><td><?= $record['error_message'] ? '<span class="text-danger">'.htmlspecialchars($record['error_message']).'</span>' : '<span class="text-muted">-</span>' ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($records)): ?><tr><td colspan="7" class="text-center py-4 text-muted">暂无数据</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div></div></div>
                <?php if ($totalPages > 1): ?>
                <nav class="mt-4"><ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">上一页</a></li>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?><li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li><?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">下一页</a></li>
                </ul></nav>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>