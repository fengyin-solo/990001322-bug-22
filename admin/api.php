<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = getDB();
    switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(404, '留言不存在或已被删除');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) jsonResponse(1, '留言不存在或状态未变化');
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        // 删除关联图片
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(404, '留言不存在或已被删除');
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
        // 举报记录通过外键 SET NULL 保留，图片在记录删除后清理
        if ($msg['image']) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (is_file($imgFile)) @unlink($imgFile);
        }
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare(
            "SELECT r.*, m.id AS message_id_ref, m.title AS message_title, m.nickname AS message_nickname,
                    m.type AS message_type, m.content AS message_content, m.image AS message_image,
                    a.username AS admin_name
             FROM reports r
             LEFT JOIN messages m ON r.message_id = m.id
             LEFT JOIN admins a ON r.processed_by = a.id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(404, '举报不存在或记录已被删除');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        // 以留言记录是否真实存在为准（LEFT JOIN 到 m.id），不能用标题非空判断
        $report['message_exists'] = !empty($report['message_id_ref']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';
        unset($report['message_id_ref']);

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = mb_substr(trim($_POST['note'] ?? ''), 0, 500);
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);

        if ($id <= 0 || $adminId <= 0) jsonResponse(1, '参数无效或登录已过期，请刷新后重试');
        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效的处理状态');

        $imageToDelete = null;
        $db->beginTransaction();
        try {
            // 原子条件更新：仅当举报仍为“待处理(status=0)”时才生效，
            // 两名处理人并发提交时只有一人 affected rows = 1
            $stmt = $db->prepare(
                "UPDATE reports
                    SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ?
                  WHERE id = ? AND status = 0
                  LIMIT 1"
            );
            $stmt->execute([$status, $adminId, $note, $id]);

            if ($stmt->rowCount() === 0) {
                // 未更新成功：举报不存在，或已被其他管理员抢先处理
                $db->rollBack();
                $latest = getReportById($id);
                if (!$latest) {
                    jsonResponse(404, '举报不存在或记录已被删除，请刷新列表');
                }
                $handler = $latest['admin_name'] ? cleanInput($latest['admin_name']) : '未知处理人';
                jsonResponse(
                    2,
                    '该举报已被其他管理员处理为「' . getReportStatusLabel((int) $latest['status'])
                    . '」（处理人：' . $handler . '），您的操作未生效，页面将刷新为最新状态',
                    [
                        'status' => (int) $latest['status'],
                        'status_label' => getReportStatusLabel((int) $latest['status']),
                        'status_class' => getReportStatusClass((int) $latest['status']),
                        'admin_name' => $handler,
                        'processed_at' => $latest['processed_at'],
                        'process_note' => $latest['process_note'] ? nl2br(cleanInput($latest['process_note'])) : '',
                    ]
                );
            }

            // 处理动作=删除留言：留言可能已被其他途径删除（message_id 为 NULL），此时仅保留举报处置结果
            if ($status === 1) {
                $msgStmt = $db->prepare("SELECT id, image FROM messages WHERE id = (SELECT message_id FROM reports WHERE id = ?)");
                $msgStmt->execute([$id]);
                $msg = $msgStmt->fetch();
                if ($msg) {
                    $imageToDelete = $msg['image'];
                    // 物理删除留言；reports.message_id 外键为 ON DELETE SET NULL，举报记录与处理留痕保留
                    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$msg['id']]);
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // 事务提交后再清理图片文件，避免回滚后文件丢失
        if ($imageToDelete) {
            $imgFile = __DIR__ . '/../' . $imageToDelete;
            if (is_file($imgFile)) @unlink($imgFile);
        }

        $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
        jsonResponse(0, $statusMsg[$status] . '，处理成功', ['status' => $status]);
        break;

    default:
        jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    error_log('[admin/api] ' . $action . ' - ' . $e->getMessage());
    jsonResponse(500, '服务器处理失败，请稍后重试');
}

