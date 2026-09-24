<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$action = $_POST['action'] ?? 'submit';
$messageId = intval($_POST['message_id'] ?? 0);

if ($messageId <= 0) {
    jsonResponse(1, '无效的留言ID');
}

$db = getDB();

try {
    if ($action === 'check') {
        $reported = hasReported($messageId);
        jsonResponse(0, '查询成功', ['reported' => $reported]);
    } elseif ($action === 'submit') {
        $reportType = trim($_POST['report_type'] ?? '');
        // 原文入库，展示时由 cleanInput 转义，避免双重转义
        $description = trim($_POST['description'] ?? '');

        if (empty($reportType)) {
            jsonResponse(1, '请选择举报类型');
        }

        if (mb_strlen($description) > 500) {
            jsonResponse(1, '补充说明不能超过500字');
        }

        $reportId = submitReport($messageId, $reportType, $description);
        jsonResponse(0, '举报提交成功，我们会尽快处理', ['report_id' => $reportId]);
    } else {
        jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}
