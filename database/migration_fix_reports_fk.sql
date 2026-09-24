-- 举报表外键修复迁移
-- 问题：reports.message_id 外键为 ON DELETE CASCADE，留言被删除（含“删除留言”处置）时，
--       举报记录被物理级联删除，导致待处理数量、已删除统计与举报列表对不上，处理留痕丢失。
-- 修复：留言删除时将 reports.message_id 置空（ON DELETE SET NULL），保留举报记录、处理人与备注。
--
-- 用法：
--   mysql -uroot -p community_board < migration_fix_reports_fk.sql
-- 或使用 PHP 脚本：
--   php database/migrate_reports_fk.php

USE `community_board`;

-- 1. message_id 允许为空（留言删除后保留举报记录）
ALTER TABLE `reports`
    MODIFY COLUMN `message_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '被举报的留言ID（留言删除后置空）';

-- 2. 动态查找并删除旧的 ON DELETE CASCADE 外键（约束名因环境而异）
SET @old_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'reports'
      AND COLUMN_NAME = 'message_id'
      AND REFERENCED_TABLE_NAME = 'messages'
    LIMIT 1
);
SET @drop_sql := IF(
    @old_fk IS NOT NULL,
    CONCAT('ALTER TABLE `reports` DROP FOREIGN KEY `', @old_fk, '`'),
    'DO 0'
);
PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. 重新添加 SET NULL 外键
ALTER TABLE `reports`
    ADD CONSTRAINT `fk_reports_message`
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL;

-- 验证：
-- SHOW CREATE TABLE reports;
