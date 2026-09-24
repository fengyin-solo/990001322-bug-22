<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 * @param bool $asJson 为 true 时（API 入口）未登录返回 JSON 401，而不是 302 跳转登录页
 */
function requireAdmin($asJson = false) {
    if (empty($_SESSION['admin_id'])) {
        if ($asJson) {
            jsonResponse(401, '登录已过期，请重新登录后再操作');
        }
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 判断 PDO 异常是否为唯一键冲突
 */
function isDuplicateEntry(PDOException $e) {
    return ($e->errorInfo[1] ?? null) == 1062;
}

/**
 * 提交举报
 * 失败时抛出 Exception，其 code 约定：
 *   1=参数无效, 3=已举报过(含并发唯一键冲突), 4=留言不存在或不可见, 5=服务异常
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes, true)) {
        throw new Exception('无效的举报类型', 1);
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核，无法举报', 4);
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了', 3);
    }

    try {
        $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$messageId, $visitorId, $reportType, $description]);
    } catch (PDOException $e) {
        // 并发提交时唯一键(访客+留言)冲突，等同于已举报
        if (isDuplicateEntry($e)) {
            throw new Exception('您已经举报过这条留言了', 3);
        }
        error_log('[submitReport] ' . $e->getMessage());
        throw new Exception('举报提交失败，请稍后重试', 5);
    }

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量（仅统计举报表 status=0）
 */
function getPendingReportCount() {
    $db = getDB();
    return (int) $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}

/**
 * 获取待审核留言数量（仅统计留言表 status=0）
 */
function getPendingMessageCount() {
    $db = getDB();
    return (int) $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
}

/**
 * 获取举报各状态数量
 * 返回: ['total' => int, 'pending' => int, 'deleted' => int, 'ignored' => int, 'rejected' => int]
 */
function getReportStats() {
    $db = getDB();
    $stats = ['total' => 0, 'pending' => 0, 'deleted' => 0, 'ignored' => 0, 'rejected' => 0];
    $rows = $db->query("SELECT status, COUNT(*) AS cnt FROM reports GROUP BY status")->fetchAll();
    foreach ($rows as $row) {
        $stats['total'] += (int) $row['cnt'];
        switch ((int) $row['status']) {
            case 0: $stats['pending'] = (int) $row['cnt']; break;
            case 1: $stats['deleted'] = (int) $row['cnt']; break;
            case 2: $stats['ignored'] = (int) $row['cnt']; break;
            case 3: $stats['rejected'] = (int) $row['cnt']; break;
        }
    }
    return $stats;
}

/**
 * 按ID获取单条举报（含最新处理状态与处理人信息）
 */
function getReportById($id) {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT r.*, a.username AS admin_name
         FROM reports r
         LEFT JOIN admins a ON r.processed_by = a.id
         WHERE r.id = ?"
    );
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}
