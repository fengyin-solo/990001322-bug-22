-- 举报表迁移脚本
-- 执行此 SQL 来添加举报功能所需的表结构

USE `community_board`;

-- 创建举报表
CREATE TABLE IF NOT EXISTS `reports` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '被举报的留言ID（留言删除后置空）',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '举报人访客标识',
    `report_type` VARCHAR(50) NOT NULL COMMENT '举报类型: spam垃圾信息, abuse辱骂攻击, illegal违法违规, porn色情低俗, other其他',
    `description` TEXT COMMENT '补充说明',
    `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待处理, 1已处理-已删除, 2已处理-已忽略, 3已驳回',
    `processed_by` INT UNSIGNED DEFAULT NULL COMMENT '处理人管理员ID',
    `processed_at` DATETIME DEFAULT NULL COMMENT '处理时间',
    `process_note` VARCHAR(500) DEFAULT NULL COMMENT '处理备注',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '举报时间',
    UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_visitor_id` (`visitor_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`processed_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表';

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'reports';
-- DESCRIBE reports;
