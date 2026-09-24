<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$action = $_POST['action'] ?? 'submit';
$messageId = intval($_POST['message_id'] ?? 0);

if ($messageId <= 0) {
    jsonResponse(1, '无效的留言ID');
}

try {
    $db = getDB();

    if ($action === 'check') {
        $reported = hasReported($messageId);
        jsonResponse(0, '查询成功', ['reported' => $reported]);
    } elseif ($action === 'submit') {
        $reportType = trim($_POST['report_type'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($reportType === '') {
            jsonResponse(1, '请选择举报类型');
        }
        if (!in_array($reportType, ['spam', 'abuse', 'illegal', 'porn', 'other'], true)) {
            jsonResponse(1, '无效的举报类型');
        }
        // 原始说明不做 html 转义入库；展示时统一由 cleanInput 转义
        if (mb_strlen($description) > 500) {
            jsonResponse(1, '补充说明不能超过500字');
        }

        try {
            $reportId = submitReport($messageId, $reportType, $description);
            jsonResponse(0, '举报提交成功，我们会尽快处理', ['report_id' => $reportId]);
        } catch (Exception $e) {
            // code: 3=已举报过(含并发冲突) 4=留言不可见 1=参数无效 5=服务异常
            if (in_array($e->getCode(), [1, 3, 4], true)) {
                jsonResponse($e->getCode(), $e->getMessage());
            }
            error_log('[api/report.submit] ' . $e->getMessage());
            jsonResponse(500, '举报提交失败，请稍后重试');
        }
    } else {
        jsonResponse(1, '未知操作');
    }
} catch (Throwable $e) {
    error_log('[api/report] ' . $e->getMessage());
    jsonResponse(500, '服务暂时不可用，请稍后重试');
}
