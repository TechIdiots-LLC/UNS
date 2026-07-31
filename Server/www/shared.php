<?php
#    shared.php, Some shared functions for both the Client page and the admin page
#    Copyright (C) 2010  Phillip Ferland / Random Intervals
#    Copyright (C) 2026  Andrew Calcutt / TechIdiots LLC
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation, either version 3 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program.  If not, see <http://www.gnu.org/licenses/>.

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
    foreach(array('compiled templates' => $compile_dir, 'template cache' => $cache_dir) as $what => $dir)
    {
        if(is_dir($dir) && is_writable($dir)){continue;}
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'the web server user')
            : 'the web server user';
        $shown = htmlspecialchars($dir, ENT_QUOTES);
        die("<h3>UNS cannot write its ".$what."</h3>"
            ."<p>PHP is running as <b>".htmlspecialchars($user, ENT_QUOTES)."</b> and needs to write to"
            ." <b>".$shown."</b>"
            .(is_dir($dir) ? ", which exists but is not writable." : ", which could not be created.")
            ."</p><p>On a typical Linux server:</p>"
            ."<pre>sudo mkdir -p '".$shown."'\n"
            ."sudo chown -R ".htmlspecialchars($user, ENT_QUOTES)." '".$shown."'\n"
            ."sudo chmod 750 '".$shown."'</pre>");
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
