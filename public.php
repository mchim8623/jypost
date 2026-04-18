<?php
require_once 'config.php';

$db = getDB();
$site_name = getConfig('site_name') ?: '集邮记';

$publicServers = $db->query("
    SELECT 
        es.id, es.name, es.url, es.icon_url,
        (SELECT is_online FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as is_online,
        (SELECT response_time FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as response_time,
        (SELECT library_count FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as library_count,
        (SELECT item_counts FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as item_counts,
        (SELECT created_at FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) as last_check,
        (SELECT AVG(response_time) FROM monitor_data WHERE server_id = es.id AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as avg_response_24h,
        (SELECT COUNT(*) FROM monitor_data WHERE server_id = es.id AND is_online = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as online_count_24h,
        (SELECT COUNT(*) FROM monitor_data WHERE server_id = es.id AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as total_checks_24h,
        (SELECT item_counts FROM monitor_data WHERE server_id = es.id AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 1) as latest_counts,
        (SELECT item_counts FROM monitor_data WHERE server_id = es.id AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND created_at <= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY created_at DESC LIMIT 1) as prev_counts
    FROM emby_servers es
    WHERE es.status = 1 AND es.is_public = 1
    ORDER BY 
        CASE 
            WHEN (SELECT item_counts FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1) IS NOT NULL 
            THEN CAST(JSON_EXTRACT((SELECT item_counts FROM monitor_data WHERE server_id = es.id ORDER BY created_at DESC LIMIT 1), '$.EpisodeCount') AS UNSIGNED)
            ELSE 0 
        END DESC,
        es.name ASC
")->fetchAll();

$publicStats = ['total_servers' => count($publicServers), 'online_servers' => count(array_filter($publicServers, fn($s) => $s['is_online'] == 1)), 'total_movies' => 0, 'total_series' => 0, 'total_episodes' => 0, 'total_songs' => 0];

foreach ($publicServers as $server) {
    if ($server['item_counts']) {
        $counts = json_decode($server['item_counts'], true) ?: [];
        $publicStats['total_movies'] += $counts['MovieCount'] ?? 0;
        $publicStats['total_series'] += $counts['SeriesCount'] ?? 0;
        $publicStats['total_episodes'] += $counts['EpisodeCount'] ?? 0;
        $publicStats['total_songs'] += $counts['SongCount'] ?? 0;
    }
}

function calculateTrend($server) {
    $trend = ['movies' => ['change' => 0, 'percent' => 0, 'direction' => 'flat'], 'series' => ['change' => 0, 'percent' => 0, 'direction' => 'flat'], 'episodes' => ['change' => 0, 'percent' => 0, 'direction' => 'flat'], 'songs' => ['change' => 0, 'percent' => 0, 'direction' => 'flat'], 'total' => ['change' => 0, 'percent' => 0, 'direction' => 'flat']];
    if (!$server['latest_counts'] || !$server['prev_counts']) return $trend;
    $latest = json_decode($server['latest_counts'], true) ?: [];
    $prev = json_decode($server['prev_counts'], true) ?: [];
    $types = ['MovieCount' => 'movies', 'SeriesCount' => 'series', 'EpisodeCount' => 'episodes', 'SongCount' => 'songs'];
    $totalLatest = $totalPrev = 0;
    foreach ($types as $key => $name) {
        $latestVal = $latest[$key] ?? 0; $prevVal = $prev[$key] ?? 0;
        $change = $latestVal - $prevVal; $percent = $prevVal > 0 ? round(($change / $prevVal) * 100, 1) : ($latestVal > 0 ? 100 : 0);
        $trend[$name] = ['change' => $change, 'percent' => $percent, 'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat')];
$totalLatest += $latestVal; $totalPrev += $prevVal;
    }
    $totalChange = $totalLatest - $totalPrev; $totalPercent = $totalPrev > 0 ? round(($totalChange / $totalPrev) * 100, 1) : ($totalLatest > 0 ? 100 : 0);
    $trend['total'] = ['change' => $totalChange, 'percent' => $totalPercent, 'direction' => $totalChange > 0 ? 'up' : ($totalChange < 0 ? 'down' : 'flat')];
    return $trend;
}

$current_time = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <style>
        :root { --latency-excellent: #10b981; --latency-good: #34d399; --latency-normal: #fbbf24; --latency-slow: #f97316; --latency-very-slow: #ef4444; --latency-unknown: #9ca3af; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px 0; }
        .container { max-width: 1400px; }
        .header { background: white; border-radius: 20px; padding: 30px; margin-bottom: 30px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; color: #333; } .header h1 i { color: #667eea; }
        .stats-bar { display: flex; gap: 30px; margin-top: 20px; flex-wrap: wrap; }
        .stat-item { display: flex; align-items: center; gap: 10px; } .stat-value { font-size: 28px; font-weight: bold; } .stat-label { color: #666; }
        .server-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px; }
        .server-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transition: all 0.3s ease; border: 1px solid rgba(0,0,0,0.05); }
        .server-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,0.15); }
        .server-header { padding: 20px; display: flex; align-items: center; gap: 15px; border-bottom: 1px solid #f0f0f0; }
        .server-icon { width: 55px; height: 55px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 28px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; flex-shrink: 0; overflow: hidden; }
        .server-icon img { width: 100%; height: 100%; object-fit: cover; }
        .server-info { flex: 1; min-width: 0; } .server-name { font-size: 18px; font-weight: 600; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .server-status { display: flex; align-items: center; gap: 8px; margin-top: 5px; }
        .status-indicator { width: 10px; height: 10px; border-radius: 50%; animation: pulse 2s infinite; }
        .status-online { background-color: #10b981; box-shadow: 0 0 10px rgba(16, 185, 129, 0.5); } .status-offline { background-color: #ef4444; } .status-unknown { background-color: #9ca3af; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .server-body { padding: 20px; } .metric-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f5f5f5; } .metric-row:last-child { border-bottom: none; }
        .metric-label { color: #666; font-size: 14px; display: flex; align-items: center; gap: 5px; } .metric-value { font-weight: 600; font-size: 16px; }
        .latency-excellent { color: var(--latency-excellent); } .latency-good { color: var(--latency-good); } .latency-normal { color: var(--latency-normal); } .latency-slow { color: var(--latency-slow); } .latency-very-slow { color: var(--latency-very-slow); } .latency-unknown { color: var(--latency-unknown); }
.latency-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .latency-badge.latency-excellent { background: rgba(16, 185, 129, 0.15); } .latency-badge.latency-good { background: rgba(52, 211, 153, 0.15); } .latency-badge.latency-normal { background: rgba(251, 191, 36, 0.15); } .latency-badge.latency-slow { background: rgba(249, 115, 22, 0.15); } .latency-badge.latency-very-slow { background: rgba(239, 68, 68, 0.15); } .latency-badge.latency-unknown { background: rgba(156, 163, 175, 0.15); }
        .uptime-bar { height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; margin-top: 5px; } .uptime-fill { height: 100%; background: linear-gradient(90deg, #10b981, #34d399); border-radius: 3px; transition: width 0.3s ease; }
        .media-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 15px; padding: 12px; background: #f8f9fa; border-radius: 10px; }
        .media-stat-item { display: flex; align-items: center; gap: 8px; } .media-stat-icon { width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .media-stat-icon.movie { background: #3b82f6; color: white; } .media-stat-icon.series { background: #8b5cf6; color: white; } .media-stat-icon.episode { background: #ec4899; color: white; } .media-stat-icon.music { background: #10b981; color: white; } .media-stat-icon.other { background: #6b7280; color: white; }
        .media-stat-content { flex: 1; display: flex; justify-content: space-between; align-items: center; } .media-stat-label { font-size: 12px; color: #666; } .media-stat-value { font-weight: 600; font-size: 14px; color: #333; display: flex; align-items: center; gap: 5px; }
        .trend-up { color: #10b981; font-size: 11px; } .trend-down { color: #ef4444; font-size: 11px; } .trend-flat { color: #9ca3af; font-size: 11px; }
        .trend-section { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb; } .trend-title { font-size: 12px; color: #999; margin-bottom: 8px; display: flex; align-items: center; gap: 5px; } .trend-grid { display: flex; justify-content: space-around; } .trend-item { text-align: center; } .trend-item .label { font-size: 11px; color: #999; } .trend-item .value { font-size: 14px; font-weight: 600; }
        .server-footer { padding: 15px 20px; background: #fafafa; font-size: 12px; color: #999; display: flex; justify-content: space-between; align-items: center; }
        .footer { text-align: center; margin-top: 40px; color: rgba(255,255,255,0.8); } .footer a { color: white; text-decoration: none; }
        .refresh-btn { position: fixed; bottom: 30px; right: 30px; width: 50px; height: 50px; border-radius: 25px; background: white; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.2); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #667eea; transition: all 0.3s; } .refresh-btn:hover { transform: rotate(180deg); box-shadow: 0 6px 25px rgba(0,0,0,0.25); }
        .empty-state { background: white; border-radius: 20px; padding: 60px; text-align: center; color: #999; } .empty-state i { font-size: 60px; color: #ddd; margin-bottom: 20px; }
        .admin-link { color: #666; text-decoration: none; font-size: 14px; } .admin-link:hover { color: #667eea; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="d-flex justify-content-between align-items-start">
                <div><h1><i class="bi bi-stamp"></i> <?= htmlspecialchars($site_name) ?></h1><p class="text-muted mt-2 mb-0">Emby服务器实时监控状态</p></div>
                <div class="text-end"><a href="login.php" class="admin-link"><i class="bi bi-lock"></i> 管理</a></div>
</div>
            <div class="stats-bar">
                <div class="stat-item"><div class="stat-value text-primary"><?= $publicStats['total_servers'] ?></div><div class="stat-label">服务器</div></div>
                <div class="stat-item"><div class="stat-value text-success"><?= $publicStats['online_servers'] ?></div><div class="stat-label">在线</div></div>
                <div class="stat-item"><div class="stat-value text-danger"><?= $publicStats['total_servers'] - $publicStats['online_servers'] ?></div><div class="stat-label">离线</div></div>
                <div class="stat-item"><div class="stat-value text-info"><?= formatNumber($publicStats['total_movies']) ?></div><div class="stat-label">电影</div></div>
                <div class="stat-item"><div class="stat-value text-warning"><?= formatNumber($publicStats['total_series']) ?></div><div class="stat-label">剧集</div></div>
                <div class="stat-item"><div class="stat-value text-secondary"><?= formatNumber($publicStats['total_episodes']) ?></div><div class="stat-label">单集</div></div>
                <div class="stat-item ms-auto"><i class="bi bi-clock"></i><span class="stat-label">更新于 <?= $current_time ?></span></div>
            </div>
        </div>
        <?php if (empty($publicServers)): ?>
        <div class="empty-state"><i class="bi bi-server"></i><h4>暂无公开服务器</h4><p class="text-muted">管理员还没有设置公开显示的服务器</p></div>
        <?php else: ?>
        <div class="server-grid">
            <?php foreach ($publicServers as $server): 
                $uptime = $server['total_checks_24h'] > 0 ? round(($server['online_count_24h'] / $server['total_checks_24h']) * 100, 2) : 0;
                $latencyClass = getLatencyClass($server['response_time']); $latencyText = getLatencyText($server['response_time']);
                $counts = $server['item_counts'] ? json_decode($server['item_counts'], true) : [];
                $trend = calculateTrend($server);
                $totalItems = ($counts['MovieCount'] ?? 0) + ($counts['SeriesCount'] ?? 0) + ($counts['EpisodeCount'] ?? 0) + ($counts['SongCount'] ?? 0) + ($counts['AlbumCount'] ?? 0);
            ?>
            <div class="server-card">
                <div class="server-header">
                    <div class="server-icon"><?php if (!empty($server['icon_url'])): ?><img src="<?= htmlspecialchars($server['icon_url']) ?>" alt="<?= htmlspecialchars($server['name']) ?>" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'bi bi-hdd-stack\'></i>'"><?php else: ?><i class="bi bi-hdd-stack"></i><?php endif; ?></div>
                    <div class="server-info"><div class="server-name" title="<?= htmlspecialchars($server['name']) ?>"><?= htmlspecialchars($server['name']) ?></div><div class="server-status"><span class="status-indicator <?= $server['is_online'] ? 'status-online' : ($server['is_online'] === null ? 'status-unknown' : 'status-offline') ?>"></span><span><?= $server['is_online'] === null ? '未知' : ($server['is_online'] ? '在线' : '离线') ?></span></div></div>
                </div>
                <div class="server-body">
                    <div class="metric-row"><span class="metric-label"><i class="bi bi-clock-history"></i> 响应延迟</span><span class="metric-value <?= $latencyClass ?>"><span class="latency-badge <?= $latencyClass ?>"><?= $latencyText ?> (<?= $server['response_time'] ?? '-' ?>ms)</span></span></div>
                    <div class="metric-row"><span class="metric-label"><i class="bi bi-bar-chart"></i> 24h在线率</span><span class="metric-value"><?= $uptime ?>%</span></div>
                    <div class="uptime-bar"><div class="uptime-fill" style="width: <?= $uptime ?>%;"></div></div>
                    <?php if (!empty($counts)): ?>
                    <div class="media-stats">
                        <?php if (($counts['MovieCount'] ?? 0) > 0): ?><div class="media-stat-item"><div class="media-stat-icon movie"><i class="bi bi-film"></i></div><div class="media-stat-content"><span class="media-stat-label">电影</span><span class="media-stat-value"><?= formatNumber($counts['MovieCount'])
?><?php if ($trend['movies']['direction'] !== 'flat'): ?><span class="trend-<?= $trend['movies']['direction'] ?>"><i class="bi bi-arrow-<?= $trend['movies']['direction'] ?>"></i><?= abs($trend['movies']['change']) ?></span><?php endif; ?></span></div></div><?php endif; ?>
                        <?php if (($counts['SeriesCount'] ?? 0) > 0): ?><div class="media-stat-item"><div class="media-stat-icon series"><i class="bi bi-tv"></i></div><div class="media-stat-content"><span class="media-stat-label">剧集</span><span class="media-stat-value"><?= formatNumber($counts['SeriesCount']) ?><?php if ($trend['series']['direction'] !== 'flat'): ?><span class="trend-<?= $trend['series']['direction'] ?>"><i class="bi bi-arrow-<?= $trend['series']['direction'] ?>"></i><?= abs($trend['series']['change']) ?></span><?php endif; ?></span></div></div><?php endif; ?>
                        <?php if (($counts['EpisodeCount'] ?? 0) > 0): ?><div class="media-stat-item"><div class="media-stat-icon episode"><i class="bi bi-collection-play"></i></div><div class="media-stat-content"><span class="media-stat-label">单集</span><span class="media-stat-value"><?= formatNumber($counts['EpisodeCount']) ?><?php if ($trend['episodes']['direction'] !== 'flat'): ?><span class="trend-<?= $trend['episodes']['direction'] ?>"><i class="bi bi-arrow-<?= $trend['episodes']['direction'] ?>"></i><?= abs($trend['episodes']['change']) ?></span><?php endif; ?></span></div></div><?php endif; ?>
                        <?php if (($counts['SongCount'] ?? 0) > 0): ?><div class="media-stat-item"><div class="media-stat-icon music"><i class="bi bi-music-note"></i></div><div class="media-stat-content"><span class="media-stat-label">歌曲</span><span class="media-stat-value"><?= formatNumber($counts['SongCount']) ?><?php if ($trend['songs']['direction'] !== 'flat'): ?><span class="trend-<?= $trend['songs']['direction'] ?>"><i class="bi bi-arrow-<?= $trend['songs']['direction'] ?>"></i><?= abs($trend['songs']['change']) ?></span><?php endif; ?></span></div></div><?php endif; ?>
                    </div>
                    <?php if ($trend['total']['change'] != 0): ?>
                    <div class="trend-section"><div class="trend-title"><i class="bi bi-graph-up-arrow"></i> 近期更新趋势</div><div class="trend-grid"><?php if ($trend['movies']['change'] != 0): ?><div class="trend-item"><div class="label">电影</div><div class="value trend-<?= $trend['movies']['direction'] ?>"><?= $trend['movies']['change'] > 0 ? '+' : '' ?><?= $trend['movies']['change'] ?></div></div><?php endif; ?><?php if ($trend['episodes']['change'] != 0): ?><div class="trend-item"><div class="label">单集</div><div class="value trend-<?= $trend['episodes']['direction'] ?>"><?= $trend['episodes']['change'] > 0 ? '+' : '' ?><?= $trend['episodes']['change'] ?></div></div><?php endif; ?><div class="trend-item"><div class="label">总计</div><div class="value trend-<?= $trend['total']['direction'] ?>"><?= $trend['total']['change'] > 0 ? '+' : '' ?><?= $trend['total']['change'] ?></div></div></div></div>
                    <?php endif; ?><?php endif; ?>
                </div>
                <div class="server-footer"><span><i class="bi bi-check-circle"></i> 最后检查: <?= $server['last_check'] ? date('H:i:s', strtotime($server['last_check'])) : '从未' ?></span><span><i class="bi bi-activity"></i> 平均: <?= round($server['avg_response_24h'] ?? 0) ?>ms</span></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="footer"><p>Powered by 集邮记 · 实时监控中</p></div>
    </div>
    <button class="refresh-btn" onclick="location.reload()" title="刷新状态"><i class="bi bi-arrow-clockwise"></i></button>
    <script>let countdown = 30; setInterval(() => { countdown--; if (countdown <= 0) location.reload(); }, 1000);</script>
</body>
</html>