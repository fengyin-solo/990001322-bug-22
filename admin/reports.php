<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '举报管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

header('Cache-Control: no-store, no-cache, must-revalidate');

// 筛选参数（严格白名单校验，防止非法值进入查询）
$status = isset($_GET['status']) && $_GET['status'] !== '' ? (string) intval($_GET['status']) : '';
$reportType = $_GET['report_type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

// 加载错误时用于展示友好的失败/重试状态，而不是白屏或空表
$loadError = '';
$reports = [];
$total = 0;
$totalPages = 0;
$stats = ['total' => 0, 'pending' => 0, 'deleted' => 0, 'ignored' => 0, 'rejected' => 0];
$pendingMessageCount = 0;
$pendingCount = 0;

try {
    $db = getDB();

    $where = "WHERE 1=1";
    $params = [];

    if ($status !== '' && in_array($status, ['0', '1', '2', '3'], true)) {
        $where .= " AND r.status = ?";
        $params[] = (int) $status;
    }
    if ($reportType && in_array($reportType, ['spam', 'abuse', 'illegal', 'porn', 'other'], true)) {
        $where .= " AND r.report_type = ?";
        $params[] = $reportType;
    }
    if ($keyword !== '') {
        $where .= " AND (m.title LIKE ? OR m.content LIKE ? OR r.description LIKE ?)";
        $kw = "%$keyword%";
        array_push($params, $kw, $kw, $kw);
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM reports r LEFT JOIN messages m ON r.message_id = m.id $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = (int) ceil($total / $pageSize);
    // 超出页码范围时回到最后一页，避免“有数据却显示空表”
    if ($page > $totalPages && $total > 0) {
        $page = $totalPages;
        $offset = ($page - 1) * $pageSize;
    }

    $sql = "SELECT r.*, m.title AS message_title, m.nickname AS message_nickname, m.type AS message_type,
                   a.username AS admin_name
            FROM reports r
            LEFT JOIN messages m ON r.message_id = m.id
            LEFT JOIN admins a ON r.processed_by = a.id
            $where
            ORDER BY r.created_at DESC
            LIMIT $pageSize OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    // 统计数据与列表使用同一数据源，保证数字与列表对得上
    $stats = getReportStats();
    $pendingCount = $stats['pending'];
    // 侧边栏“待审核留言”数量必须来自留言表，不能复用举报待处理数
    $pendingMessageCount = getPendingMessageCount();
} catch (Throwable $e) {
    error_log('[admin/reports] ' . $e->getMessage());
    $loadError = '举报数据加载失败，可能是数据库连接异常，请稍后重试。';
    $pendingCount = 0;
}

// 分页链接保持当前筛选条件
$buildPageUrl = function ($targetPage) use ($status, $reportType, $keyword) {
    return 'reports.php?' . http_build_query([
        'page' => $targetPage,
        'status' => $status,
        'report_type' => $reportType,
        'keyword' => $keyword,
    ]);
};

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核留言 <?= $pendingMessageCount > 0 ? "($pendingMessageCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link active">🚩 举报管理</a>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>举报管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 20px;">
            <a href="reports.php" class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">总举报数</div>
            </a>
            <a href="reports.php?status=0" class="stat-card stat-help">
                <div class="stat-number"><?= $stats['pending'] ?></div>
                <div class="stat-label">待处理</div>
            </a>
            <a href="reports.php?status=1" class="stat-card stat-suggest">
                <div class="stat-number"><?= $stats['deleted'] ?></div>
                <div class="stat-label">已删除</div>
            </a>
            <a href="reports.php?status=2" class="stat-card stat-lost">
                <div class="stat-number"><?= $stats['ignored'] ?></div>
                <div class="stat-label">已忽略</div>
            </a>
            <a href="reports.php?status=3" class="stat-card">
                <div class="stat-number"><?= $stats['rejected'] ?></div>
                <div class="stat-label">已驳回</div>
            </a>
        </div>

        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待处理</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已处理-已删除</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已处理-已忽略</option>
                    <option value="3" <?= $status === '3' ? 'selected' : '' ?>>已驳回</option>
                </select>
                <select name="report_type">
                    <option value="">全部类型</option>
                    <option value="spam" <?= $reportType === 'spam' ? 'selected' : '' ?>>垃圾信息</option>
                    <option value="abuse" <?= $reportType === 'abuse' ? 'selected' : '' ?>>辱骂攻击</option>
                    <option value="illegal" <?= $reportType === 'illegal' ? 'selected' : '' ?>>违法违规</option>
                    <option value="porn" <?= $reportType === 'porn' ? 'selected' : '' ?>>色情低俗</option>
                    <option value="other" <?= $reportType === 'other' ? 'selected' : '' ?>>其他</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="reports.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <div class="admin-table-wrapper">
            <?php if ($loadError): ?>
            <div class="empty-state" style="padding: 40px 0;">
                <div class="empty-icon">⚠️</div>
                <p><?= cleanInput($loadError) ?></p>
                <button type="button" class="btn btn-primary" onclick="location.reload()">🔄 重新加载</button>
            </div>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>举报类型</th>
                        <th>被举报留言</th>
                        <th>留言作者</th>
                        <th>状态</th>
                        <th>举报时间</th>
                        <th>处理人</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="8">
                            <?php if ($status !== '' || $reportType !== '' || $keyword !== ''): ?>
                            <div class="empty-state" style="padding: 24px 0;">
                                <div class="empty-icon">🔍</div>
                                <p>没有符合筛选条件的举报</p>
                                <a href="reports.php" class="btn btn-secondary btn-sm">清除筛选条件</a>
                            </div>
                            <?php else: ?>
                            <div class="empty-state" style="padding: 24px 0;">
                                <div class="empty-icon">🎉</div>
                                <p>暂无举报，当前没有需要处理的举报记录</p>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($reports as $r): ?>
                    <tr data-report-id="<?= $r['id'] ?>" data-report-status="<?= (int) $r['status'] ?>">
                        <td><?= (int) $r['id'] ?></td>
                        <td><span class="badge badge-<?= cleanInput($r['report_type']) ?>"><?= getReportTypeLabel($r['report_type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($r['message_title'] ?? '留言已删除') ?>">
                            <?php if (!empty($r['message_title'])): ?>
                                <a href="../detail.php?id=<?= (int) $r['message_id'] ?>" target="_blank"><?= cleanInput(mb_substr($r['message_title'], 0, 15)) ?></a>
                            <?php else: ?>
                                <span class="text-muted">留言已删除</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($r['message_nickname']) ? cleanInput($r['message_nickname']) : '-' ?></td>
                        <td><span class="status-badge report-status-<?= getReportStatusClass((int) $r['status']) ?>"><?= getReportStatusLabel((int) $r['status']) ?></span></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
                        <td class="td-handler"><?= !empty($r['admin_name']) ? cleanInput($r['admin_name']) : '-' ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewReport(<?= (int) $r['id'] ?>)">查看</button>
                            <?php if ((int) $r['status'] === 0): ?>
                                <button class="btn btn-xs btn-danger" onclick="processReport(<?= (int) $r['id'] ?>, 1, this)">删除留言</button>
                                <button class="btn btn-xs btn-success" onclick="processReport(<?= (int) $r['id'] ?>, 2, this)">忽略</button>
                                <button class="btn btn-xs btn-warning" onclick="processReport(<?= (int) $r['id'] ?>, 3, this)">驳回</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php if (!$loadError && $total > 0): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?= $buildPageUrl($page - 1) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="<?= $buildPageUrl($i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= $buildPageUrl($page + 1) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="reportViewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>举报详情</h3>
            <button class="modal-close" onclick="closeReportViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="reportViewBody">加载中...</div>
    </div>
</div>

<div class="modal" id="processNoteModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>处理备注 <span id="processNoteTitle"></span></h3>
            <button class="modal-close" onclick="closeProcessNoteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="processNote">处理备注（可选）</label>
                <textarea id="processNote" rows="3" maxlength="500" placeholder="请输入处理备注..."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="processCancelBtn" onclick="closeProcessNoteModal()">取消</button>
                <button type="button" class="btn btn-primary" id="processConfirmBtn" onclick="confirmProcess()">确认处理</button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingProcessId = null;
let pendingProcessStatus = null;
let pendingProcessBtn = null;

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function (ch) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch];
    });
}

function viewReport(id) {
    const modal = document.getElementById('reportViewModal');
    const body = document.getElementById('reportViewBody');
    modal.style.display = 'flex';
    body.innerHTML = '加载中...';

    fetch('api.php?action=report_detail&id=' + id, {cache: 'no-store'})
    .then(function (r) {
        return r.json().then(function (data) {
            if (!r.ok && (!data || data.code === undefined)) {
                throw new Error('HTTP ' + r.status);
            }
            return data;
        });
    })
    .then(function (data) {
        if (data.code !== 0) {
            body.innerHTML =
                '<div class="empty-state" style="padding:24px 0;">' +
                '<div class="empty-icon">⚠️</div>' +
                '<p>' + escapeHtml(data.msg || '举报详情加载失败') + '</p>' +
                '<button type="button" class="btn btn-primary btn-sm" onclick="viewReport(' + id + ')">🔄 重试</button>' +
                '</div>';
            return;
        }
        renderReportDetail(data.data);
    })
    .catch(function () {
        body.innerHTML =
            '<div class="empty-state" style="padding:24px 0;">' +
            '<div class="empty-icon">📡</div>' +
            '<p>网络异常，举报详情加载失败，请检查网络后重试</p>' +
            '<button type="button" class="btn btn-primary btn-sm" onclick="viewReport(' + id + ')">🔄 重试</button>' +
            '</div>';
    });
}

function renderReportDetail(d) {
    const body = document.getElementById('reportViewBody');
    let html = '<div class="detail-view">';
    html += '<p><strong>举报ID：</strong>' + escapeHtml(d.id) + '</p>';
    html += '<p><strong>举报类型：</strong><span class="badge badge-' + escapeHtml(d.report_type) + '">' + escapeHtml(d.report_type_label) + '</span></p>';
    html += '<p><strong>举报时间：</strong>' + escapeHtml(d.created_at) + '</p>';
    html += '<p><strong>举报状态：</strong><span class="status-badge report-status-' + escapeHtml(d.status_class) + '">' + escapeHtml(d.status_label) + '</span></p>';
    if (d.description) {
        html += '<p><strong>举报说明：</strong></p><div class="detail-text">' + d.description + '</div>';
    }
    html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
    html += '<h4 style="margin-bottom: 12px;">被举报留言信息</h4>';
    if (d.message_exists) {
        html += '<p><strong>留言标题：</strong>' + escapeHtml(d.message_title) + '</p>';
        html += '<p><strong>留言作者：</strong>' + escapeHtml(d.message_nickname) + '</p>';
        html += '<p><strong>留言类型：</strong>' + escapeHtml(d.message_type_label) + '</p>';
        html += '<p><strong>留言内容：</strong></p><div class="detail-text">' + d.message_content + '</div>';
        if (d.message_image) {
            html += '<p><strong>留言图片：</strong><br><img src="../' + encodeURI(d.message_image) + '" style="max-width:100%;margin-top:8px;"></p>';
        }
        html += '<p><a href="../detail.php?id=' + encodeURIComponent(d.message_id) + '" target="_blank" class="btn btn-sm btn-info">查看原留言</a></p>';
    } else {
        html += '<p class="text-muted">该留言已被删除，但举报记录与处理信息保留</p>';
    }
    if (Number(d.status) > 0) {
        html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
        html += '<h4 style="margin-bottom: 12px;">处理信息</h4>';
        html += '<p><strong>处理人：</strong>' + escapeHtml(d.admin_name || '-') + '</p>';
        html += '<p><strong>处理时间：</strong>' + escapeHtml(d.processed_at || '-') + '</p>';
        if (d.process_note) {
            html += '<p><strong>处理备注：</strong></p><div class="detail-text">' + d.process_note + '</div>';
        }
    }
    html += '</div>';
    body.innerHTML = html;
}

function closeReportViewModal() {
    document.getElementById('reportViewModal').style.display = 'none';
}

function processReport(id, status, btn) {
    let actionText = '';
    if (status === 1) actionText = '删除留言并标记为已处理';
    else if (status === 2) actionText = '忽略此举报';
    else if (status === 3) actionText = '驳回此举报';

    if (!confirm('确定要' + actionText + '吗？')) return;

    pendingProcessId = id;
    pendingProcessStatus = status;
    pendingProcessBtn = btn;

    let titleText = '';
    if (status === 1) titleText = '（删除留言）';
    else if (status === 2) titleText = '（忽略举报）';
    else if (status === 3) titleText = '（驳回举报）';

    document.getElementById('processNoteTitle').textContent = titleText;
    document.getElementById('processNote').value = '';
    document.getElementById('processNoteModal').style.display = 'flex';
}

function setProcessLoading(loading) {
    const confirmBtn = document.getElementById('processConfirmBtn');
    const cancelBtn = document.getElementById('processCancelBtn');
    confirmBtn.disabled = loading;
    cancelBtn.disabled = loading;
    confirmBtn.textContent = loading ? '处理中...' : '确认处理';
}

function closeProcessNoteModal() {
    // 请求进行中时禁止关闭，避免重复打开同一举报再次提交
    if (document.getElementById('processConfirmBtn').disabled) return;
    document.getElementById('processNoteModal').style.display = 'none';
    pendingProcessId = null;
    pendingProcessStatus = null;
    pendingProcessBtn = null;
}

// 根据服务端返回的最新状态，即时更新当前行（无需等整页刷新）
function applyLatestRowState(id, data) {
    const row = document.querySelector('tr[data-report-id="' + id + '"]');
    if (!row) return;
    row.setAttribute('data-report-status', data.status);
    const badge = row.querySelector('.status-badge');
    if (badge) {
        badge.textContent = data.status_label;
        badge.className = 'status-badge report-status-' + data.status_class;
    }
    const handler = row.querySelector('.td-handler');
    if (handler) handler.textContent = data.admin_name || '-';
    const actions = row.querySelector('.td-actions');
    if (actions) actions.innerHTML = '<button class="btn btn-xs btn-info" onclick="viewReport(' + id + ')">查看</button>';
}

function confirmProcess() {
    const reportId = pendingProcessId;
    const reportStatus = pendingProcessStatus;
    if (!reportId || !reportStatus) return;

    const note = document.getElementById('processNote').value;
    const formData = new FormData();
    formData.append('action', 'process_report');
    formData.append('id', reportId);
    formData.append('status', reportStatus);
    formData.append('note', note);

    setProcessLoading(true);

    fetch('api.php', {
        method: 'POST',
        body: formData,
        cache: 'no-store'
    })
    .then(function (r) {
        return r.json().then(function (data) {
            if (!r.ok && (!data || data.code === undefined)) throw new Error('HTTP ' + r.status);
            return data;
        });
    })
    .then(function (data) {
        if (data.code === 0) {
            // 只有自己处理成功才提示成功，并整页刷新以同步待办数字与列表
            alert(data.msg || '操作成功');
            location.reload();
            return;
        }
        if (data.code === 2) {
            // 并发冲突：自己的操作未生效，展示对方的最新处理结果
            closeProcessNoteModalForce();
            const detail = data.data || {};
            applyLatestRowState(reportId, detail);
            alert(data.msg || '该举报已被其他管理员处理，您的操作未生效');
            // 打开详情让另一方直接看到最新状态
            viewReport(reportId);
            return;
        }
        // 404：举报已不存在；401：登录过期；其他业务错误：保留弹窗可修改后重试
        if (data.code === 404) {
            closeProcessNoteModalForce();
            alert(data.msg || '举报不存在');
            location.reload();
            return;
        }
        if (data.code === 401) {
            closeProcessNoteModalForce();
            alert(data.msg || '登录已过期，请重新登录');
            window.location.href = 'login.php';
            return;
        }
        alert((data.msg || '操作失败') + '，请重试');
    })
    .catch(function () {
        alert('网络异常，操作未提交，请检查网络后重试');
    })
    .finally(function () {
        setProcessLoading(false);
    });
}

function closeProcessNoteModalForce() {
    document.getElementById('processConfirmBtn').disabled = false;
    closeProcessNoteModal();
}

document.getElementById('reportViewModal').addEventListener('click', function(e) {
    if (e.target === this) closeReportViewModal();
});

document.getElementById('processNoteModal').addEventListener('click', function(e) {
    if (e.target === this) closeProcessNoteModal();
});

// 从浏览器后退/前进返回本页时强制刷新，避免待办数字残留旧值
window.addEventListener('pageshow', function (e) {
    if (e.persisted) location.reload();
});
</script>
