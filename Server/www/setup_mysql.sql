
CREATE TABLE IF NOT EXISTS `allowed_clients` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `client_name` varchar(255) NOT NULL,
  `led` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_name` (`client_name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `allowed_users`
--

CREATE TABLE IF NOT EXISTS `allowed_users` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `tz` VARCHAR( 8 ) NOT NULL DEFAULT 'ewt:0',
  `edit_urls` tinyint(4) NOT NULL DEFAULT '1',
  `edit_emerg` tinyint(4) NOT NULL DEFAULT '1',
  `edit_users` tinyint(4) NOT NULL DEFAULT '0',
  `edit_options` tinyint(4) NOT NULL DEFAULT '0',
  `c_messages` tinyint(4) NOT NULL DEFAULT '1',
  `rss_feeds` tinyint(4) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `archive_links`
--

CREATE TABLE IF NOT EXISTS `archive_links` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `client` varchar(255) NOT NULL,
  `urls` text COLLATE utf8_bin NOT NULL,
  `name` varchar(255) NOT NULL,
  `details` text NOT NULL,
  `date` varchar(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `connections`
--

CREATE TABLE IF NOT EXISTS `connections` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `client` varchar(255) NOT NULL,
  `last_conn` int(32) NOT NULL,
  `last_url` varchar(255) COLLATE utf8_bin NOT NULL,
  KEY `id` (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `c_messages`
--

CREATE TABLE IF NOT EXISTS `c_messages` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `refresh` int(255) NOT NULL DEFAULT '0',
  `wrapper` tinyint(4) NOT NULL DEFAULT '1',
  UNIQUE KEY `name` (`name`),
  KEY `id` (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `emerg`
--

CREATE TABLE IF NOT EXISTS `emerg` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `url` text COLLATE utf8_bin NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT '0',
  `refresh` int(255) NOT NULL DEFAULT '30',
  `scope` varchar(8) NOT NULL DEFAULT 'all',
  `target` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `friendly`
--

CREATE TABLE IF NOT EXISTS `friendly` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `friendly` varchar(255) NOT NULL,
  `client` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `friendly` (`friendly`),
  UNIQUE KEY `client` (`client`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `hash_links`
--

CREATE TABLE IF NOT EXISTS `hash_links` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `hash` varchar(32) NOT NULL,
  `time` int(9) NOT NULL,
  `username` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=0;
-- --------------------------------------------------------
--
-- Table structure for table `internal_users`
--

CREATE TABLE IF NOT EXISTS `internal_users` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) COLLATE utf8_bin NOT NULL,
  `password` varchar(255) COLLATE utf8_bin NOT NULL,
  `disabled` tinyint(4) NOT NULL,
  `failed` int(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0 ;

--
-- Table structure for table `saved_lists`
--

CREATE TABLE IF NOT EXISTS `saved_lists` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `urls` text COLLATE utf8_bin NOT NULL,
  `name` varchar(255) NOT NULL,
  `details` text NOT NULL,
  `date` varchar(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `emerg` tinyint(4) NOT NULL DEFAULT '0',
  `built_in_admin` tinyint(1) NOT NULL DEFAULT '0',
  `uns_ver` varchar(32) NOT NULL,
  `svn_rev` varchar(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;


INSERT INTO `settings` (`id`, `emerg`, `built_in_admin`, `uns_ver`, `svn_rev`) VALUES
(1, 0, 0, '2.0.0', '80');

-- --------------------------------------------------------

--
-- Table structure for table `rss_feeds`
--

CREATE TABLE IF NOT EXISTS `rss_feeds` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8_bin NOT NULL,
  `url` varchar(255) COLLATE utf8_bin NOT NULL,
  `maxlines` int(255) NOT NULL,
  KEY `id` (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Table structure for table `uns_config`
--
-- General key/value settings store. Newer settings live here rather than in
-- configs/vars.php so they are backed up and restored with the database.
--

CREATE TABLE IF NOT EXISTS `uns_config` (
  `cfg_key` varchar(64) NOT NULL,
  `cfg_value` text NOT NULL,
  PRIMARY KEY (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Client groups
--
-- A group is a named collection of clients with its own URL list. Membership is
-- many-to-many: a screen can belong to several groups at once (a building, and a
-- role such as "outdoor"), which is what makes targeted alerting possible later.
--
-- Members are keyed by client_name rather than allowed_clients.id, because every
-- other table that refers to a client (friendly, connections, archive_links, and
-- the per-client "<client>_links" table) does the same.
--
-- mode controls how a group's list combines with the member's own list:
--   add     - the group's URLs join the client's normal rotation
--   replace - while active, members show only this group's URLs
-- priority breaks the tie when a client is in more than one active replace group;
-- the highest wins.
--

CREATE TABLE IF NOT EXISTS `client_groups` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `mode` varchar(8) NOT NULL DEFAULT 'add',
  `priority` int(11) NOT NULL DEFAULT '0',
  `active` tinyint(4) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

CREATE TABLE IF NOT EXISTS `client_group_members` (
  `group_id` int(255) NOT NULL,
  `client` varchar(255) NOT NULL,
  PRIMARY KEY (`group_id`, `client`),
  KEY `client` (`client`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `group_links` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `group_id` int(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `disabled` tinyint(4) NOT NULL DEFAULT '0',
  `refresh` int(5) NOT NULL DEFAULT '60',
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_url` (`group_id`, `url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

-- --------------------------------------------------------

--
-- Targeted emergency mode
--
-- settings.emerg remains the global switch that puts every client into emergency
-- mode. emerg_targets narrows it: a row here is a live emergency for one group or
-- one client, leaving every other screen on its normal rotation.
--
--   scope   'group' or 'client'
--   target  the client_groups.id, or the client_name
--   until   unix time the emergency lapses; 0 means it stays until cleared.
--           Alerts carry their own expiry, and the client page treats a lapsed row
--           as inactive, so a monitor that dies cannot strand screens on an alert.
--   source  'manual' for an administrator, 'monitor' for the feed daemon. The
--           daemon only ever clears what it raised, so a hand-set takeover is
--           never stomped by the next feed poll.
--
-- The emerg URL rows are scoped the same way; a target with no URLs of its own
-- falls back to the scope='all' list.
--

CREATE TABLE IF NOT EXISTS `emerg_targets` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `scope` varchar(8) NOT NULL,
  `target` varchar(255) NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT '0',
  `until` int(11) NOT NULL DEFAULT '0',
  `source` varchar(16) NOT NULL DEFAULT 'manual',
  `note` varchar(255) NOT NULL DEFAULT '',
  `updated` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `scope_target` (`scope`, `target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;

--
-- Which alerts reach which screens.
--
-- Each row says "an alert whose <field> <op> <value> raises emergency mode for
-- <scope>:<target>". With no rows configured the monitor behaves exactly as it did
-- before targeting existed and drives the global flag only.
--

CREATE TABLE IF NOT EXISTS `emerg_routes` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `scope` varchar(8) NOT NULL DEFAULT 'all',
  `target` varchar(255) NOT NULL DEFAULT '',
  `field` varchar(16) NOT NULL DEFAULT 'event',
  `op` varchar(10) NOT NULL DEFAULT 'contains',
  `value` varchar(255) NOT NULL DEFAULT '',
  `min_severity` varchar(16) NOT NULL DEFAULT 'Unknown',
  `enabled` tinyint(4) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;
