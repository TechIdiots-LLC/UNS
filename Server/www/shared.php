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
# ----------------------------------------------------------------------------

# Reads the app version from the repo-root VERSION file, so the version only
# needs updating in one place (see .github/workflows/bump-version.yml). Falls
# back to 'unknown' if the file isn't there, eg. if only Server/www was
# deployed on its own without the rest of the repo alongside it.
function uns_version()
{
    $path = dirname(__DIR__, 2).'/VERSION';
    if(!is_readable($path)){return 'unknown';}
    return trim(file_get_contents($path));
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
