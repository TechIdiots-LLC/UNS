-- UNS schema for Microsoft SQL Server (T-SQL). Mirrors setup_mysql.sql.

IF OBJECT_ID('allowed_clients', 'U') IS NULL
CREATE TABLE allowed_clients (
  id INT IDENTITY(1,1) PRIMARY KEY,
  client_name VARCHAR(255) NOT NULL UNIQUE,
  led INT NOT NULL DEFAULT 1
);

IF OBJECT_ID('allowed_users', 'U') IS NULL
CREATE TABLE allowed_users (
  id INT IDENTITY(1,1) PRIMARY KEY,
  username VARCHAR(255) NOT NULL,
  domain VARCHAR(255) NOT NULL,
  tz VARCHAR(8) NOT NULL DEFAULT 'ewt:0',
  edit_urls TINYINT NOT NULL DEFAULT 1,
  edit_emerg TINYINT NOT NULL DEFAULT 1,
  edit_users TINYINT NOT NULL DEFAULT 0,
  edit_options TINYINT NOT NULL DEFAULT 0,
  c_messages TINYINT NOT NULL DEFAULT 1,
  rss_feeds TINYINT NOT NULL DEFAULT 1
);

IF OBJECT_ID('archive_links', 'U') IS NULL
CREATE TABLE archive_links (
  id INT IDENTITY(1,1) PRIMARY KEY,
  client VARCHAR(255) NOT NULL,
  urls NVARCHAR(MAX) NOT NULL,
  name VARCHAR(255) NOT NULL,
  details NVARCHAR(MAX) NOT NULL,
  date VARCHAR(32) NOT NULL
);

IF OBJECT_ID('connections', 'U') IS NULL
CREATE TABLE connections (
  id INT IDENTITY(1,1) PRIMARY KEY,
  client VARCHAR(255) NOT NULL,
  last_conn INT NOT NULL,
  last_url VARCHAR(255) NOT NULL
);

IF OBJECT_ID('c_messages', 'U') IS NULL
CREATE TABLE c_messages (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  body NVARCHAR(MAX) NOT NULL,
  refresh INT NOT NULL DEFAULT 0,
  wrapper TINYINT NOT NULL DEFAULT 1
);

IF OBJECT_ID('emerg', 'U') IS NULL
CREATE TABLE emerg (
  id INT IDENTITY(1,1) PRIMARY KEY,
  url NVARCHAR(MAX) NOT NULL,
  enabled TINYINT NOT NULL DEFAULT 0,
  refresh INT NOT NULL DEFAULT 30
  ,scope VARCHAR(8) NOT NULL DEFAULT 'all'
  ,target VARCHAR(255) NOT NULL DEFAULT ''
);

IF OBJECT_ID('friendly', 'U') IS NULL
CREATE TABLE friendly (
  id INT IDENTITY(1,1) PRIMARY KEY,
  friendly VARCHAR(255) NOT NULL UNIQUE,
  client VARCHAR(255) NOT NULL UNIQUE
);

IF OBJECT_ID('hash_links', 'U') IS NULL
CREATE TABLE hash_links (
  id INT IDENTITY(1,1) PRIMARY KEY,
  hash VARCHAR(32) NOT NULL,
  time INT NOT NULL,
  username VARCHAR(255) NOT NULL
);

IF OBJECT_ID('internal_users', 'U') IS NULL
CREATE TABLE internal_users (
  id INT IDENTITY(1,1) PRIMARY KEY,
  username VARCHAR(255) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  disabled TINYINT NOT NULL,
  failed INT NOT NULL
);

IF OBJECT_ID('saved_lists', 'U') IS NULL
CREATE TABLE saved_lists (
  id INT IDENTITY(1,1) PRIMARY KEY,
  urls NVARCHAR(MAX) NOT NULL,
  name VARCHAR(255) NOT NULL UNIQUE,
  details NVARCHAR(MAX) NOT NULL,
  date VARCHAR(32) NOT NULL
);

IF OBJECT_ID('settings', 'U') IS NULL
CREATE TABLE settings (
  id INT IDENTITY(1,1) PRIMARY KEY,
  emerg TINYINT NOT NULL DEFAULT 0,
  built_in_admin TINYINT NOT NULL DEFAULT 0,
  uns_ver VARCHAR(32) NOT NULL,
  svn_rev VARCHAR(32) NOT NULL
);

IF NOT EXISTS (SELECT 1 FROM settings)
INSERT INTO settings (emerg, built_in_admin, uns_ver, svn_rev) VALUES (0, 0, '3.1.0', '80');

IF OBJECT_ID('rss_feeds', 'U') IS NULL
CREATE TABLE rss_feeds (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  url VARCHAR(255) NOT NULL,
  maxlines INT NOT NULL
);

-- General key/value settings store, see setup_mysql.sql for notes.
IF OBJECT_ID('uns_config', 'U') IS NULL
CREATE TABLE uns_config (
  cfg_key VARCHAR(64) NOT NULL PRIMARY KEY,
  cfg_value NVARCHAR(MAX) NOT NULL
);

-- Client groups, see setup_mysql.sql for notes.
IF OBJECT_ID('client_groups', 'U') IS NULL
CREATE TABLE client_groups (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  description NVARCHAR(MAX) NOT NULL DEFAULT '',
  mode VARCHAR(8) NOT NULL DEFAULT 'add',
  priority INT NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1
);

IF OBJECT_ID('client_group_members', 'U') IS NULL
CREATE TABLE client_group_members (
  group_id INT NOT NULL,
  client VARCHAR(255) NOT NULL,
  PRIMARY KEY (group_id, client)
);

IF OBJECT_ID('group_links', 'U') IS NULL
CREATE TABLE group_links (
  id INT IDENTITY(1,1) PRIMARY KEY,
  group_id INT NOT NULL,
  url VARCHAR(255) NOT NULL,
  disabled TINYINT NOT NULL DEFAULT 0,
  refresh INT NOT NULL DEFAULT 60,
  CONSTRAINT group_url UNIQUE (group_id, url)
);

-- Targeted emergency mode, see setup_mysql.sql for notes.
IF OBJECT_ID('emerg_targets', 'U') IS NULL
CREATE TABLE emerg_targets (
  id INT IDENTITY(1,1) PRIMARY KEY,
  scope VARCHAR(8) NOT NULL,
  target VARCHAR(255) NOT NULL,
  active TINYINT NOT NULL DEFAULT 0,
  until INT NOT NULL DEFAULT 0,
  source VARCHAR(16) NOT NULL DEFAULT 'manual',
  note VARCHAR(255) NOT NULL DEFAULT '',
  updated INT NOT NULL DEFAULT 0,
  CONSTRAINT scope_target UNIQUE (scope, target)
);

IF OBJECT_ID('emerg_routes', 'U') IS NULL
CREATE TABLE emerg_routes (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name VARCHAR(255) NOT NULL DEFAULT '',
  scope VARCHAR(8) NOT NULL DEFAULT 'all',
  target VARCHAR(255) NOT NULL DEFAULT '',
  field VARCHAR(16) NOT NULL DEFAULT 'event',
  op VARCHAR(10) NOT NULL DEFAULT 'contains',
  value VARCHAR(255) NOT NULL DEFAULT '',
  min_severity VARCHAR(16) NOT NULL DEFAULT 'Unknown',
  enabled TINYINT NOT NULL DEFAULT 1
);
