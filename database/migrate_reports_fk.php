<?php
/**
 * 举报表外键修复迁移（幂等，可重复执行）
 *
 * 用法：
 *   php database/migrate_reports_fk.php
 *
 * 作用：将 reports.message_id 外键从 ON DELETE CASCADE 改为 ON DELETE SET NULL，
 *       并允许 message_id 为空。留言删除后举报记录、处理人和备注仍保留，
 *       保证待处理数量、统计卡片与举报列表始终一致。
 */
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. message_id 允许为空
    $db->exec("ALTER TABLE `reports`
        MODIFY COLUMN `message_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '被举报的留言ID（留言删除后置空）'");
    echo "[1/3] message_id 已允许为空\n";

    // 2. 查找并删除旧外键（按被引用列定位，不依赖固定约束名）
    $fkStmt = $db->prepare(
        "SELECT CONSTRAINT_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'reports'
            AND COLUMN_NAME = 'message_id'
            AND REFERENCED_TABLE_NAME = 'messages'
          LIMIT 1"
    );
    $fkStmt->execute();
    $fkName = $fkStmt->fetchColumn();

    if ($fkName) {
        $db->exec("ALTER TABLE `reports` DROP FOREIGN KEY `" . $fkName . "`");
        echo "[2/3] 已删除旧外键: {$fkName}\n";
    } else {
        echo "[2/3] 未发现旧外键，跳过删除\n";
    }

    // 3. 重新添加 SET NULL 外键（先检查避免重复添加）
    $chk = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'reports'
            AND CONSTRAINT_NAME = 'fk_reports_message'"
    );
    $chk->execute();
    if ((int) $chk->fetchColumn() === 0) {
        $db->exec("ALTER TABLE `reports`
            ADD CONSTRAINT `fk_reports_message`
            FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL");
        echo "[3/3] 已添加外键 fk_reports_message (ON DELETE SET NULL)\n";
    } else {
        echo "[3/3] 外键 fk_reports_message 已存在，跳过\n";
    }

    echo "\n迁移完成。\n";
} catch (Throwable $e) {
    fwrite(STDERR, "迁移失败: " . $e->getMessage() . "\n");
    exit(1);
}
