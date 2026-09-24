<?php
/**
 * 后台侧边栏公共片段
 * $activeNav: 'messages' | 'reports'
 * 留言待审数与举报待处理数分别统计，互不影响
 */
if (!isset($activeNav)) {
    $activeNav = 'messages';
}
if (!isset($pendingMessageCount)) {
    $pendingMessageCount = getPendingMessageCount();
}
if (!isset($pendingCount)) {
    $pendingCount = getPendingReportCount();
}
?>
<aside class="admin-sidebar">
    <div class="sidebar-header">
        <h3>📋 管理后台</h3>
    </div>
    <nav class="sidebar-nav">
        <a href="index.php" class="sidebar-link <?= $activeNav === 'messages' ? 'active' : '' ?>">📝 留言管理</a>
        <a href="index.php?status=0" class="sidebar-link">⏳ 待审核留言 <?= $pendingMessageCount > 0 ? "($pendingMessageCount)" : '' ?></a>
        <a href="reports.php" class="sidebar-link <?= ($activeNav === 'reports' && !isset($_GET['status'])) ? 'active' : '' ?>">🚩 举报管理</a>
        <a href="reports.php?status=0" class="sidebar-link <?= ($activeNav === 'reports' && isset($_GET['status'])) ? 'active' : '' ?>">🚩 待处理举报 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
        <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
        <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
    </nav>
</aside>
