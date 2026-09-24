-- 留言软删除迁移脚本
-- 背景：举报处理选择“删除留言”时，原实现物理删除 messages 记录，
-- reports 表外键 ON DELETE CASCADE 会连带删除举报记录，
-- 导致举报列表/统计与实际不一致、处理人与备注丢失。
-- 改为软删除后，留言在前台/后台均不可见，但举报记录完整保留。

USE `community_board`;

ALTER TABLE `messages`
    ADD COLUMN `is_deleted` TINYINT NOT NULL DEFAULT 0
        COMMENT '是否已删除(举报处理删除为软删除, 保留举报记录): 0否, 1是'
    AFTER `status`;

-- 历史上已被物理删除的留言无法恢复，其举报记录已被级联删除，无需额外处理。
