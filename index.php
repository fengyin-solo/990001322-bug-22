<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '社区便民留言板 - 首页';
$currentPage = 'home';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$db = getDB();

// 获取排序参数
$sort = $_GET['sort'] ?? 'time';
$type = $_GET['type'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

// 构建查询
$where = "WHERE status = 1 AND is_deleted = 0";
$params = [];

if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
    $where .= " AND type = ?";
    $params[] = $type;
}

// 排序
$orderBy = ($sort === 'hot') ? "views DESC, created_at DESC" : "created_at DESC";

// 总数
$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

// 列表
$sql = "SELECT id, nickname, type, title, content, image, views, created_at FROM messages $where ORDER BY $orderBy LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 获取当前用户已收藏的留言ID
$favoritedIds = getFavoritedMessageIds();
$favoritedIds = array_flip($favoritedIds);

// 滚动数据（最新5条）
$scrollStmt = $db->query("SELECT id, type, title, created_at FROM messages WHERE status = 1 AND is_deleted = 0 ORDER BY created_at DESC LIMIT 8");
$scrollMessages = $scrollStmt->fetchAll();

// 统计
$statsStmt = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN type='help' THEN 1 ELSE 0 END) as help_count,
    SUM(CASE WHEN type='suggest' THEN 1 ELSE 0 END) as suggest_count,
    SUM(CASE WHEN type='lost' THEN 1 ELSE 0 END) as lost_count
    FROM messages WHERE status = 1 AND is_deleted = 0");
$stats = $statsStmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<!-- 滚动信息栏 -->
<div class="scroll-bar">
    <div class="container">
        <span class="scroll-label">📢 最新动态</span>
        <div class="scroll-wrapper">
            <div class="scroll-content" id="scrollContent">
                <?php foreach ($scrollMessages as $msg): ?>
                <a href="detail.php?id=<?= $msg['id'] ?>" class="scroll-item">
                    <span class="scroll-type"><?= getTypeIcon($msg['type']) ?></span>
                    <span class="scroll-title"><?= cleanInput($msg['title']) ?></span>
                    <span class="scroll-time"><?= timeAgo($msg['created_at']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- 统计卡片 -->
<section class="stats-section">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
                <div class="stat-label">全部留言</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $stats['help_count'] ?? 0 ?></div>
                <div class="stat-label">🆘 居民求助</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $stats['suggest_count'] ?? 0 ?></div>
                <div class="stat-label">💡 意见建议</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $stats['lost_count'] ?? 0 ?></div>
                <div class="stat-label">🔍 失物招领</div>
            </div>
        </div>
    </div>
</section>

<!-- 筛选和排序 -->
<section class="filter-section">
    <div class="container">
        <div class="filter-bar">
            <div class="filter-types">
                <a href="index.php?sort=<?= $sort ?>" class="filter-tag <?= !$type ? 'active' : '' ?>">全部</a>
                <a href="index.php?sort=<?= $sort ?>&type=help" class="filter-tag <?= $type === 'help' ? 'active' : '' ?>">🆘 求助</a>
                <a href="index.php?sort=<?= $sort ?>&type=suggest" class="filter-tag <?= $type === 'suggest' ? 'active' : '' ?>">💡 建议</a>
                <a href="index.php?sort=<?= $sort ?>&type=lost" class="filter-tag <?= $type === 'lost' ? 'active' : '' ?>">🔍 失物招领</a>
            </div>
            <div class="filter-sort">
                <a href="index.php?sort=time&type=<?= $type ?>" class="sort-btn <?= $sort === 'time' ? 'active' : '' ?>">🕐 按时间</a>
                <a href="index.php?sort=hot&type=<?= $type ?>" class="sort-btn <?= $sort === 'hot' ? 'active' : '' ?>">🔥 按热度</a>
            </div>
        </div>
    </div>
</section>

<!-- 留言列表 -->
<section class="message-list-section">
    <div class="container">
        <?php if (empty($messages)): ?>
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <p>暂无留言信息</p>
            <a href="submit.php" class="btn btn-primary">发布第一条留言</a>
        </div>
        <?php else: ?>
        <div class="message-list">
            <?php foreach ($messages as $msg): ?>
            <div class="message-card">
                <a href="detail.php?id=<?= $msg['id'] ?>" class="card-link">
                    <div class="card-header">
                        <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                        <span class="card-time"><?= timeAgo($msg['created_at']) ?></span>
                    </div>
                    <h3 class="card-title"><?= cleanInput($msg['title']) ?></h3>
                    <p class="card-content"><?= cleanInput(mb_substr($msg['content'], 0, 80)) ?><?= mb_strlen($msg['content']) > 80 ? '...' : '' ?></p>
                    <div class="card-footer">
                        <span class="card-author">👤 <?= cleanInput($msg['nickname']) ?></span>
                        <?php if ($msg['image']): ?>
                        <span class="card-image">📷 有图</span>
                        <?php endif; ?>
                        <span class="card-views">👁 <?= $msg['views'] ?></span>
                    </div>
                </a>
                <button class="favorite-btn <?= isset($favoritedIds[$msg['id']]) ? 'favorited' : '' ?>" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon"><?= isset($favoritedIds[$msg['id']]) ? '⭐' : '☆' ?></span>
                    <span class="favorite-text"><?= isset($favoritedIds[$msg['id']]) ? '已收藏' : '收藏' ?></span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?page=<?= $page - 1 ?>&sort=<?= $sort ?>&type=<?= $type ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?page=<?= $i ?>&sort=<?= $sort ?>&type=<?= $type ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?page=<?= $page + 1 ?>&sort=<?= $sort ?>&type=<?= $type ?>" class="page-btn">下一页</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
