<?php
#    shared.php, Some shared functions for both the Client page and the admin page
#    Copyright (C) 2010  Phillip Ferland / Random Intervals
#    Copyright (C) 2026  Andrew Calcutt / TechIdiots LLC
#
#    SPDX-License-Identifier: GPL-2.0-or-later
#
#    This program is free software; you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation; either version 2 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program; if not, write to the Free Software Foundation,
#    Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.

# --- Database portability layer -------------------------------------------
# UNS supports MySQL, Microsoft SQL Server, and SQLite through PDO. $driver
# comes from configs/conn.php ('mysql' [default], 'sqlsrv', or 'sqlite'). For
# sqlite, $db is a filesystem path to the database file rather than a name,
# and $server/$username/$password are ignored.

function db_connect($server, $username, $password, $db, $driver = 'mysql')
{
    # PHP 7.4 defaults PDO to ERRMODE_SILENT, while PHP 8.0+ defaults to
    # ERRMODE_EXCEPTION. Pin it at construction time (before any exec() runs) so both
    # behave identically: this app checks return values throughout - if($stmt) and
    # "!== false" - and reports failures via db_error(), which is the same contract the
    # original mysqli code used. Without this, a failed query is a graceful message on
    # 7.4 but an uncaught fatal on 8.x.
    $opts = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT);

    try
    {
        switch($driver)
        {
            case 'sqlite':
                $pdo = new PDO('sqlite:'.$db, null, null, $opts);
                $pdo->exec('PRAGMA foreign_keys = ON');
                # This app opens a fresh connection per function call (fine for a
                # client/server DB, but SQLite is a single file with much stricter
                # locking). WAL mode lets readers and a writer coexist instead of
                # blocking outright, and the busy timeout makes PDO retry for a bit
                # instead of immediately failing with "database is locked".
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                break;
            case 'sqlsrv':
                # Newer ODBC Driver for SQL Server versions default to encrypted connections
                # with strict certificate validation, which breaks connections to instances
                # using a self-signed/internal cert unless this is set (same as WifiDB's
                # SQL.inc.php - see the connection notes there for the underlying reason).
                $pdo = new PDO('sqlsrv:Server='.$server.';Database='.$db.';TrustServerCertificate=true', $username, $password, $opts);
                break;
            case 'mysql':
            default:
                $pdo = new PDO('mysql:host='.$server.';dbname='.$db.';charset=utf8mb4', $username, $password, $opts);
                break;
        }
        return $pdo;
    }
    catch(PDOException $e)
    {
        # Callers throughout this app expect a falsy return on connection failure
        # (mirroring the old mysqli_connect() contract), not an exception.
        return false;
    }
}

# PDO/PDOStatement both expose errorInfo(); this works for either.
function db_error($conn)
{
    if(!$conn){return 'No connection';}
    $info = $conn->errorInfo();
    return $info[2] ?? '';
}

function db_truncate_table($conn, $driver, $table)
{
    if($driver === 'sqlite')
    {
        return $conn->exec('DELETE FROM '.$table) !== false;
    }
    return $conn->exec('TRUNCATE TABLE '.$table) !== false;
}

# Creates the per-client "<client>_links" table. $table must already be validated
# against is_safe_client_id()-style whitelisting by the caller - it can't be
# parameterized since it's an identifier, not a value.
function db_create_links_table($conn, $driver, $table)
{
    switch($driver)
    {
        case 'sqlite':
            $sql = "CREATE TABLE IF NOT EXISTS $table (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url VARCHAR(255) NOT NULL UNIQUE,
                disabled TINYINT NOT NULL DEFAULT 0,
                refresh INTEGER NOT NULL DEFAULT 60
            )";
            break;
        case 'sqlsrv':
            $sql = "IF OBJECT_ID('$table', 'U') IS NULL
            CREATE TABLE $table (
                id INT IDENTITY(1,1) PRIMARY KEY,
                url VARCHAR(255) NOT NULL UNIQUE,
                disabled TINYINT NOT NULL DEFAULT 0,
                refresh INT NOT NULL DEFAULT 60
            )";
            break;
        case 'mysql':
        default:
            $sql = "CREATE TABLE IF NOT EXISTS $table (
                id int(255) NOT NULL AUTO_INCREMENT,
                url varchar(255) NOT NULL,
                disabled tinyint(4) NOT NULL DEFAULT '0',
                refresh int(5) NOT NULL DEFAULT '60',
                PRIMARY KEY (id),
                UNIQUE (url)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1";
            break;
    }
    return $conn->exec($sql) !== false;
}
# --- Writable data directory ------------------------------------------------
#
# UNS needs somewhere to write things that are not configuration: a SQLite database,
# and Smarty's compiled templates and cache. None of it should ever be served over
# HTTP, so the preference is a folder OUTSIDE the document root, falling back to
# configs/ (which ships deny rules) only when nothing better is writable.
#
# These live here rather than in install.php because the running app needs them too -
# Smarty resolves its compile directory on every request.

# True if $path resolves to somewhere under the web server's document root - ie. a file
# placed there is potentially fetchable over HTTP. When the document root cannot be
# determined we return true, so callers fall back to the location we know is protected.
function uns_path_is_public($path)
{
    $docroot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if($docroot === false){return true;}

    # The folder may not exist yet (we are often asked about it before creating it), and
    # realpath() fails on a missing path - so resolve the nearest ancestor that does
    # exist. A folder is inside the web root exactly when its parent chain is.
    $probe = $path;
    while(realpath($probe) === false)
    {
        $up = dirname($probe);
        if($up === $probe){return true;}
        $probe = $up;
    }

    $target  = rtrim(str_replace('\\', '/', realpath($probe)), '/').'/';
    $docroot = rtrim(str_replace('\\', '/', $docroot), '/').'/';

    # Windows paths are case-insensitive, and IIS's DOCUMENT_ROOT often differs in case
    # from what realpath() returns. A case-sensitive compare would then report a folder
    # that IS inside the web root as safe - the dangerous direction to get wrong.
    if(PHP_OS_FAMILY === 'Windows'){return stripos($target, $docroot) === 0;}
    return strpos($target, $docroot) === 0;
}

# Drop deny rules into a folder that must never be served. Apache reads .htaccess and
# ignores web.config, IIS does the exact opposite, so write both rather than guessing
# which one will be in front of the folder later. The index.php is the one that works
# regardless of server configuration - AllowOverride None or locked IIS sections can
# neutralise the other two, but a directory request still resolves to the index file.
#
# This is belt and braces: while the folder is outside the document root none of it does
# anything, because no URL maps there. It earns its keep only if the layout later changes.
function uns_write_dir_guards($dir)
{
    $htaccess = $dir.'/.htaccess';
    if(!file_exists($htaccess))
    {
        @file_put_contents($htaccess,
            "# UNS data folder - nothing in here should ever be served over HTTP.\n"
            ."#\n"
            ."# Does nothing while this folder is outside the document root, and Apache\n"
            ."# ignores it entirely under AllowOverride None. IIS uses web.config instead.\n"
            ."# PHP reads these files from the filesystem, so denying everything here\n"
            ."# costs the application nothing.\n"
            ."Options -Indexes\n"
            ."<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            ."<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    $webconfig = $dir.'/web.config';
    if(!file_exists($webconfig))
    {
        # allowUnlisted="false" denies every extension not explicitly allowed, and nothing
        # is allowed here - a blanket deny rather than a blocklist. requestFiltering is in
        # the base IIS install, unlike URL Authorization which 500s when absent.
        @file_put_contents($webconfig,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<!-- UNS data folder - nothing in here should ever be served over HTTP. -->\n"
            ."<configuration>\n    <system.webServer>\n        <directoryBrowse enabled=\"false\" />\n"
            ."        <security>\n            <requestFiltering>\n"
            ."                <fileExtensions allowUnlisted=\"false\" />\n"
            ."            </requestFiltering>\n        </security>\n"
            ."    </system.webServer>\n</configuration>\n");
    }

    $index = $dir.'/index.php';
    if(!file_exists($index)){@file_put_contents($index, "<?php # Intentionally blank - keeps this folder from being listed.\n");}
}

# Resolves (and optionally creates) UNS's writable data folder, or a named subfolder of
# it. Returns a path that is outside the document root where possible; otherwise a
# folder under configs/, which ships the same deny rules.
function uns_data_dir($subdir = '', $create = false)
{
    $candidate = dirname(__DIR__).'/uns-data';
    $parent    = dirname(__DIR__);

    # Judge by the parent, which always exists, so this answers the same before and after
    # the folder is created.
    $base = null;
    if(!uns_path_is_public($parent) && is_writable(is_dir($candidate) ? $candidate : $parent))
    {
        if($create && !is_dir($candidate)){@mkdir($candidate, 0750, true);}
        if(!$create || is_dir($candidate)){$base = $candidate;}
    }
    if($base === null){$base = __DIR__.'/configs';}

    if($create && is_dir($base)){uns_write_dir_guards($base);}

    if($subdir === ''){return $base;}

    $path = $base.'/'.$subdir;
    if($create && !is_dir($path)){@mkdir($path, 0750, true);}

    # Guard the subfolder too, not just the base. When the base is configs/ - the fallback
    # when nothing outside the web root is writable - it already carries a shipped
    # .htaccess that only denies database files, and uns_write_dir_guards() will not
    # replace it. Compiled Smarty templates are generated PHP, so without a rule of their
    # own they would sit in the document root and be executable by direct request.
    if($create && is_dir($path)){uns_write_dir_guards($path);}

    return $path;
}

# --- Templating -------------------------------------------------------------
#
# UNS 3.0 renders through Smarty rather than echoing HTML from inside PHP. The library
# is vendored under lib/smarty (UNS has no Composer setup) and loaded through the stub
# PSR-4 loader Smarty ships for exactly that case.
#
# Compiled templates and the cache go in the writable data folder, which is outside the
# document root wherever possible - they are generated PHP, and must never be served.

function uns_smarty()
{
    static $smarty = null;
    if($smarty !== null){return $smarty;}

    require_once __DIR__.'/lib/smarty/libs/Smarty.class.php';

    $smarty = new Smarty\Smarty();
    $smarty->setTemplateDir(__DIR__.'/templates');

    # Smarty throws an uncaught exception when it cannot write a compiled template, which
    # reaches the browser as a bare fatal naming an internal Smarty file. Check first and
    # say which folder is at fault and how to fix it, since this is always a permissions
    # problem on the server rather than anything the page itself did wrong.
    $compile_dir = uns_data_dir('templates_c', true);
    $cache_dir   = uns_data_dir('templates_cache', true);

    # Collect every unusable folder before reporting. Failing on the first one means
    # fixing it, reloading, and being told about the next - which is exactly what
    # happens with these two, since Smarty only touches the cache folder after the
    # compile folder is working.
    $bad = array();
    foreach(array($compile_dir, $cache_dir) as $dir)
    {
        if(!is_dir($dir) || !is_writable($dir)){$bad[] = $dir;}
    }
    if($bad)
    {
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'the web server user')
            : 'the web server user';
        $user_esc = htmlspecialchars($user, ENT_QUOTES);
        $msg = "<h3>UNS cannot write its template folder".(count($bad) === 1 ? "" : "s")."</h3>"
            ."<p>Smarty compiles templates on every page. PHP is running as <b>".$user_esc."</b>"
            ." and needs to write to the following, so run all of it in one go:</p><pre>";
        foreach($bad as $dir)
        {
            $shown = htmlspecialchars($dir, ENT_QUOTES);
            $msg .= "sudo mkdir -p '".$shown."'\n"
                 ."sudo chown -R ".$user_esc." '".$shown."'\n"
                 ."sudo chmod 750 '".$shown."'\n";
        }
        die($msg."</pre>");
    }

    $smarty->setCompileDir($compile_dir);
    $smarty->setCacheDir($cache_dir);

    # Caching is off: every page here is either per-request (an admin screen) or already
    # cheap (a client redirect). Compilation is still cached, which is where the win is.
    $smarty->setCaching(Smarty\Smarty::CACHING_OFF);

    # Escape on output by default. Smarty ships with this OFF, so without it every {$var}
    # would emit raw HTML - a step backwards from the htmlspecialchars() calls the inline
    # markup used. With it on, the handful of places that legitimately emit stored HTML
    # (a custom message body, a generated RSS block) say so explicitly with "nofilter".
    $smarty->setEscapeHtml(true);

    # Values every template can rely on.
    $vars = __DIR__.'/configs/vars.php';
    if(is_readable($vars))
    {
        include $vars;
        $smarty->assign('uns_title', isset($name_title) ? $name_title : 'URL Notification System');
        $smarty->assign('uns_ssl', !empty($SSL));
        $smarty->assign('uns_root', isset($root) ? $root : '');
        $smarty->assign('uns_host', isset($host) ? $host : '');
    }
    $smarty->assign('uns_version', uns_version());

    return $smarty;
}

# --- Settings stored in the database ---------------------------------------
#
# configs/vars.php still holds the original settings, but anything added since lives
# here instead: it is backed up and restored with the database, and reachable by any
# process with database access rather than only by something that can read a PHP file
# on the web server.
#
# UNS has no schema migration mechanism, so the table is created on demand the first
# time it is used. Installs that predate it pick it up with no upgrade step - the same
# approach db_create_links_table() already uses for the per-client link tables.

function db_create_config_table($conn, $driver)
{
    switch($driver)
    {
        case 'sqlite':
            $sql = "CREATE TABLE IF NOT EXISTS uns_config (
                cfg_key VARCHAR(64) NOT NULL PRIMARY KEY,
                cfg_value TEXT NOT NULL
            )";
            break;
        case 'sqlsrv':
            $sql = "IF OBJECT_ID('uns_config', 'U') IS NULL
            CREATE TABLE uns_config (
                cfg_key VARCHAR(64) NOT NULL PRIMARY KEY,
                cfg_value NVARCHAR(MAX) NOT NULL
            )";
            break;
        case 'mysql':
        default:
            $sql = "CREATE TABLE IF NOT EXISTS uns_config (
                cfg_key varchar(64) NOT NULL,
                cfg_value text NOT NULL,
                PRIMARY KEY (cfg_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
            break;
    }
    return $conn->exec($sql) !== false;
}

# Every setting in one round trip. The create-on-demand only runs once per request.
function uns_config_all($conn, $driver)
{
    static $ensured = false;
    if(!$ensured){db_create_config_table($conn, $driver); $ensured = true;}

    $out = array();
    $stmt = $conn->query("SELECT cfg_key, cfg_value FROM uns_config");
    if(!$stmt){return $out;}
    while($row = $stmt->fetch(PDO::FETCH_ASSOC))
    {
        $out[$row['cfg_key']] = $row['cfg_value'];
    }
    return $out;
}

function uns_config_get($conn, $driver, $key, $default = '')
{
    $all = uns_config_all($conn, $driver);
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function uns_config_set($conn, $driver, $key, $value)
{
    static $ensured = false;
    if(!$ensured){db_create_config_table($conn, $driver); $ensured = true;}

    # SELECT then UPDATE/INSERT rather than a driver-specific upsert. Checking
    # rowCount() after an UPDATE would not work either: MySQL reports 0 rows changed
    # when the value is written back unchanged, which would trigger a duplicate INSERT.
    $sel = $conn->prepare("SELECT cfg_key FROM uns_config WHERE cfg_key = ?");
    $exists = false;
    if($sel && $sel->execute(array($key))){$exists = (bool)$sel->fetch(PDO::FETCH_ASSOC);}

    if($exists)
    {
        $upd = $conn->prepare("UPDATE uns_config SET cfg_value = ? WHERE cfg_key = ?");
        return $upd ? $upd->execute(array((string)$value, $key)) : false;
    }
    $ins = $conn->prepare("INSERT INTO uns_config (cfg_key, cfg_value) VALUES (?, ?)");
    return $ins ? $ins->execute(array($key, (string)$value)) : false;
}

# --- Client groups ----------------------------------------------------------
#
# A group is a named collection of clients with its own URL list. Membership is
# many-to-many, so one screen can sit in several groups at once.
#
# Created on demand like uns_config, so installs predating groups pick the tables
# up with no upgrade step.

function db_create_group_tables($conn, $driver)
{
    switch($driver)
    {
        case 'sqlite':
            $sql = array(
                "CREATE TABLE IF NOT EXISTS client_groups (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL UNIQUE,
                    description TEXT NOT NULL DEFAULT '',
                    mode VARCHAR(8) NOT NULL DEFAULT 'add',
                    priority INTEGER NOT NULL DEFAULT 0,
                    active TINYINT NOT NULL DEFAULT 1
                )",
                "CREATE TABLE IF NOT EXISTS client_group_members (
                    group_id INTEGER NOT NULL,
                    client VARCHAR(255) NOT NULL,
                    PRIMARY KEY (group_id, client)
                )",
                "CREATE TABLE IF NOT EXISTS group_links (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    group_id INTEGER NOT NULL,
                    url VARCHAR(255) NOT NULL,
                    disabled TINYINT NOT NULL DEFAULT 0,
                    refresh INTEGER NOT NULL DEFAULT 60,
                    UNIQUE (group_id, url)
                )",
            );
            break;
        case 'sqlsrv':
            $sql = array(
                "IF OBJECT_ID('client_groups', 'U') IS NULL
                CREATE TABLE client_groups (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL UNIQUE,
                    description NVARCHAR(MAX) NOT NULL DEFAULT '',
                    mode VARCHAR(8) NOT NULL DEFAULT 'add',
                    priority INT NOT NULL DEFAULT 0,
                    active TINYINT NOT NULL DEFAULT 1
                )",
                "IF OBJECT_ID('client_group_members', 'U') IS NULL
                CREATE TABLE client_group_members (
                    group_id INT NOT NULL,
                    client VARCHAR(255) NOT NULL,
                    PRIMARY KEY (group_id, client)
                )",
                "IF OBJECT_ID('group_links', 'U') IS NULL
                CREATE TABLE group_links (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    group_id INT NOT NULL,
                    url VARCHAR(255) NOT NULL,
                    disabled TINYINT NOT NULL DEFAULT 0,
                    refresh INT NOT NULL DEFAULT 60,
                    CONSTRAINT group_url UNIQUE (group_id, url)
                )",
            );
            break;
        case 'mysql':
        default:
            $sql = array(
                "CREATE TABLE IF NOT EXISTS client_groups (
                    id int(255) NOT NULL AUTO_INCREMENT,
                    name varchar(255) NOT NULL,
                    description text NOT NULL,
                    mode varchar(8) NOT NULL DEFAULT 'add',
                    priority int(11) NOT NULL DEFAULT '0',
                    active tinyint(4) NOT NULL DEFAULT '1',
                    PRIMARY KEY (id),
                    UNIQUE KEY name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
                "CREATE TABLE IF NOT EXISTS client_group_members (
                    group_id int(255) NOT NULL,
                    client varchar(255) NOT NULL,
                    PRIMARY KEY (group_id, client),
                    KEY client (client)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
                "CREATE TABLE IF NOT EXISTS group_links (
                    id int(255) NOT NULL AUTO_INCREMENT,
                    group_id int(255) NOT NULL,
                    url varchar(255) NOT NULL,
                    disabled tinyint(4) NOT NULL DEFAULT '0',
                    refresh int(5) NOT NULL DEFAULT '60',
                    PRIMARY KEY (id),
                    UNIQUE KEY group_url (group_id, url)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            );
            break;
    }
    $ok = true;
    foreach($sql as $q){if($conn->exec($q) === false){$ok = false;}}
    return $ok;
}

# Runs the create-on-demand once per request. Called by the admin screens, which are
# about to write and so need the tables to exist up front.
function uns_groups_ensure($conn, $driver)
{
    static $ensured = false;
    if(!$ensured){db_create_group_tables($conn, $driver); $ensured = true;}
}

# Reads from the group tables, creating them and retrying once if they aren't there.
#
# The client page runs this on every hit, so it must not issue DDL just to guarantee
# the tables exist: that would mean three CREATE TABLE IF NOT EXISTS per screen per
# refresh, and would require the runtime database user to hold DDL rights forever.
# Reading first keeps the steady state at one SELECT, and an install upgrading from a
# pre-groups database still self-heals on its first request.
function uns_groups_select($conn, $driver, $sql, $params = array())
{
    static $created = false;

    $stmt = $conn->prepare($sql);
    if($stmt && $stmt->execute($params)){return $stmt;}
    if($created){return false;}

    $created = true;
    db_create_group_tables($conn, $driver);

    $stmt = $conn->prepare($sql);
    if($stmt && $stmt->execute($params)){return $stmt;}
    return false;
}

# Every group a client belongs to, most important first. Used by both the client
# page (to resolve its URL list) and the admin screens (to show membership).
function uns_client_groups($conn, $driver, $client)
{
    $out  = array();
    $stmt = uns_groups_select($conn, $driver,
        "SELECT g.id, g.name, g.mode, g.priority, g.active
           FROM client_groups g, client_group_members m
          WHERE m.group_id = g.id AND m.client = ?
       ORDER BY g.priority DESC, g.id ASC", array($client));
    if(!$stmt){return $out;}
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){$out[] = $row;}
    return $out;
}

# The enabled URLs belonging to one group, as [[url, refresh], ...].
function uns_group_links($conn, $driver, $group_id)
{
    $out  = array();
    $stmt = uns_groups_select($conn, $driver,
        "SELECT url, refresh FROM group_links WHERE group_id = ? AND disabled != '1'",
        array((int)$group_id));
    if(!$stmt){return $out;}
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){$out[] = array($row['url'], $row['refresh']);}
    return $out;
}

# Resolves what a client should actually rotate through, as [[url, refresh], ...].
#
# $own is the client's own list, already read from its "<client>_links" table.
# A group in 'replace' mode takes the screen over entirely while it is active, so
# the highest-priority active replace group wins outright and nothing else is
# mixed in. Otherwise every active 'add' group contributes its URLs alongside the
# client's own. A replace group with no usable URLs is ignored rather than
# blanking the screen.
function uns_resolve_client_urls($conn, $driver, $client, $own)
{
    $groups = uns_client_groups($conn, $driver, $client);
    if(!$groups){return $own;}

    $extra = array();
    foreach($groups as $g)
    {
        if(empty($g['active'])){continue;}
        $links = uns_group_links($conn, $driver, $g['id']);
        if(!$links){continue;}

        # uns_client_groups() orders by priority, so the first replace group that
        # actually has URLs is the winner.
        if($g['mode'] === 'replace'){return $links;}
        foreach($links as $l){$extra[] = $l;}
    }

    # De-duplicate by URL: a client can be in two groups carrying the same page, and
    # array_rand() would otherwise weight it double in the rotation.
    $seen = array();
    $out  = array();
    foreach(array_merge($own, $extra) as $l)
    {
        if(isset($seen[$l[0]])){continue;}
        $seen[$l[0]] = true;
        $out[] = $l;
    }
    return $out;
}

# Drops a client out of every group. Called when the client itself is removed, so
# membership rows cannot outlive the client and silently re-attach to a later
# client that happens to reuse the name.
function uns_groups_forget_client($conn, $driver, $client)
{
    uns_groups_ensure($conn, $driver);
    $stmt = $conn->prepare("DELETE FROM client_group_members WHERE client = ?");
    return $stmt ? $stmt->execute(array($client)) : false;
}

# --- Sessions ---------------------------------------------------------------

# Mints a login session: a random token in hash_links plus the cookie that names it.
#
# Lives here rather than in admin/index.php because it is the single point at which a
# user becomes logged in, and the SSO entry point needs it too. The token is the only
# thing that authorises a request - the username travels in the cookie for display
# only, and is always re-read from hash_links server-side.
function uns_create_session($conn, $username_login, $timeout, $root, $ssl)
{
    $hash = bin2hex(random_bytes(16));   # must be unguessable, hence a CSPRNG
    $time = ($timeout > 0) ? time() + $timeout : 0;
    $path = ($root === '' || $root === '/') ? '/admin' : '/'.$root.'admin';

    if(!setcookie('login_yes', $hash.':'.$username_login, $time, $path, '', (bool)$ssl, true))
    {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO hash_links (hash, time, username) VALUES (?, ?, ?)");
    if(!$stmt || !$stmt->execute(array($hash, $time, $username_login))){return false;}
    return $hash;
}

# --- LDAP / Active Directory ------------------------------------------------
#
# The original code connected with ldap_connect($host, $port) and bound straight
# away. That is an unencrypted connection, so the administrator's domain password
# crossed the network in the clear - and on the default port 3268 (the Global
# Catalog), which does not offer StartTLS at all.
#
# Encryption is now selectable. It defaults to 'none' so an existing install keeps
# working after an upgrade rather than failing to authenticate, but the options page
# says plainly that it should not stay there.

function uns_ldap_settings($conn, $driver)
{
    $all = uns_config_all($conn, $driver);
    $enc = isset($all['ldap_encryption']) ? $all['ldap_encryption'] : '';
    if(!in_array($enc, array('none', 'starttls', 'ldaps'), true)){$enc = 'none';}

    return array(
        'encryption'  => $enc,
        # Verifying the certificate is the point of encrypting at all, so it defaults on.
        # Domain controllers commonly present a certificate from an internal CA, which
        # the web server has to trust (LDAPTLS_CACERT / ldap.conf TLS_CACERT).
        'verify_cert' => isset($all['ldap_verify_cert']) ? (int)$all['ldap_verify_cert'] : 1,
    );
}

# The default port for a given encryption mode, following Active Directory's layout:
# 389/636 for the domain, 3268/3269 for the Global Catalog.
function uns_ldap_default_port($encryption)
{
    return ($encryption === 'ldaps') ? 636 : 389;
}

# Builds the connection URI.
#
# ldap_connect() with separate host and port arguments is deprecated as of PHP 8.3;
# the URI form is what it wants now, and it is also the only way to ask for ldaps.
function uns_ldap_uri($host, $port, $encryption)
{
    $host = trim((string)$host);
    # Tolerate a host already written as a URI, so an administrator who typed
    # "ldaps://dc.example.edu" does not end up with "ldap://ldaps://...".
    if(preg_match('#^ldaps?://#i', $host))
    {
        $parsed = parse_url($host);
        if(isset($parsed['scheme']) && strtolower($parsed['scheme']) === 'ldaps'){$encryption = 'ldaps';}
        if(isset($parsed['port']) && !$port){$port = $parsed['port'];}
        $host = isset($parsed['host']) ? $parsed['host'] : '';
    }
    if($host === ''){return '';}

    $scheme = ($encryption === 'ldaps') ? 'ldaps' : 'ldap';
    $port   = (int)$port;
    if($port <= 0){$port = uns_ldap_default_port($encryption);}

    return $scheme.'://'.$host.':'.$port;
}

# Binds as $user. Returns true on success; on failure $error explains why.
#
# Kept here rather than inline in the login handler so the connection setup has one
# definition, and so the URI building above can be tested without a directory server.
function uns_ldap_bind($host, $port, $encryption, $verify_cert, $user, $pass, &$error)
{
    $error = '';
    if(!function_exists('ldap_connect'))
    {
        $error = 'The PHP ldap extension is not installed.';
        return false;
    }

    $uri = uns_ldap_uri($host, $port, $encryption);
    if($uri === '')
    {
        $error = 'No LDAP server is configured.';
        return false;
    }

    # Certificate policy has to be set before the connection is made: with OpenLDAP the
    # option is read when the TLS context is built, and setting it on the link
    # afterwards is silently ignored for ldaps://.
    if($encryption !== 'none' && defined('LDAP_OPT_X_TLS_REQUIRE_CERT'))
    {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT,
            $verify_cert ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER);
    }

    $ldap = @ldap_connect($uri);
    if(!$ldap)
    {
        $error = 'Could not create an LDAP connection to '.$uri.'.';
        return false;
    }

    # v3 is required for StartTLS and is what Active Directory expects; referrals off is
    # the usual setting for AD, which otherwise chases them and fails the bind.
    @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    if(defined('LDAP_OPT_NETWORK_TIMEOUT')){@ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 10);}

    if($encryption === 'starttls')
    {
        if(!@ldap_start_tls($ldap))
        {
            # Deliberately not falling back to an unencrypted bind: silently downgrading
            # would hand over the password in the clear exactly when something is wrong.
            $error = 'STARTTLS failed on '.$uri.'. Check the port (389, not the Global'
                   .' Catalog 3268) and that the server certificate is trusted.';
            @ldap_unbind($ldap);
            return false;
        }
    }

    $ok = @ldap_bind($ldap, $user, $pass);
    if(!$ok){$error = 'Login failed against '.$uri.'.';}
    @ldap_unbind($ldap);
    return (bool)$ok;
}

# --- Single sign-on ---------------------------------------------------------
#
# UNS does not speak SAML or OpenID Connect itself. It trusts an identity already
# established by the web server - mod_auth_openidc, a Shibboleth SP, or IIS Windows
# authentication - which keeps every protocol detail, certificate and signature check
# outside the application, where it is maintained by people who do that for a living.
#
# The contract is simply: the web server authenticates a request to admin/sso.php and
# exposes who the user is in a server variable. See INSTALL for the configuration.

# The authentication mode. Kept backward compatible: installs that predate this only
# have the $LDAP flag in configs/vars.php, and must keep behaving the way they did.
function uns_auth_mode($conn, $driver, $ldap_flag)
{
    $mode = uns_config_get($conn, $driver, 'auth_mode', '');
    if(in_array($mode, array('internal', 'ldap', 'sso'), true)){return $mode;}
    return !empty($ldap_flag) ? 'ldap' : 'internal';
}

function uns_sso_config($conn, $driver)
{
    $all = uns_config_all($conn, $driver);
    $get = function($key, $default) use ($all)
    {
        return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
    };

    return array(
        # Which server variable names the user. REMOTE_USER is what both
        # mod_auth_openidc and mod_shib populate by default, and unlike an HTTP header
        # a client cannot set it.
        'user_var'      => $get('sso_user_var', 'REMOTE_USER'),
        # Server variables sourced from request headers (anything HTTP_*) are supplied
        # by whoever made the request. Trusting one is only safe when a proxy in front
        # is guaranteed to overwrite it, so it has to be opted into deliberately.
        'allow_headers' => (int)$get('sso_allow_headers', '0'),
        'strip_domain'  => (int)$get('sso_strip_domain', '1'),
        'lowercase'     => (int)$get('sso_lowercase', '1'),
        'autocreate'    => (int)$get('sso_autocreate', '0'),
        'group_var'     => $get('sso_group_var', ''),
        'admin_group'   => $get('sso_admin_group', ''),
        'logout_url'    => $get('sso_logout_url', ''),
        'button_label'  => $get('sso_button_label', 'Sign in with SSO'),
    );
}

# True if reading this variable would mean trusting something the client sent.
function uns_sso_var_is_header($name)
{
    return strpos((string)$name, 'HTTP_') === 0;
}

# The identity the web server established, or '' if it did not establish one.
function uns_sso_identity($cfg, $server = null)
{
    if($server === null){$server = $_SERVER;}
    $var = $cfg['user_var'];
    if($var === ''){return '';}
    if(uns_sso_var_is_header($var) && empty($cfg['allow_headers'])){return '';}
    return isset($server[$var]) ? trim((string)$server[$var]) : '';
}

# Turns whatever the identity provider called the user into the name UNS matches
# against allowed_users: DOMAIN\user and user@domain.example both reduce to "user"
# when strip_domain is on.
function uns_sso_normalize($raw, $cfg)
{
    $name = trim((string)$raw);
    if($name === ''){return '';}

    if(!empty($cfg['strip_domain']))
    {
        $slash = strrpos($name, "\\");
        if($slash !== false){$name = substr($name, $slash + 1);}
        $at = strpos($name, '@');
        if($at !== false){$name = substr($name, 0, $at);}
    }
    if(!empty($cfg['lowercase'])){$name = strtolower($name);}

    # allowed_users.username is compared as an exact string everywhere else, so refuse
    # anything that is not a plausible account name rather than storing it.
    if(!preg_match('/^[A-Za-z0-9._\-]{1,255}$/', $name)){return '';}
    return $name;
}

# The groups the identity provider says the user is in. Providers disagree about the
# separator - mod_auth_openidc joins a JSON array with commas, Shibboleth uses
# semicolons - so all the common ones are accepted.
function uns_sso_groups($cfg, $server = null)
{
    if($server === null){$server = $_SERVER;}
    $var = $cfg['group_var'];
    if($var === ''){return array();}
    if(uns_sso_var_is_header($var) && empty($cfg['allow_headers'])){return array();}
    if(!isset($server[$var])){return array();}

    $parts = preg_split('/[;,\r\n]+/', (string)$server[$var]);
    $out = array();
    foreach($parts as $p)
    {
        $p = trim($p);
        if($p !== ''){$out[] = $p;}
    }
    return $out;
}

function uns_sso_in_group($groups, $wanted)
{
    if($wanted === ''){return false;}
    foreach($groups as $g)
    {
        if(strcasecmp($g, $wanted) === 0){return true;}
    }
    return false;
}

# --- Targeted emergency mode ------------------------------------------------
#
# settings.emerg is still the global switch: on, and every client shows the
# emergency list. emerg_targets narrows it, holding one row per group or client
# that is currently in emergency mode on its own.
#
# Precedence for a client is global, then its own target row, then the highest
# priority group it belongs to that has one. The emergency URLs are scoped the
# same way, and a target with no URLs of its own falls back to the shared list,
# so a group emergency still shows something before anyone curates URLs for it.

# True if a column already exists. Driver-specific because there is no portable way
# to ask, and the answer decides whether ALTER TABLE needs to run at all.
function uns_column_exists($conn, $driver, $table, $column)
{
    switch($driver)
    {
        case 'sqlite':
            $stmt = $conn->query("PRAGMA table_info(".$table.")");
            if(!$stmt){return false;}
            while($row = $stmt->fetch(PDO::FETCH_ASSOC))
            {
                if(isset($row['name']) && strcasecmp($row['name'], $column) === 0){return true;}
            }
            return false;
        case 'sqlsrv':
            $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?");
            if(!$stmt || !$stmt->execute(array($table, $column))){return false;}
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        case 'mysql':
        default:
            $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS"
                ." WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            if(!$stmt || !$stmt->execute(array($table, $column))){return false;}
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

# Adds a column if it isn't there. $table/$column are literals from this file, never
# user input - they cannot be parameterized as identifiers.
function uns_ensure_column($conn, $driver, $table, $column, $definition)
{
    if(uns_column_exists($conn, $driver, $table, $column)){return true;}
    return $conn->exec("ALTER TABLE ".$table." ADD ".$column." ".$definition) !== false;
}

function db_create_emerg_tables($conn, $driver)
{
    switch($driver)
    {
        case 'sqlite':
            $sql = array(
                "CREATE TABLE IF NOT EXISTS emerg_targets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    scope VARCHAR(8) NOT NULL,
                    target VARCHAR(255) NOT NULL,
                    active TINYINT NOT NULL DEFAULT 0,
                    until INTEGER NOT NULL DEFAULT 0,
                    source VARCHAR(16) NOT NULL DEFAULT 'manual',
                    note VARCHAR(255) NOT NULL DEFAULT '',
                    updated INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (scope, target)
                )",
                "CREATE TABLE IF NOT EXISTS emerg_routes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL DEFAULT '',
                    scope VARCHAR(8) NOT NULL DEFAULT 'all',
                    target VARCHAR(255) NOT NULL DEFAULT '',
                    field VARCHAR(16) NOT NULL DEFAULT 'event',
                    op VARCHAR(10) NOT NULL DEFAULT 'contains',
                    value VARCHAR(255) NOT NULL DEFAULT '',
                    min_severity VARCHAR(16) NOT NULL DEFAULT 'Unknown',
                    enabled TINYINT NOT NULL DEFAULT 1
                )",
            );
            $col = "VARCHAR(8) NOT NULL DEFAULT 'all'";
            $tgt = "VARCHAR(255) NOT NULL DEFAULT ''";
            break;
        case 'sqlsrv':
            $sql = array(
                "IF OBJECT_ID('emerg_targets', 'U') IS NULL
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
                )",
                "IF OBJECT_ID('emerg_routes', 'U') IS NULL
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
                )",
            );
            $col = "VARCHAR(8) NOT NULL DEFAULT 'all'";
            $tgt = "VARCHAR(255) NOT NULL DEFAULT ''";
            break;
        case 'mysql':
        default:
            $sql = array(
                "CREATE TABLE IF NOT EXISTS emerg_targets (
                    id int(255) NOT NULL AUTO_INCREMENT,
                    scope varchar(8) NOT NULL,
                    target varchar(255) NOT NULL,
                    active tinyint(4) NOT NULL DEFAULT '0',
                    until int(11) NOT NULL DEFAULT '0',
                    source varchar(16) NOT NULL DEFAULT 'manual',
                    note varchar(255) NOT NULL DEFAULT '',
                    updated int(11) NOT NULL DEFAULT '0',
                    PRIMARY KEY (id),
                    UNIQUE KEY scope_target (scope, target)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
                "CREATE TABLE IF NOT EXISTS emerg_routes (
                    id int(255) NOT NULL AUTO_INCREMENT,
                    name varchar(255) NOT NULL DEFAULT '',
                    scope varchar(8) NOT NULL DEFAULT 'all',
                    target varchar(255) NOT NULL DEFAULT '',
                    field varchar(16) NOT NULL DEFAULT 'event',
                    op varchar(10) NOT NULL DEFAULT 'contains',
                    value varchar(255) NOT NULL DEFAULT '',
                    min_severity varchar(16) NOT NULL DEFAULT 'Unknown',
                    enabled tinyint(4) NOT NULL DEFAULT '1',
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            );
            $col = "varchar(8) NOT NULL DEFAULT 'all'";
            $tgt = "varchar(255) NOT NULL DEFAULT ''";
            break;
    }
    $ok = true;
    foreach($sql as $q){if($conn->exec($q) === false){$ok = false;}}

    # emerg predates targeting, so these two are added to the existing table rather
    # than shipped in it. Defaulting scope to 'all' keeps every existing row meaning
    # exactly what it meant before: shown to every client.
    if(!uns_ensure_column($conn, $driver, 'emerg', 'scope', $col)){$ok = false;}
    if(!uns_ensure_column($conn, $driver, 'emerg', 'target', $tgt)){$ok = false;}
    return $ok;
}

function uns_emerg_ensure($conn, $driver)
{
    static $ensured = false;
    if(!$ensured){db_create_emerg_tables($conn, $driver); $ensured = true;}
}

# Read-then-create, for the same reason uns_groups_select() does it: the client page
# runs this on every hit and must not issue DDL to prove the tables are there.
function uns_emerg_select($conn, $driver, $sql, $params = array())
{
    static $created = false;

    $stmt = $conn->prepare($sql);
    if($stmt && $stmt->execute($params)){return $stmt;}
    if($created){return false;}

    $created = true;
    db_create_emerg_tables($conn, $driver);

    $stmt = $conn->prepare($sql);
    if($stmt && $stmt->execute($params)){return $stmt;}
    return false;
}

# An emergency target counts only while it is active and unexpired. until = 0 means
# it runs until something clears it.
function uns_emerg_target_live($row, $now)
{
    if(empty($row['active'])){return false;}
    $until = isset($row['until']) ? (int)$row['until'] : 0;
    return ($until <= 0 || $until > $now);
}

# The live emergency target covering this client, or false. A target naming the
# client beats one naming a group it belongs to, and among groups the highest
# priority wins - the same ordering uns_client_groups() already returns.
function uns_emerg_target_for_client($conn, $driver, $client, $now = null)
{
    if($now === null){$now = time();}

    $stmt = uns_emerg_select($conn, $driver,
        "SELECT * FROM emerg_targets WHERE scope = 'client' AND target = ?", array($client));
    if($stmt)
    {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if($row && uns_emerg_target_live($row, $now)){return $row;}
    }

    foreach(uns_client_groups($conn, $driver, $client) as $g)
    {
        $gs = uns_emerg_select($conn, $driver,
            "SELECT * FROM emerg_targets WHERE scope = 'group' AND target = ?", array((string)$g['id']));
        if(!$gs){continue;}
        $row = $gs->fetch(PDO::FETCH_ASSOC);
        if($row && uns_emerg_target_live($row, $now)){return $row;}
    }
    return false;
}

# Emergency URLs for one scope/target, as [[url, refresh], ...]. Falls back to the
# shared scope='all' list when the target has none of its own.
function uns_emerg_urls($conn, $driver, $scope = 'all', $target = '')
{
    $out = array();
    if($scope !== 'all')
    {
        $stmt = uns_emerg_select($conn, $driver,
            "SELECT url, refresh FROM emerg WHERE enabled = '1' AND scope = ? AND target = ?",
            array($scope, (string)$target));
        if($stmt)
        {
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){$out[] = array($row['url'], $row['refresh']);}
        }
        if($out){return $out;}
    }

    $stmt = uns_emerg_select($conn, $driver,
        "SELECT url, refresh FROM emerg WHERE enabled = '1' AND scope = 'all'");
    if(!$stmt)
    {
        # Pre-targeting database that has not been migrated yet: every row is global.
        $stmt = $conn->query("SELECT url, refresh FROM emerg WHERE enabled = '1'");
        if(!$stmt){return $out;}
    }
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)){$out[] = array($row['url'], $row['refresh']);}
    return $out;
}

# Whether this client is in emergency mode, and what to show if so.
# Returns array(bool $active, array $urls).
function uns_emerg_for_client($conn, $driver, $client, $global_on)
{
    $now = time();

    if($global_on)
    {
        # The global switch can carry an expiry too, so a monitor-raised site-wide
        # alert lapses on its own rather than needing the monitor to run again.
        $until = (int)uns_config_get($conn, $driver, 'emerg_global_until', '0');
        if($until <= 0 || $until > $now)
        {
            return array(true, uns_emerg_urls($conn, $driver, 'all', ''));
        }
    }

    $t = uns_emerg_target_for_client($conn, $driver, $client, $now);
    if($t)
    {
        return array(true, uns_emerg_urls($conn, $driver, $t['scope'], $t['target']));
    }
    return array(false, array());
}

# The admin forms carry scope and target in one "scope:target" select, so the pair can
# never arrive half-set. Anything unrecognised degrades to the global scope.
function uns_parse_scope($raw)
{
    $raw   = (string)$raw;
    $parts = explode(':', $raw, 2);
    $scope = $parts[0];
    $target = isset($parts[1]) ? $parts[1] : '';
    if(!in_array($scope, array('all', 'group', 'client'), true)){return array('all', '');}
    if($scope === 'all'){return array('all', '');}
    if($target === ''){return array('all', '');}
    return array($scope, $target);
}

# Raises or clears the emergency for one group or client. One row per scope/target,
# updated in place, so history does not accumulate and the unique key holds.
function uns_emerg_target_set($conn, $driver, $scope, $target, $active, $until = 0, $source = 'manual', $note = '')
{
    uns_emerg_ensure($conn, $driver);
    $target = (string)$target;
    $now    = time();

    $sel = $conn->prepare("SELECT id FROM emerg_targets WHERE scope = ? AND target = ?");
    $id  = 0;
    if($sel && $sel->execute(array($scope, $target)))
    {
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if($row){$id = (int)$row['id'];}
    }

    if($id > 0)
    {
        $upd = $conn->prepare("UPDATE emerg_targets SET active = ?, until = ?, source = ?, note = ?, updated = ? WHERE id = ?");
        return $upd ? $upd->execute(array($active ? 1 : 0, (int)$until, $source, $note, $now, $id)) : false;
    }
    $ins = $conn->prepare("INSERT INTO emerg_targets (scope, target, active, until, source, note, updated) VALUES (?, ?, ?, ?, ?, ?, ?)");
    return $ins ? $ins->execute(array($scope, $target, $active ? 1 : 0, (int)$until, $source, $note, $now)) : false;
}

# --- Alert routing ----------------------------------------------------------
#
# A route says "an alert whose <field> <op> <value> puts <scope>:<target> into
# emergency mode". Both the admin screen and the monitor read these definitions from
# here so the choices offered can never drift from the ones actually evaluated.

function uns_route_fields()
{
    return array(
        'event'       => 'CAP event',
        'category'    => 'CAP category',
        'severity'    => 'Severity',
        'urgency'     => 'Urgency',
        'certainty'   => 'Certainty',
        'area'        => 'Area description',
        'geocode'     => 'Geocode (SAME/FIPS/UGC)',
        'sender'      => 'Sender',
        'headline'    => 'Headline',
        'description' => 'Description',
    );
}

function uns_route_ops()
{
    return array(
        'contains' => 'contains',
        'equals'   => 'is exactly',
        'regex'    => 'matches regex',
        'any'      => 'anything (severity only)',
    );
}

function uns_severity_rank($severity)
{
    $order = array('Unknown' => 0, 'Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4);
    return isset($order[$severity]) ? $order[$severity] : 0;
}

# True if one route matches one parsed alert.
#
# Alert fields that CAP allows to repeat (category, area, geocode) are held as arrays
# by the parser and matched if ANY element matches - an alert covering three counties
# should reach a route naming any one of them.
function uns_route_matches($route, $alert)
{
    if(empty($route['enabled'])){return false;}

    $min = isset($route['min_severity']) ? $route['min_severity'] : 'Unknown';
    if(uns_severity_rank(isset($alert['severity']) ? $alert['severity'] : '') < uns_severity_rank($min))
    {
        return false;
    }

    $op = isset($route['op']) ? $route['op'] : 'contains';
    if($op === 'any'){return true;}

    $field  = isset($route['field']) ? $route['field'] : 'event';
    $needle = (string)(isset($route['value']) ? $route['value'] : '');
    if($needle === ''){return false;}

    $hay = isset($alert[$field]) ? $alert[$field] : '';
    $candidates = is_array($hay) ? $hay : array($hay);

    foreach($candidates as $candidate)
    {
        $candidate = (string)$candidate;
        if($candidate === ''){continue;}
        switch($op)
        {
            case 'equals':
                if(strcasecmp($candidate, $needle) === 0){return true;}
                break;
            case 'regex':
                # Delimiters are escaped rather than chosen by the author, so a rule
                # cannot smuggle in pattern modifiers.
                if(@preg_match('/'.str_replace('/', '\/', $needle).'/i', $candidate) === 1){return true;}
                break;
            case 'contains':
            default:
                if(stripos($candidate, $needle) !== false){return true;}
                break;
        }
    }
    return false;
}

# ----------------------------------------------------------------------------

# Reads the app version from the VERSION file, so the version only needs updating in one
# place (see .github/workflows/bump-version.yml).
#
# This walks up from this file rather than using a fixed dirname(__DIR__, 2): UNS is
# normally deployed by copying the *contents* of Server/www into a document root, which
# leaves the repo-root VERSION file at a different depth - or absent altogether. The old
# fixed offset only resolved when running directly from a checkout, so real installs
# showed "vunknown" in the admin footer.
#
# Callers should prefer $uns_ver from configs/vars.php, which the installer records and
# which does not depend on the deployed layout at all; this is the fallback.
function uns_version()
{
    $dir = __DIR__;
    for($i = 0; $i < 4; $i++)
    {
        $path = $dir.'/VERSION';
        if(is_readable($path))
        {
            $ver = trim((string)file_get_contents($path));
            if($ver !== ''){return $ver;}
        }
        $up = dirname($dir);
        if($up === $dir){break;}
        $dir = $up;
    }
    return 'unknown';
}

function gen_base_urls($dir)
{
    include "$dir/configs/vars.php";
    global $proto, $admin_url, $reg_url;
    if($SSL){$proto = "https://";}else{$proto = "http://";}

    $admin_url = $proto.$host;
    $reg_url = "http://".$host; # client-facing pages intentionally stay on http regardless of the admin SSL setting
    # original code guarded this with `$root != "" or $root != "/"`, which is always true (a string can't
    # equal both at once) - so root is unconditionally appended, same as here.
    $admin_url .= $root;
    $reg_url .= $root;
}

function check_install($dir)
{
    if(!include_once "$dir/configs/vars.php")
    {echo "No Var.php Config found."; return 0;}

    if(!include_once "$dir/configs/conn.php")
    { echo "No Conn.php Config found."; return 0;}

    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    if(!$conn){echo "Failed to connect: ".db_error($conn); return 0;}

    $stmt = $conn->query("SELECT uns_ver FROM settings ORDER BY id ASC");
    if($stmt)
    {$uns_ver = $stmt->fetch(PDO::FETCH_ASSOC);}
    else{return 0;}

    if(empty($uns_ver['uns_ver'])){echo "Database Not Installed Properly.<br />"; return 0;}
    return 1;
}

function blinky($id)
{
    include "configs/vars.php";
    include "configs/conn.php";
    if(!$led_blink){return 1;}

    $c_leds = array(
        1 => 1,
        2 => 2,
        3 => 4,
        4 => 8,
        5 => 16,
        6 => 32,
        "admin" => 64
    );

    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    if(!$conn){return 0;}
    $stmt = $conn->query("SELECT emerg FROM settings");
    $emerg = $stmt->fetch(PDO::FETCH_ASSOC);

    $dec = 255; #all LEDs off by default
    if($emerg['emerg']){$dec -= 128;} #set Emerg led in var

    blink($dec); #set initial

    if(!isset($c_leds[$id])){return 1;}
    $on = $dec - $c_leds[$id];
    blink($on);

    $off = $on + $c_leds[$id];
    blink($off);
    return 1;
}

function blink($on)
{
    include "configs/vars.php";
    if(empty($lpt_set_app)){return 1;}
    usleep(700);
    $lpt_write = system("$lpt_set_app $on;");
    usleep(700);
    if($lpt_write == '')
    {
        return 1;
    }else
    {
        return 0;
    }
}

function emerg_blink($toggle)
{
    include "configs/vars.php";
    if(empty($lpt_set_app)){return 1;}
    usleep(700);
    if($toggle)
    {
        $lpt_write = system("$lpt_set_app 127;");
    }else
    {
        $lpt_write = system("$lpt_set_app 255;");
    }
    return 1;
}
?>
