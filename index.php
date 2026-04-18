<?php
// index.php
require_once 'config.php';
requireLogin();

$db = getDB();
$site_name = getConfig('site_name') ?: '集邮记';

$stats = [
    'total_servers' => $db->query("SELECT COUNT(*) FROM emby_servers")->fetchColumn(),
    'online_servers' => $db->query("SELECT COUNT(DISTINCT server_id) FROM monitor_data WHERE is_online=1 AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn(),
    'total_monitors' => $db->query("SELECT COUNT(*) FROM monitors")->fetchColumn(),
    'online_monitors' => $db->query("SELECT COUNT(*) FROM monitors WHERE status=1 AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn(),
    'today_alerts' => $db->query("SELECT COUNT(*) FROM alert_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
    'avg_response' => round($db->query("SELECT AVG(response_time) FROM monitor_data WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn() ?: 0),
];

$serverStatus = $db->query("
    SELECT 
        es.id, es.name, es.url, es.icon_url, es.is_public,
        (SELECT is_online FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as is_online,
        (SELECT response_time FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as response_time,
        (SELECT item_counts FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as item_counts,
        (SELECT created_at FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as last_check
    FROM emby_servers es
    WHERE es.status = 1
    ORDER BY es.name
")->fetchAll();

$recentData = $db->query("
    SELECT 
        es.name as server_name, 
        m.name as monitor_name, 
        md.is_online, 
        md.response_time, 
        md.created_at
    FROM monitor_data md
    JOIN emby_servers es ON md.server_id = es.id
    JOIN monitors m ON md.monitor_id = m.id
    WHERE md.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ORDER BY md.created_at DESC
    LIMIT 50
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?> - 管理面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,.8);
            padding: 12px 20px;
            border-radius: 8px;
            margin: 4px 0;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,.2);
        }
        .stat-card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,.1);
            transition: transform .2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .status-badge {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .status-online { background-color: #10b981; }
        .status-offline { background-color: #ef4444; }
        .server-card {
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            transition: all .2s;
        }
        .server-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }
        .server-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            overflow: hidden;
        }
        .server-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .public-badge {
            background: #10b981;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 d-md-block sidebar">
                <div class="position-sticky pt-4">
                    <h4 class="text-white text-center mb-4">
                        <i class="bi bi-stamp"></i> <?= htmlspecialchars($site_name) ?>
                    </h4>
                    <div class="nav flex-column">
                        <a class="nav-link active" href="index.php">
                            <i class="bi bi-speedometer2"></i> 仪表盘
                        </a>
                        <a class="nav-link" href="servers.php">
                            <i class="bi bi-server"></i> 服务器管理
                        </a>
                        <a class="nav-link" href="monitors.php">
                            <i class="bi bi-hdd-network"></i> 监控机管理
                        </a>
                        <a class="nav-link" href="history.php">
                            <i class="bi bi-clock-history"></i> 历史数据
                        </a>
                        <a class="nav-link" href="settings.php">
                            <i class="bi bi-gear"></i> 系统设置
                        </a>
                        <a class="nav-link" href="public.php" target="_blank">
                            <i class="bi bi-eye"></i> 公开监控站
                        </a>
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> 退出登录
                        </a>
                    </div>
                </div>
            </nav>

            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>管理面板</h2>
                    <span class="text-muted">
                        <i class="bi bi-clock"></i> 
                        <?= date('Y-m-d H:i:s') ?>
                    </span>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Emby服务器</h5>
                                <h3><?= $stats['total_servers'] ?></h3>
                                <small>在线: <?= $stats['online_servers'] ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">监控节点</h5>
                                <h3><?= $stats['total_monitors'] ?></h3>
                                <small>在线: <?= $stats['online_monitors'] ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">今日告警</h5>
                                <h3><?= $stats['today_alerts'] ?></h3>
                                <small>需要关注</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">平均响应</h5>
                                <h3><?= $stats['avg_response'] ?>ms</h3>
                                <small>最近1小时</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <?php foreach ($serverStatus as $server): 
                        $counts = $server['item_counts'] ? json_decode($server['item_counts'], true) : [];
                    ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="server-card p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="d-flex gap-3">
                                    <div class="server-icon">
                                        <?php if (!empty($server['icon_url'])): ?>
                                        <img src="<?= htmlspecialchars($server['icon_url']) ?>" alt="<?= htmlspecialchars($server['name']) ?>" 
                                             onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'bi bi-hdd-stack\'></i>'">
                                        <?php else: ?>
                                        <i class="bi bi-hdd-stack"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h5><?= htmlspecialchars($server['name']) ?></h5>
                                        <?php if ($server['is_public']): ?>
                                        <span class="public-badge">
                                            <i class="bi bi-eye"></i> 公开
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if (isset($server['is_online'])): ?>
                                        <span class="badge bg-<?= $server['is_online'] ? 'success' : 'danger' ?>">
                                            <?= $server['is_online'] ? '在线' : '离线' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">未知</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <hr>
                            <div class="row text-center">
                                <div class="col-3">
                                    <small class="text-muted">响应</small>
                                    <h6><?= $server['response_time'] ?? '-' ?>ms</h6>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">电影</small>
                                    <h6><?= formatNumber($counts['MovieCount'] ?? 0) ?></h6>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">剧集</small>
                                    <h6><?= formatNumber($counts['SeriesCount'] ?? 0) ?></h6>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">单集</small>
                                    <h6><?= formatNumber($counts['EpisodeCount'] ?? 0) ?></h6>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($serverStatus)): ?>
                    <div class="col-12">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 暂无服务器，请先在"服务器管理"中添加
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">最近监控记录</h5>
                        <a href="history.php" class="btn btn-sm btn-outline-primary">
                            查看更多 <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>时间</th>
                                        <th>服务器</th>
                                        <th>监控节点</th>
                                        <th>状态</th>
                                        <th>响应时间</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentData as $data): ?>
                                    <tr>
                                        <td><?= date('H:i:s', strtotime($data['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($data['server_name']) ?></td>
                                        <td><?= htmlspecialchars($data['monitor_name']) ?></td>
                                        <td>
                                            <span class="status-badge <?= $data['is_online'] ? 'status-online' : 'status-offline' ?>"></span>
                                            <?= $data['is_online'] ? '在线' : '离线' ?>
                                        </td>
                                        <td><?= $data['response_time'] ? $data['response_time'].'ms' : '-' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recentData)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            暂无监控数据
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>