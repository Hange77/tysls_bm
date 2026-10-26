-- ============================================================
-- 书签管理系统 数据库结构
-- 适用于 MySQL 5.7+ / 8.0
-- 字符集: utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- 用户表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}users`;
CREATE TABLE `{prefix}users` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '用户名(登录名,URL安全)',
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `password` varchar(255) NOT NULL COMMENT '密码哈希',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称/显示名',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像URL',
  `bio` varchar(255) NOT NULL DEFAULT '' COMMENT '个人简介',
  `role` enum('user','admin') NOT NULL DEFAULT 'user' COMMENT '角色',
  `theme` varchar(50) NOT NULL DEFAULT 'default' COMMENT '界面主题',
  `dark_mode` enum('off','on','auto') NOT NULL DEFAULT 'off' COMMENT '深色模式',
  `home_layout` varchar(50) NOT NULL DEFAULT 'default' COMMENT '首页布局:default/vertical',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '邮箱是否已验证',
  `verify_token` varchar(64) NOT NULL DEFAULT '' COMMENT '邮箱验证token哈希',
  `verify_expire` int(11) NOT NULL DEFAULT 0 COMMENT '邮箱验证token过期时间',
  `reset_token` varchar(64) NOT NULL DEFAULT '' COMMENT '找回密码token哈希',
  `reset_expire` int(11) NOT NULL DEFAULT 0 COMMENT '找回密码token过期时间',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `sync_prefs` text COMMENT '同步偏好JSON(删除开关/回收站天数/受保护分类)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- -----------------------------------------------------------
-- 分类表 (无限级)
-- user_id = 0 表示系统分类(管理员在后台"网址大全"中添加)
-- user_id > 0 表示用户个人分类
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}categories`;
CREATE TABLE `{prefix}categories` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属用户ID,0=系统分类',
  `parent_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类ID,0=顶级',
  `name` varchar(100) NOT NULL COMMENT '分类名称',
  `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '分类描述',
  `icon` varchar(100) NOT NULL DEFAULT '' COMMENT '图标(emoji或class)',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序(小的在前)',
  `is_public` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否公开',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '访问密码(空=无密码)',
  `source` enum('manual','browser') NOT NULL DEFAULT 'manual' COMMENT '来源:手动/浏览器同步',
  `ext_id` varchar(191) NOT NULL DEFAULT '' COMMENT '浏览器文件夹ID(双向锚定)',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间,NULL=未删除',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_parent` (`user_id`,`parent_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_ext` (`user_id`,`ext_id`),
  KEY `idx_user_deleted` (`user_id`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分类表(无限级)';

-- -----------------------------------------------------------
-- 书签表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}bookmarks`;
CREATE TABLE `{prefix}bookmarks` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属用户ID,0=系统书签(网址大全)',
  `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属分类ID',
  `title` varchar(200) NOT NULL COMMENT '书签标题',
  `url` varchar(2048) NOT NULL COMMENT '书签URL',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '书签描述',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '图标URL或emoji',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `is_public` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否公开',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否推荐位(高亮)',
  `featured_color` varchar(7) NOT NULL DEFAULT '' COMMENT '推荐位标题颜色(#rrggbb,空=仅加粗)',
  `click_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '点击次数',
  `domain` varchar(255) NOT NULL DEFAULT '' COMMENT '域名(由url派生,用于筛选/查重)',
  `source` enum('manual','browser') NOT NULL DEFAULT 'manual' COMMENT '来源:手动/浏览器同步',
  `ext_id` varchar(191) NOT NULL DEFAULT '' COMMENT '浏览器书签节点ID(双向锚定)',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间,NULL=未删除',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_category` (`user_id`,`category_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user_ext` (`user_id`,`ext_id`),
  KEY `idx_user_deleted` (`user_id`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='书签表';

-- -----------------------------------------------------------
-- 页面/便签表
-- content_type: html = 后台管理页面(关于页/公告等,可写任意HTML)
--               text = 用户便签(纯文字)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}pages`;
CREATE TABLE `{prefix}pages` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属用户ID,0=系统页面',
  `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属分类ID',
  `title` varchar(200) NOT NULL COMMENT '页面标题',
  `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `content` longtext NOT NULL COMMENT '页面内容',
  `content_type` enum('html','text') NOT NULL DEFAULT 'text' COMMENT '内容类型',
  `is_public` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否公开',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '访问密码',
  `view_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览次数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间,NULL=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_user_category` (`user_id`,`category_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_slug` (`slug`),
  KEY `idx_user_deleted` (`user_id`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='页面/便签表';

-- -----------------------------------------------------------
-- API令牌表 (浏览器插件鉴权, Bearer Token)
-- token_hash = sha256(明文token), 明文仅下发一次
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}api_tokens`;
CREATE TABLE `{prefix}api_tokens` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED NOT NULL COMMENT '所属用户',
  `name` varchar(50) NOT NULL DEFAULT '插件' COMMENT '设备/令牌名称',
  `token_hash` varchar(64) NOT NULL COMMENT 'token哈希(sha256)',
  `last_used_at` datetime DEFAULT NULL COMMENT '最近使用时间',
  `revoked_at` datetime DEFAULT NULL COMMENT '吊销时间,NULL=有效',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_revoked` (`revoked_at`),
  KEY `idx_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API令牌表(浏览器插件鉴权)';

-- -----------------------------------------------------------
-- 操作日志表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}operation_logs`;
CREATE TABLE `{prefix}operation_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作用户ID',
  `username` varchar(50) NOT NULL DEFAULT '' COMMENT '操作用户名(冗余便于查询)',
  `action` varchar(50) NOT NULL COMMENT '操作类型:login/register/create/update/delete/grant/etc',
  `target_type` varchar(50) NOT NULL DEFAULT '' COMMENT '目标类型:user/bookmark/category/page',
  `target_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '目标ID',
  `detail` varchar(1000) NOT NULL DEFAULT '' COMMENT '操作详情(JSON或文本)',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '操作IP',
  `user_agent` varchar(500) NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志表';

-- -----------------------------------------------------------
-- 系统设置表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}settings`;
CREATE TABLE `{prefix}settings` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` varchar(50) NOT NULL COMMENT '设置键',
  `svalue` text COMMENT '设置值',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '说明',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置表';

-- -----------------------------------------------------------
-- 初始数据: 系统设置
-- -----------------------------------------------------------
INSERT INTO `{prefix}settings` (`skey`, `svalue`, `description`) VALUES
('site_name', '书签导航', '网站名称'),
('site_description', '简洁好用的书签导航系统', '网站描述'),
('site_keywords', '书签,导航,网址大全', '网站关键词'),
('allow_register', '1', '是否允许用户注册(1=是,0=否)'),
('default_bookmark_public', '1', '用户添加书签默认是否公开(1=是,0=否)'),
('default_theme', 'default', '界面主题全局默认-主题颜色'),
('default_dark_mode', 'off', '界面主题全局默认-深色模式'),
('default_home_layout', 'default', '界面主题全局默认-首页布局'),
('icp_number', '', 'ICP备案号'),
('analytics_code', '', '统计代码(HTML)'),
('marquee_enabled', '1', '首页公告跑马灯是否启用(1=是,0=否)'),
('marquee_direction', 'horizontal', '跑马灯滚动方向(horizontal=横向向左,vertical=纵向向上)'),
('marquee_speed', '30', '跑马灯滚动速度(秒/轮,数值越大越慢)'),
('recycle_retention_days', '30', '回收站保留天数(0=永久)'),
('bookmark_desc_mode', 'off', '书签描述显示方式(off=关闭,hover=悬停提示,below=标题下方)'),
('search_engines', '[{"name":"必应","url_template":"https://cn.bing.com/search?q=%s","enabled":1,"sort":1},{"name":"百度","url_template":"https://www.baidu.com/s?wd=%s","enabled":1,"sort":2},{"name":"谷歌","url_template":"https://www.google.com/search?q=%s","enabled":1,"sort":3}]', '聚合搜索-站外搜索引擎配置(JSON:name/url_template/enabled/sort)');

-- -----------------------------------------------------------
-- 站点公告表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}announcements`;
CREATE TABLE `{prefix}announcements` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '公告标题(可选)',
  `content` varchar(1000) NOT NULL COMMENT '公告内容',
  `link_url` varchar(500) NOT NULL DEFAULT '' COMMENT '点击跳转链接(空=不跳转)',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序(小的在前)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  `expire_at` datetime DEFAULT NULL COMMENT '过期时间(NULL=永不过期)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='站点公告表';

-- -----------------------------------------------------------
-- 广告位表
-- position: top=分类区域最上方, bottom=所有分类最下方, category=指定分类下方
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}ads`;
CREATE TABLE `{prefix}ads` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `position` enum('top','bottom','category') NOT NULL DEFAULT 'top' COMMENT '广告位位置',
  `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '当position=category时指定的分类ID',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '广告名称(管理用)',
  `code` text NOT NULL COMMENT '广告代码(HTML/JS)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `display_scope` enum('all','site','user') NOT NULL DEFAULT 'all' COMMENT '显示范围:all=全站,site=仅网址大全,user=仅用户收藏夹',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_position` (`position`,`category_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='广告位表';

-- -----------------------------------------------------------
-- 首页友情链接表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `{prefix}friend_links`;
CREATE TABLE `{prefix}friend_links` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '链接名称',
  `url` varchar(500) NOT NULL COMMENT '链接地址',
  `icon` varchar(500) NOT NULL DEFAULT '' COMMENT '图标(外链图片URL)',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '描述(后台备注/悬浮提示)',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序(小的在前)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否显示',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active_sort` (`is_active`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='首页友情链接表';

SET FOREIGN_KEY_CHECKS = 1;
