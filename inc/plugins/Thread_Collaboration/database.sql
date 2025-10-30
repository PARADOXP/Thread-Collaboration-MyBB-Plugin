-- Thread Collaboration plugin schema

-- Collaborators table
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}thread_collaborators` (
    `cid` INT(11) NOT NULL AUTO_INCREMENT,
    `tid` INT(11) NOT NULL,
    `uid` INT(11) NOT NULL,
    `role` VARCHAR(255) NOT NULL,
    `role_icon` VARCHAR(100) DEFAULT NULL,
    `joined_date` INT(11) NOT NULL DEFAULT 0,
    `joined_via` ENUM('invitation', 'request', 'direct') DEFAULT 'direct',
    `source_id` INT(11) DEFAULT NULL,
    PRIMARY KEY (`cid`),
    KEY `tid` (`tid`),
    KEY `uid` (`uid`),
    KEY `joined_date` (`joined_date`),
    KEY `joined_via` (`joined_via`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invitations table
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collaboration_invitations` (
    `invite_id` INT(11) NOT NULL AUTO_INCREMENT,
    `tid` INT(11) NOT NULL,
    `inviter_uid` INT(11) NOT NULL,
    `invitee_uid` INT(11) NOT NULL,
    `role` VARCHAR(255) NOT NULL,
    `role_icon` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
    `invite_date` INT(11) NOT NULL,
    `response_date` INT(11) DEFAULT NULL,
    PRIMARY KEY (`invite_id`),
    KEY `tid` (`tid`),
    KEY `inviter_uid` (`inviter_uid`),
    KEY `invitee_uid` (`invitee_uid`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Requests table
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collaboration_requests` (
    `request_id` INT(11) NOT NULL AUTO_INCREMENT,
    `tid` INT(11) NOT NULL,
    `requester_uid` INT(11) NOT NULL,
    `thread_owner_uid` INT(11) NOT NULL,
    `role` VARCHAR(255) NOT NULL,
    `role_icon` VARCHAR(100) DEFAULT NULL,
    `message` TEXT DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `request_date` INT(11) NOT NULL,
    `response_date` INT(11) DEFAULT NULL,
    `response_message` TEXT DEFAULT NULL,
    PRIMARY KEY (`request_id`),
    KEY `tid` (`tid`),
    KEY `requester_uid` (`requester_uid`),
    KEY `thread_owner_uid` (`thread_owner_uid`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User settings table
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collaboration_user_settings` (
    `uid` INT(11) NOT NULL,
    `requests_enabled` TINYINT(1) DEFAULT 0,
    `thread_ids` TEXT DEFAULT NULL,
    `updated_date` INT(11) NOT NULL,
    `owner_icon` VARCHAR(100) DEFAULT NULL,
    `show_all_roles` TINYINT(1) DEFAULT 1,
    `postbit_roles` TEXT DEFAULT NULL,
    PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pending post edits approvals
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_post_edits` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `pid` INT(11) NOT NULL,
    `tid` INT(11) NOT NULL,
    `editor_uid` INT(11) NOT NULL,
    `owner_uid` INT(11) NOT NULL,
    `draft` MEDIUMTEXT NOT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `dateline` INT(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `pid` (`pid`),
    KEY `tid` (`tid`),
    KEY `owner_uid` (`owner_uid`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Edit history table for tracking all post edits
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_edit_history` (
    `history_id` INT(11) NOT NULL AUTO_INCREMENT,
    `pid` INT(11) NOT NULL,
    `tid` INT(11) NOT NULL,
    `editor_uid` INT(11) NOT NULL,
    `edit_type` ENUM('create', 'edit', 'restore') DEFAULT 'edit',
    `original_content` LONGTEXT DEFAULT NULL,
    `new_content` LONGTEXT NOT NULL,
    `original_subject` VARCHAR(255) DEFAULT NULL,
    `new_subject` VARCHAR(255) DEFAULT NULL,
    `edit_reason` VARCHAR(500) DEFAULT NULL,
    `restore_from_id` INT(11) DEFAULT NULL,
    `dateline` INT(11) NOT NULL,
    `ip_address` VARBINARY(16) DEFAULT NULL,
    PRIMARY KEY (`history_id`),
    KEY `pid` (`pid`),
    KEY `tid` (`tid`),
    KEY `editor_uid` (`editor_uid`),
    KEY `edit_type` (`edit_type`),
    KEY `dateline` (`dateline`),
    KEY `restore_from_id` (`restore_from_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Collaboration chat messages table
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_chat_messages` (
    `message_id` INT(11) NOT NULL AUTO_INCREMENT,
    `tid` INT(11) NOT NULL,
    `uid` INT(11) NOT NULL,
    `message` TEXT NOT NULL,
    `reply_to` INT(11) DEFAULT 0,
    `dateline` INT(11) NOT NULL,
    `ip_address` VARBINARY(16) DEFAULT NULL,
    `is_system` TINYINT(1) DEFAULT 0,
    `system_type` VARCHAR(50) DEFAULT NULL,
    `edited` TINYINT(1) NOT NULL DEFAULT 0,
    `edit_time` INT(11) DEFAULT NULL,
    PRIMARY KEY (`message_id`),
    KEY `tid` (`tid`),
    KEY `uid` (`uid`),
    KEY `dateline` (`dateline`),
    KEY `is_system` (`is_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_drafts` (
    `draft_id` INT(11) NOT NULL AUTO_INCREMENT,
    `tid` INT(11) NOT NULL,
    `uid` INT(11) NOT NULL,
    `subject` VARCHAR(200) NOT NULL,
    `content` TEXT NOT NULL,
    `dateline` INT(11) NOT NULL,
    `last_edit` INT(11) NOT NULL,
    `status` ENUM('draft', 'ready', 'published', 'archived') DEFAULT 'draft',
    `published_date` INT(11) DEFAULT NULL,
    `published_post_id` INT(11) DEFAULT NULL,
    `archived_date` INT(11) DEFAULT NULL,
    `archived_by` INT(11) DEFAULT NULL,
    PRIMARY KEY (`draft_id`),
    KEY `tid` (`tid`),
    KEY `uid` (`uid`),
    KEY `status` (`status`),
    KEY `published_date` (`published_date`),
    KEY `published_post_id` (`published_post_id`),
    KEY `archived_date` (`archived_date`),
    KEY `archived_by` (`archived_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_draft_contributions` (
    `contribution_id` INT(11) NOT NULL AUTO_INCREMENT,
    `draft_id` INT(11) NOT NULL,
    `uid` INT(11) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `characters_added` INT(11) DEFAULT 0,
    `characters_removed` INT(11) DEFAULT 0,
    `dateline` INT(11) NOT NULL,
    PRIMARY KEY (`contribution_id`),
    KEY `draft_id` (`draft_id`),
    KEY `uid` (`uid`),
    KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chat settings table for user preferences
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_chat_settings` (
    `setting_id` INT(11) NOT NULL AUTO_INCREMENT,
    `tid` INT(11) NOT NULL,
    `uid` INT(11) NOT NULL,
    `setting_name` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `dateline` INT(11) NOT NULL,
    PRIMARY KEY (`setting_id`),
    UNIQUE KEY `user_thread_setting` (`tid`, `uid`, `setting_name`),
    KEY `tid` (`tid`),
    KEY `uid` (`uid`),
    KEY `setting_name` (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contributor posts table for multi-author reputation system
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_contributor_posts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `pid` INT(11) NOT NULL,
    `tid` INT(11) NOT NULL,
    `uid` INT(11) NOT NULL,
    `draft_id` INT(11) NOT NULL,
    `contribution_percentage` DECIMAL(5,2) DEFAULT 0.00,
    `is_primary_author` TINYINT(1) DEFAULT 0,
    `added_date` INT(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `post_user` (`pid`, `uid`),
    KEY `pid` (`pid`),
    KEY `tid` (`tid`),
    KEY `uid` (`uid`),
    KEY `draft_id` (`draft_id`),
    KEY `is_primary_author` (`is_primary_author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Draft management settings table
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_draft_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tid` INT(11) NOT NULL,
    `allow_collaborators_publish` TINYINT(1) DEFAULT 0,
    `publish_as_author` TINYINT(1) DEFAULT 0,
    `publishing_permissions` ENUM('all', 'primary', 'collaborators') DEFAULT 'all',
    `allowed_collaborators` TEXT DEFAULT NULL,
    `created_by` INT(11) NOT NULL,
    `created_date` INT(11) NOT NULL,
    `updated_date` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tid` (`tid`),
    KEY `created_by` (`created_by`),
    KEY `created_date` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Draft edit logs table for detailed tracking
CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}collab_draft_edit_logs` (
    `log_id` INT(11) NOT NULL AUTO_INCREMENT,
    `draft_id` INT(11) NOT NULL,
    `uid` INT(11) NOT NULL,
    `action` ENUM('create', 'edit', 'delete', 'restore') DEFAULT 'edit',
    `content_before` LONGTEXT DEFAULT NULL,
    `content_after` LONGTEXT NOT NULL,
    `characters_added` INT(11) DEFAULT 0,
    `characters_removed` INT(11) DEFAULT 0,
    `words_added` INT(11) DEFAULT 0,
    `words_removed` INT(11) DEFAULT 0,
    `edit_summary` VARCHAR(500) DEFAULT NULL,
    `dateline` INT(11) NOT NULL,
    `ip_address` VARBINARY(16) DEFAULT NULL,
    `actual_additions` TEXT NULL,
    `actual_removals` TEXT NULL,
    PRIMARY KEY (`log_id`),
    KEY `draft_id` (`draft_id`),
    KEY `uid` (`uid`),
    KEY `action` (`action`),
    KEY `dateline` (`dateline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration for existing installations to add advanced contribution tracking columns
-- Run this if you already have the collab_draft_edit_logs table:
-- ALTER TABLE `{TABLE_PREFIX}collab_draft_edit_logs` 
-- ADD COLUMN `actual_additions` TEXT NULL AFTER `ip_address`,
-- ADD COLUMN `actual_removals` TEXT NULL AFTER `actual_additions`;

-- Migration for existing installations to add archive functionality
-- Run this if you already have the collab_drafts table:
-- ALTER TABLE `{TABLE_PREFIX}collab_drafts` 
-- MODIFY COLUMN `status` ENUM('draft', 'ready', 'published', 'archived') DEFAULT 'draft',
-- ADD COLUMN `archived_date` INT(11) DEFAULT NULL AFTER `published_post_id`,
-- ADD COLUMN `archived_by` INT(11) DEFAULT NULL AFTER `archived_date`,
-- ADD KEY `archived_date` (`archived_date`),
-- ADD KEY `archived_by` (`archived_by`);

