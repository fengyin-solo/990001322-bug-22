<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在或已被删除');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        if (!empty($msg['image'])) {
            $msg['image'] = cleanInput($msg['image']);
        }
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(1, '不支持的请求方式');
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        // 已删除的留言不能再审核，且必须确认确实更新到一条记录
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(1, '留言不存在、已被删除或状态未变化，请刷新后重试');
        }
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(1, '不支持的请求方式');
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');
        // 软删除：保留留言与举报的关联记录，避免举报被外键级联删除后统计/列表对不上
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在或已被删除');

        $db->beginTransaction();
        try {
            // 条件更新保证只有一个请求删除成功
            $del = $db->prepare("UPDATE messages SET is_deleted = 1, status = 2 WHERE id = ? AND is_deleted = 0");
            $del->execute([$id]);
            if ($del->rowCount() === 0) {
                $db->rollBack();
                jsonResponse(1, '该留言已被其他人删除，请刷新后查看最新状态');
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(1, '删除失败，请稍后重试');
        }

        // 提交成功后再删除物理图片，失败不影响数据一致性
        if (!empty($msg['image'])) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (is_file($imgFile)) {
                @unlink($imgFile);
            }
        }
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的举报ID');
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, m.is_deleted as message_is_deleted, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在或已被删除，请刷新列表');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        // 留言被软删除或关联记录被物理删除时，均视为“留言已删除”
        $report['message_exists'] = !empty($report['message_title']) && (int)$report['message_is_deleted'] === 0;
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        if (!empty($report['message_image'])) {
            $report['message_image'] = cleanInput($report['message_image']);
        } else {
            $report['message_image'] = '';
        }
        $report['description'] = $report['description'] !== null && $report['description'] !== '' ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] !== null && $report['process_note'] !== '' ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(1, '不支持的请求方式');
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        // 备注原文入库，展示时再转义
        $note = trim($_POST['note'] ?? '');

        if ($id <= 0) jsonResponse(1, '无效的举报ID');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理备注不能超过500字');
        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效的处理状态');

        $db->beginTransaction();
        try {
            // 原子“抢占”：仅当举报仍为待处理时才能更新成功。
            // 两名处理人员同时操作时，数据库层面保证只有一方 affected rows = 1。
            $stmt = $db->prepare(
                "UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ?
                 WHERE id = ? AND status = 0"
            );
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            if ($stmt->rowCount() === 0) {
                // 已被他人（或自己的另一个请求）处理：回滚并返回最新状态
                $db->rollBack();
                $latest = $db->prepare(
                    "SELECT r.status, r.processed_at, a.username AS admin_name
                     FROM reports r LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?"
                );
                $latest->execute([$id]);
                $current = $latest->fetch();
                if (!$current) {
                    jsonResponse(1, '举报不存在，请刷新列表');
                }
                jsonResponse(
                    1,
                    '该举报已被' . ($current['admin_name'] ? '管理员「' . $current['admin_name'] . '」' : '其他处理人员')
                    . '处理为「' . getReportStatusLabel($current['status']) . '」，请勿重复操作',
                    [
                        'conflict' => true,
                        'status' => (int)$current['status'],
                        'status_label' => getReportStatusLabel($current['status']),
                        'admin_name' => $current['admin_name'] ? cleanInput($current['admin_name']) : '',
                        'processed_at' => $current['processed_at'],
                    ]
                );
            }

            $imageToDelete = null;
            if ($status === 1) {
                // 软删除留言：举报记录（处理人、备注、时间）得以完整保留
                $repStmt = $db->prepare("SELECT message_id FROM reports WHERE id = ?");
                $repStmt->execute([$id]);
                $rep = $repStmt->fetch();
                if ($rep) {
                    $mStmt = $db->prepare("SELECT image FROM messages WHERE id = ? AND is_deleted = 0");
                    $mStmt->execute([$rep['message_id']]);
                    $m = $mStmt->fetch();
                    if ($m) {
                        $db->prepare("UPDATE messages SET is_deleted = 1, status = 2 WHERE id = ? AND is_deleted = 0")
                            ->execute([$rep['message_id']]);
                        $imageToDelete = $m['image'];
                    }
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(1, '操作失败，请稍后重试');
        }

        // 事务提交后再清理物理图片
        if (!empty($imageToDelete)) {
            $imgFile = __DIR__ . '/../' . $imageToDelete;
            if (is_file($imgFile)) {
                @unlink($imgFile);
            }
        }

        $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
        jsonResponse(0, $statusMsg[$status] . '成功');
        break;

    default:
        jsonResponse(1, '未知操作');
}
