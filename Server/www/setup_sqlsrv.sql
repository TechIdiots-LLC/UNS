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
INSERT INTO settings (emerg, built_in_admin, uns_ver, svn_rev) VALUES (0, 0, '1.0', '80');

IF OBJECT_ID('rss_feeds', 'U') IS NULL
CREATE TABLE rss_feeds (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  url VARCHAR(255) NOT NULL,
  maxlines INT NOT NULL
);
