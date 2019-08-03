CREATE TABLE `{{prefix}}__acls` (
  `page_tag` varchar(50) NOT NULL DEFAULT '',
  `privilege` varchar(20) NOT NULL DEFAULT '',
  `list` text NOT NULL,
  PRIMARY KEY (`page_tag`,`privilege`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{{prefix}}__links` (
  `from_tag` char(50) NOT NULL DEFAULT '',
  `to_tag` char(50) NOT NULL DEFAULT '',
  UNIQUE KEY `from_tag` (`from_tag`,`to_tag`),
  KEY `idx_from` (`from_tag`),
  KEY `idx_to` (`to_tag`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{{prefix}}__nature` (
  `bn_id_nature` int(10) unsigned NOT NULL DEFAULT '0',
  `bn_label_nature` varchar(255) DEFAULT NULL,
  `bn_description` text,
  `bn_condition` text,
  `bn_ce_id_menu` int(3) unsigned NOT NULL DEFAULT '0',
  `bn_commentaire` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `bn_appropriation` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `bn_image_titre` varchar(255) NOT NULL DEFAULT '',
  `bn_image_logo` varchar(255) NOT NULL DEFAULT '',
  `bn_couleur_calendrier` varchar(255) NOT NULL DEFAULT '',
  `bn_picto_calendrier` varchar(255) NOT NULL DEFAULT '',
  `bn_template` text NOT NULL,
  `bn_ce_i18n` varchar(5) NOT NULL DEFAULT '',
  `bn_type_fiche` varchar(255) NOT NULL,
  `bn_label_class` varchar(255) NOT NULL,
  PRIMARY KEY (`bn_id_nature`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{{prefix}}__pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tag` varchar(50) NOT NULL DEFAULT '',
  `time` datetime NOT NULL,
  `body` text NOT NULL,
  `body_r` text NOT NULL,
  `owner` varchar(50) NOT NULL DEFAULT '',
  `user` varchar(50) NOT NULL DEFAULT '',
  `latest` enum('Y','N') NOT NULL DEFAULT 'N',
  `handler` varchar(30) NOT NULL DEFAULT 'page',
  `comment_on` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_tag` (`tag`),
  KEY `idx_time` (`time`),
  KEY `idx_latest` (`latest`),
  KEY `idx_comment_on` (`comment_on`),
  FULLTEXT KEY `tag` (`tag`,`body`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{{prefix}}__referrers` (
  `page_tag` char(50) NOT NULL DEFAULT '',
  `referrer` char(150) NOT NULL DEFAULT '',
  `time` datetime NOT NULL,
  KEY `idx_page_tag` (`page_tag`),
  KEY `idx_time` (`time`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{{prefix}}__triples` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `resource` varchar(255) NOT NULL DEFAULT '',
  `property` varchar(255) NOT NULL DEFAULT '',
  `value` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `resource` (`resource`),
  KEY `property` (`property`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `{{prefix}}__triples` (`id`, `resource`, `property`, `value`) VALUES
(1, 'ThisWikiGroup:admins', 'http://www.wikini.net/_vocabulary/acls', '{{WikiName}}');

CREATE TABLE `{{prefix}}__users` (
  `name` varchar(80) NOT NULL DEFAULT '',
  `password` varchar(32) NOT NULL DEFAULT '',
  `email` varchar(50) NOT NULL DEFAULT '',
  `motto` text NOT NULL,
  `revisioncount` int(10) unsigned NOT NULL DEFAULT '20',
  `changescount` int(10) unsigned NOT NULL DEFAULT '50',
  `doubleclickedit` enum('Y','N') NOT NULL DEFAULT 'Y',
  `signuptime` datetime NOT NULL,
  `show_comments` enum('Y','N') NOT NULL DEFAULT 'N',
  PRIMARY KEY (`name`),
  KEY `idx_name` (`name`),
  KEY `idx_signuptime` (`signuptime`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `{{prefix}}__users` (`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) VALUES
('{{WikiName}}', md5('{{password}}'), '{{email}}', '', 20, 50, 'Y',  now(), 'N');
