<?php
#    install.php, First-run installer: creates the UNS database (MySQL, SQL Server, or
#    SQLite), imports the matching schema, creates the built-in admin account, and
#    writes configs/vars.php and configs/conn.php.
#
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

require __DIR__.'/shared.php';

function uns_already_installed()
{
    if(!file_exists(__DIR__.'/configs/conn.php')){return false;}
    include __DIR__.'/configs/conn.php';
    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server ?? '', $username ?? '', $password ?? '', $db ?? '', $driver);
    if(!$conn){return false;}
    $stmt = $conn->query("SELECT uns_ver FROM settings ORDER BY id ASC");
    if(!$stmt){return false;}
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return !empty($row['uns_ver']);
}

# A SQL identifier (db name, username) - conservative whitelist, no way to safely
# parameterize identifiers in SQL so we validate instead.
function is_safe_identifier($value)
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) === 1;
}

# The MySQL/SQL Server user "host" part of user@host - allow hostnames/IPs and the % wildcard.
function is_safe_user_host($value)
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_.%-]{1,128}$/', $value) === 1;
}

function uns_is_windows()
{
    return PHP_OS_FAMILY === 'Windows';
}

# Short random hex token, used to make guessable defaults (the SQL account name, and a
# SQLite filename that had to stay in the web root) unguessable instead.
function uns_random_token($bytes = 4)
{
    return bin2hex(random_bytes($bytes));
}

# Strong random password for the UNS database account. No human ever types this one - it
# is written into configs/conn.php and used by PHP from then on - so it can be long and
# random at no usability cost. The alphabet deliberately leaves out quotes, backslashes
# and dollar signs so the value is safe to embed both in SQL (CREATE USER ... IDENTIFIED
# BY / CREATE LOGIN ... WITH PASSWORD) and in the generated PHP config file.
function uns_random_password($length = 24)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#%*+-=?@^_';
    $max = strlen($alphabet) - 1;
    $out = '';
    for($i = 0; $i < $length; $i++){$out .= $alphabet[random_int(0, $max)];}
    return $out;
}

# Best-effort "who is PHP actually running as", so the installer can print a permissions
# command that is correct for this box instead of guessing www-data.
function uns_web_user()
{
    # Linux/Apache: the real effective user, when the posix extension is available.
    if(function_exists('posix_geteuid') && function_exists('posix_getpwuid'))
    {
        $info = posix_getpwuid(posix_geteuid());
        if(!empty($info['name'])){return $info['name'];}
    }
    $env = getenv('APACHE_RUN_USER');
    if($env !== false && $env !== ''){return $env;}

    # Windows/IIS: FastCGI runs as the application pool identity, which surfaces in
    # USERNAME. get_current_user() would report the owner of this file instead, which is
    # usually not the identity that needs the permissions.
    if(uns_is_windows())
    {
        $u = getenv('USERNAME');
        if($u !== false && $u !== '')
        {
            $domain = getenv('USERDOMAIN');
            return ($domain !== false && $domain !== '') ? $domain."\\".$u : $u;
        }
        return 'IIS APPPOOL\\<your app pool>';
    }

    $user = get_current_user();
    return $user !== '' ? $user : 'the web server user';
}

# Permission commands are completely different on Linux/Apache vs Windows/IIS, so emit
# whichever suits the server actually running this installer. Paths are normalised to
# the separator that server's shell expects.
function uns_path_for_shell($path)
{
    $p = str_replace('\\', '/', $path);
    return uns_is_windows() ? str_replace('/', '\\', $p) : $p;
}

function uns_cmd_delete($path)
{
    $p = uns_path_for_shell($path);
    return uns_is_windows() ? "del \"".$p."\"" : "sudo rm '".$p."'";
}

function uns_cmd_make_writable($dir, $user)
{
    $p = uns_path_for_shell($dir);
    if(uns_is_windows()){return "icacls \"".$p."\" /grant \"".$user.":(OI)(CI)M\"";}
    return "sudo chown -R ".$user." '".$p."'\nsudo chmod 750 '".$p."'";
}

# Create a folder and hand it to the web server. Separate from uns_cmd_make_writable()
# because the folder may not exist yet - chown alone then fails with "No such file".
function uns_cmd_make_dir($dir, $user)
{
    $p = uns_path_for_shell($dir);
    if(uns_is_windows())
    {
        return "mkdir \"".$p."\"\nicacls \"".$p."\" /grant \"".$user.":(OI)(CI)M\"";
    }
    return "sudo mkdir -p '".$p."'\nsudo chown -R ".$user." '".$p."'\nsudo chmod 750 '".$p."'";
}

function uns_cmd_make_readonly($dir, $user)
{
    $p = uns_path_for_shell($dir);
    if(uns_is_windows()){return "icacls \"".$p."\" /inheritance:r /grant \"".$user.":(OI)(CI)RX\" /grant \"Administrators:(OI)(CI)F\"";}
    return "sudo chmod 550 '".$p."'";
}

function uns_cmd_restrict_file($file, $user)
{
    $p = uns_path_for_shell($file);
    if(uns_is_windows()){return "icacls \"".$p."\" /inheritance:r /grant \"".$user.":R\" /grant \"Administrators:F\"";}
    return "sudo chown ".$user." '".$p."'\nsudo chmod 640 '".$p."'";
}

# Renders a possibly multi-line command block as escaped <code> lines.
function uns_cmd_html($cmd)
{
    $out = '';
    foreach(explode("\n", $cmd) as $line)
    {
        $out .= "<br /><code>".htmlspecialchars($line, ENT_QUOTES)."</code>";
    }
    return $out;
}

# Returns '' when the installer can genuinely write both config files, otherwise a short
# explanation of what is in the way.
#
# Checking is_writable() on the folder alone is not enough: overwriting an existing file
# needs write permission on that FILE, and a folder the web server can add to (so creating
# the SQLite database succeeds) can still hold config files it cannot replace.
#
# On a fresh checkout neither file exists - only configs/*.sample.php is tracked - so this
# normally just checks the folder. The per-file check still matters when re-running the
# installer over an existing install.
function uns_config_write_problem()
{
    $dir = __DIR__.'/configs';
    if(!is_dir($dir)){return "the configs/ folder does not exist";}
    if(!is_writable($dir)){return "the configs/ folder is not writable";}
    foreach(array('vars.php', 'conn.php') as $name)
    {
        $path = $dir.'/'.$name;
        if(file_exists($path) && !is_writable($path)){return "configs/".$name." already exists and is not writable";}
    }
    return '';
}

# Shown whenever configs/ is not writable. The same advice applies whether we are
# writing vars.php/conn.php or creating a SQLite database file in there.
function uns_perm_hint($dir)
{
    $raw_user = uns_web_user();
    $raw_dir  = rtrim($dir, '/\\');
    $user = htmlspecialchars($raw_user, ENT_QUOTES);
    $shown = htmlspecialchars(uns_path_for_shell($raw_dir), ENT_QUOTES);
    return "PHP is running as <b>".$user."</b> and cannot write to <b>".$shown."</b>."
        ." Note that SQLite also creates -wal and -shm files next to the database, so the"
        ." <i>folder</i> has to be writable - making just the .sqlite file writable is not enough."
        ."<br />".(uns_is_windows() ? "In an elevated command prompt:" : "On a typical Linux server:")
        .uns_cmd_html(uns_cmd_make_writable($raw_dir, $raw_user));
}


# Where a SQLite database should live: the shared data folder, which is outside the
# document root wherever possible. uns_data_dir() and the deny rules it writes live in
# shared.php, because the running app needs the same folder for Smarty's compiled
# templates - see uns_smarty().
function uns_sqlite_dir($create = false)
{
    return uns_data_dir('', $create);
}

# Splits a schema file into individual statements. Neither mysqli's multi_query() nor
# PDO have a portable "run this whole .sql file" call, and our schema files never put a
# semicolon inside a string/value, so a plain split is safe here.
function db_run_schema_file($conn, $path)
{
    $sql = file_get_contents($path);
    if($sql === false){return false;}
    # Drop "--" comment lines before splitting. A semicolon inside a comment would
    # otherwise cut the following statement in half, and the halves fail silently -
    # which is exactly how a table went missing from a fresh install once.
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)), function($s){return $s !== '';});
    foreach($statements as $stmt)
    {
        if($conn->exec($stmt) === false){return false;}
    }
    return true;
}

if(uns_already_installed())
{
    die("UNS is already installed. Delete configs/conn.php (and re-create the database) if you really want to run the installer again.");
}

$installing = @filter_input(INPUT_GET, 'installing', FILTER_SANITIZE_SPECIAL_CHARS);
?>
<html>
    <head>
        <title>UNS Install Page</title>
        <link rel="stylesheet" href="configs/styles.css">
    </head>
<?php
if($installing)
{
    ?>
    <body class="main_body" align="center">
    <div align="center">
    <table border="1" width="75%" class="main_cell">
        <tr class="client_table_head"><th colspan="2">UNS Install Process</th></tr>
        <tr class="client_table_head"><th>Step</th><th>Outcome</th></tr>
<?php
    $db_driver = filter_input(INPUT_POST, 'db_driver', FILTER_SANITIZE_SPECIAL_CHARS);
    if(!in_array($db_driver, array('mysql', 'sqlsrv', 'sqlite'), true)){$db_driver = 'mysql';}

    $sql_host = filter_input(INPUT_POST, 'sql_host', FILTER_SANITIZE_SPECIAL_CHARS);
    $sql_root_usr = filter_input(INPUT_POST, 'sql_root_usr', FILTER_SANITIZE_SPECIAL_CHARS);
    $sql_root_pwd = (string)filter_input(INPUT_POST, 'sql_root_pwd', FILTER_UNSAFE_RAW);
    $uns_sql_usr = filter_input(INPUT_POST, 'uns_sql_usr', FILTER_SANITIZE_SPECIAL_CHARS);
    $uns_sql_pwd = (string)filter_input(INPUT_POST, 'uns_sql_pwd', FILTER_UNSAFE_RAW);
    $db_name = filter_input(INPUT_POST, 'db_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $hostname = filter_input(INPUT_POST, 'hostname', FILTER_SANITIZE_SPECIAL_CHARS);
    $hostname = rtrim(str_replace("\\", "/", (string)$hostname), "/")."/";
    $user_host = filter_input(INPUT_POST, 'sql_user_host', FILTER_SANITIZE_SPECIAL_CHARS);
    if($user_host === null || $user_host === false || $user_host === ""){$user_host = "localhost";}

    # Validate anything that can abort the install BEFORE creating a database, a schema or
    # a SQL account. This check used to sit further down, after the database and tables had
    # already been built, so a missing admin password left an orphaned (and randomly named,
    # therefore easy to overlook) .sqlite file behind on every failed attempt.
    # Confirm the config files can actually be written before creating a database, a schema
    # or a SQL account. Discovering this at the end left a working database and admin user
    # behind with no config to reach them - and, with randomised SQLite names, a fresh
    # orphaned .sqlite file on every retry.
    $cfg_problem = uns_config_write_problem();
    if($cfg_problem !== '')
    {
        $raw_cfg = __DIR__.'/configs';
        die("<tr><td colspan='2' class='Emerg'>Cannot write the UNS config files: <b>".htmlspecialchars($cfg_problem, ENT_QUOTES)."</b>."
            ."<br />PHP is running as <b>".htmlspecialchars(uns_web_user(), ENT_QUOTES)."</b>. Note that vars.php and conn.php"
            ." ship with UNS, so they already exist - being able to add files to configs/ is not enough, the web server also"
            ." needs to be able to replace those two files."
            .uns_cmd_html(uns_cmd_make_writable($raw_cfg, uns_web_user()))
            ."<br />Then submit again. <b>Nothing has been created</b>, so you can simply retry."
            ."</td></tr></table></div></body></html>");
    }

    $admin_user = (string)filter_input(INPUT_POST, 'uns_admin_usr', FILTER_SANITIZE_SPECIAL_CHARS);
    if($admin_user === ""){$admin_user = 'unsadmin';}
    if(!is_safe_identifier($admin_user))
    {
        die("<tr><td colspan='2' class='Emerg'>The admin username may only contain letters, numbers and underscores."
            ."<br />Nothing has been created, so you can simply retry.</td></tr></table></div></body></html>");
    }

    $admin_pwd = (string)filter_input(INPUT_POST, 'uns_admin_pwd', FILTER_UNSAFE_RAW);
    if($admin_pwd === "")
    {
        die("<tr><td colspan='2' class='Emerg'>An <b>Internal Admin Password</b> is required - that is the"
            ." account you log in to UNS with, and it is needed even when LDAP is enabled."
            ." Go back, fill it in, and submit again.<br />Nothing has been created, so you can simply retry."
            ."</td></tr></table></div></body></html>");
    }

    if($db_driver === 'sqlite')
    {
        # A single self-contained file - no server, no separate DB user. Kept outside the
        # document root where possible; see uns_sqlite_dir().
        $sqlite_name = filter_input(INPUT_POST, 'sqlite_name', FILTER_SANITIZE_SPECIAL_CHARS);
        if(!is_safe_identifier($sqlite_name)){$sqlite_name = 'uns';}
        $sqlite_dir = uns_sqlite_dir(true);
        # If we could not get out of the web root, the deny rules are the only thing between
        # the internet and every password hash in the install - and Apache ignores .htaccess
        # outright under AllowOverride None. Give the file an unguessable name so a direct
        # request for the obvious /configs/uns.sqlite fails even when those rules are inert.
        # Nothing needs to guess it: the real path is recorded in conn.php.
        if(uns_path_is_public($sqlite_dir)){$sqlite_name .= '-'.uns_random_token(8);}
        $db_name = $sqlite_dir.'/'.$sqlite_name.'.sqlite';
        $sql_host = '';
        $uns_sql_usr = '';
        $uns_sql_pwd = '';

        echo "<tr><td>Create UNS SQLite database.</td>";
        $conn = db_connect('', '', '', $db_name, 'sqlite');
        if($conn)
        {
            echo "<td class='Good'>Success<br /><font size='1'>".htmlspecialchars($db_name, ENT_QUOTES)."</font>";
            if(uns_path_is_public($sqlite_dir))
            {
                echo "<br /><font color='orange' size='1'>This is inside the web root, so the file name was given a"
                    ." random suffix to make it unguessable, and configs/.htaccess (Apache) plus configs/web.config (IIS)"
                    ." deny downloads. Be aware Apache ignores .htaccess entirely under <b>AllowOverride None</b>."
                    ." Moving the database outside the web root is the only fix that does not depend on server config.</font>";
            }
            echo "</td></tr>";
        }
        else{die("<td class='Emerg'>Could not create ".htmlspecialchars($db_name, ENT_QUOTES)."<br />".uns_perm_hint($sqlite_dir)."</td></tr></table></div></body></html>");}

        echo "<tr><td>Create UNS tables.</td>";
        if(db_run_schema_file($conn, __DIR__.'/setup_sqlite.sql')){echo "<td class='Good'>Success</td></tr>";}
        else{echo "<td class='Emerg'>".htmlspecialchars(db_error($conn), ENT_QUOTES)."</td></tr>";}
    }
    else
    {
        if(!is_safe_identifier($db_name) || !is_safe_identifier($uns_sql_usr) || !is_safe_user_host($user_host))
        {
            die("<tr><td colspan='2' class='Emerg'>Database name / UNS SQL username / user host may only contain letters, numbers, and underscores.</td></tr></table></div></body></html>");
        }
        # MySQL caps account names at 32 characters. A longer name passes the identifier
        # check above and then fails at CREATE USER with an opaque error, so catch it here.
        if($db_driver === 'mysql' && strlen($uns_sql_usr) > 32)
        {
            die("<tr><td colspan='2' class='Emerg'>MySQL limits usernames to 32 characters (this one is ".strlen($uns_sql_usr).").</td></tr></table></div></body></html>");
        }
        if($uns_sql_pwd === "" || $sql_root_pwd === "")
        {
            die("<tr><td colspan='2' class='Emerg'>Both the SQL admin password and the new UNS SQL user password are required.</td></tr></table></div></body></html>");
        }

        # Connect as the admin/root account, with no default database selected yet.
        $root_conn = db_connect($sql_host, $sql_root_usr, $sql_root_pwd, '', $db_driver);
        if(!$root_conn)
        {
            die("<tr><td colspan='2' class='Emerg'>Could not connect as the SQL admin user: ".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr></table></div></body></html>");
        }

        if($db_driver === 'sqlsrv')
        {
            echo "<tr><td>Create UNS database.</td>";
            if($root_conn->exec("IF DB_ID('$db_name') IS NULL CREATE DATABASE $db_name") !== false){echo "<td class='Good'>Success</td></tr>";}
            else{echo "<td class='Emerg'>".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr>";}

            echo "<tr><td>Create UNS SQL login and user.</td>";
            $ok = $root_conn->exec("IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = '$uns_sql_usr') CREATE LOGIN [$uns_sql_usr] WITH PASSWORD = '".str_replace("'", "''", $uns_sql_pwd)."'") !== false;
            $ok = $ok && $root_conn->exec("USE $db_name; IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = '$uns_sql_usr') CREATE USER [$uns_sql_usr] FOR LOGIN [$uns_sql_usr]") !== false;
            if($ok){echo "<td class='Good'>Success</td></tr>";}
            else{echo "<td class='Emerg'>".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr>";}

            echo "<tr><td>Grant privileges on the UNS database to the UNS SQL user.</td>";
            if($root_conn->exec("USE $db_name; ALTER ROLE db_owner ADD MEMBER [$uns_sql_usr]") !== false){echo "<td class='Good'>Success</td></tr>";}
            else{echo "<td class='Emerg'>".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr>";}
        }
        else # mysql
        {
            echo "<tr><td>Create UNS database.</td>";
            if($root_conn->exec("CREATE DATABASE IF NOT EXISTS $db_name DEFAULT CHARACTER SET utf8mb4") !== false){echo "<td class='Good'>Success</td></tr>";}
            else{echo "<td class='Emerg'>".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr>";}

            echo "<tr><td>Create UNS SQL user.</td>";
            $quoted_pwd = $root_conn->quote($uns_sql_pwd);
            if($root_conn->exec("CREATE USER IF NOT EXISTS '$uns_sql_usr'@'$user_host' IDENTIFIED BY $quoted_pwd") !== false){echo "<td class='Good'>Success</td></tr>";}
            else{echo "<td class='Emerg'>".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr>";}

            echo "<tr><td>Grant privileges on the UNS database to the UNS SQL user.</td>";
            if($root_conn->exec("GRANT ALL PRIVILEGES ON $db_name.* TO '$uns_sql_usr'@'$user_host'") !== false){echo "<td class='Good'>Success</td></tr>";}
            else{echo "<td class='Emerg'>".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr>";}
            $root_conn->exec("FLUSH PRIVILEGES");
        }

        echo "<tr><td>Create UNS tables.</td>";
        $root_conn->exec("USE $db_name");
        if(db_run_schema_file($root_conn, __DIR__.'/setup_'.$db_driver.'.sql')){echo "<td class='Good'>Success</td></tr>";}
        else{echo "<td class='Emerg'>".htmlspecialchars(db_error($root_conn), ENT_QUOTES)."</td></tr>";}
        $root_conn = null;

        echo "<tr><td>Reconnect as the UNS SQL user.</td>";
        $conn = db_connect($sql_host, $uns_sql_usr, $uns_sql_pwd, $db_name, $db_driver);
        if(!$conn)
        {
            die("<td class='Emerg'>".htmlspecialchars(db_error($conn), ENT_QUOTES)."</td></tr></table></div></body></html>");
        }
        echo "<td class='Good'>Connected</td></tr>";
    }

    # The schema files bake in whatever uns_ver was current when they were last
    # synced by the release workflow, which can lag between releases (they're
    # also run directly, without install.php, for manual installs). Overwrite it
    # here so anyone using install.php always gets the version they actually installed.
    #
    # Only when we genuinely know it, though: if VERSION is not reachable (a deploy of just
    # the Server/www contents), uns_version() returns 'unknown', and writing that over the
    # correct value seeded by the schema is how the admin footer ended up showing
    # "vunknown". In that case keep the schema's value and read it back for vars.php.
    $uns_ver = uns_version();
    if($uns_ver !== 'unknown' && $uns_ver !== '')
    {
        $conn->exec("UPDATE settings SET uns_ver = ".$conn->quote($uns_ver));
    }
    else
    {
        $vstmt = $conn->query("SELECT uns_ver FROM settings");
        $vrow  = $vstmt ? $vstmt->fetch(PDO::FETCH_ASSOC) : false;
        if($vrow && !empty($vrow['uns_ver'])){$uns_ver = $vrow['uns_ver'];}
    }

    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $seed = '';
    for($p = 0; $p < 32; $p++){$seed .= $characters[random_int(0, strlen($characters)-1)];}
    # $admin_pwd was read and validated up front, before anything was created.
    # New installs get a modern hash straight away; the $seed above is kept around only
    # because the admin options page still displays/rewrites it for older installs.
    $password_hash = password_hash($admin_pwd, PASSWORD_DEFAULT);

    echo "<tr><td>Create UNS internal admin user.</td>";
    $stmt = $conn->prepare("INSERT INTO internal_users (username, password, disabled, failed) VALUES (?, ?, 0, 0)");
    if($stmt->execute([$admin_user, $password_hash]))
    {
        $stmt2 = $conn->prepare("INSERT INTO allowed_users (username, domain, edit_urls, edit_emerg, edit_users, edit_options, c_messages, rss_feeds) VALUES (?, '', 1, 1, 1, 1, 1, 1)");
        if($stmt2->execute([$admin_user])){echo "<td class='Good'>Success<br /><font size='1'>".htmlspecialchars($admin_user, ENT_QUOTES)."</font></td></tr>";}
        else{echo "<td class='Emerg'>".htmlspecialchars(db_error($stmt2), ENT_QUOTES)."</td></tr>";}
    }else
    {
        echo "<td class='Emerg'>".htmlspecialchars(db_error($stmt), ENT_QUOTES)."</td></tr>";
    }

    $uns_name = (string)filter_input(INPUT_POST, 'uns_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $root = (string)filter_input(INPUT_POST, 'root', FILTER_SANITIZE_SPECIAL_CHARS);
    $root = trim(str_replace("\\", "/", $root), "/");
    if($root !== ""){$root .= "/";}
    $timeout = (int)filter_input(INPUT_POST, 'timeout', FILTER_VALIDATE_INT, ['options' => ['default' => 3600]]);
    $tz = (string)filter_input(INPUT_POST, 'tz', FILTER_SANITIZE_SPECIAL_CHARS);
    if($tz === "" || !in_array($tz, timezone_identifiers_list(), true))
    {
        # Silently swapping in UTC meant a mistyped zone (or an abbreviation like EDT, which
        # is not a PHP identifier) produced wrong timestamps with no clue why. Say so.
        if($tz !== "")
        {
            echo "<tr><td>Time zone.</td><td class='Emerg'>'".htmlspecialchars($tz, ENT_QUOTES)."' is not a PHP time zone"
                ." identifier - abbreviations such as EDT are not accepted. Falling back to <b>UTC</b>."
                ." To fix, set \$TZ in configs/vars.php to a full identifier like 'America/New_York'.</td></tr>";
        }
        $tz = "UTC";
    }
    $ssl = filter_input(INPUT_POST, 'ssl', FILTER_VALIDATE_INT) ? 1 : 0;
    $ldap = filter_input(INPUT_POST, 'ldap', FILTER_VALIDATE_INT) ? 1 : 0;
    $ldap_domain = (string)filter_input(INPUT_POST, 'ldap_domain', FILTER_SANITIZE_SPECIAL_CHARS);
    $ldap_port = (int)filter_input(INPUT_POST, 'ldap_port', FILTER_VALIDATE_INT, ['options' => ['default' => 3268]]);
    $page_timeout = (int)filter_input(INPUT_POST, 'page_timeout', FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
    $refresh = (int)filter_input(INPUT_POST, 'refresh', FILTER_VALIDATE_INT, ['options' => ['default' => 30]]);
    $max_arch = (int)filter_input(INPUT_POST, 'max_arch', FILTER_VALIDATE_INT, ['options' => ['default' => 10]]);
    $max_conns = (int)filter_input(INPUT_POST, 'max_conns', FILTER_VALIDATE_INT, ['options' => ['default' => 10]]);

    $vars_file = "<?php\n"
        ."\$name_title     = ".var_export($uns_name !== "" ? $uns_name : "URL Notification System", true)."; # Name of your Install, Will be displayed on all pages\n"
        ."\$host           = ".var_export($hostname, true)."; # The HTTP server the clients will connect to.\n"
        ."\$root           = ".var_export($root, true)."; # Folder UNS lives in\n"
        ."\$timeout        = ($timeout); # Cookie Time out\n"
        ."\$SSL            = $ssl; # Cookie SSL only?\n"
        ."\$domain         = ".var_export($ldap_domain, true)."; # LDAP Domain to connect to for user authentication\n"
        ."\$port           = $ldap_port; # LDAP Port\n"
        ."\$TZ             = ".var_export($tz, true)."; # Local Time Zone\n"
        ."\$page_timeout   = $page_timeout; # Refresh time for page to forward in seconds.\n"
        ."\$refresh        = $refresh; # Time for client pages to refresh.\n"
        ."\$seed           = ".var_export($seed, true)."; # Only used for internal user logins, to hash the password and store that.\n"
        ."\$admin_user     = ".var_export($admin_user, true)."; # The built-in admin account name. Must match the row in internal_users/allowed_users.\n"
        ."\$uns_ver        = ".var_export($uns_ver, true)."; # Version recorded at install time - shown in the admin footer, independent of deployment layout.\n"
        ."\$LDAP           = $ldap; # If this flag is set, internal users will be overridden, except for the Admin.\n"
        ."\$max_archives   = $max_arch; # The Maximum number of Archived URL lists that will be kept before the oldest is killed\n"
        ."\$max_conn_hist  = $max_conns; # The Maximum number of Connection histories that will be kept per client.\n"
        ."\$lpt_set_app    = ''; # Bin for the LPT LED blinker\n"
        ."\$lpt_read_app   = ''; # Bin for LPT value reader\n"
        ."\$led_blink      = 0; # Variable to turn on the LPT LED blinking\n"
        ."\$mysql_dump_bin = 'mysqldump'; # Name or location of the mysqldump binary, used by the admin backup/restore feature\n"
        ."
";

    $conn_file = "<?php\n"
        ."\$driver = ".var_export($db_driver, true).";\n"
        ."\$server = ".var_export($sql_host, true).";\n"
        ."\$username = ".var_export($uns_sql_usr, true).";\n"
        ."\$password = ".var_export($uns_sql_pwd, true).";\n"
        ."\$db = ".var_export($db_name, true).";\n";

    echo "<tr><td>Write configs/vars.php.</td>";
    if(file_put_contents(__DIR__.'/configs/vars.php', $vars_file) !== false){echo "<td class='Good'>Success</td></tr>";}
    else{echo "<td class='Emerg'>Failed to write configs/vars.php - check folder permissions.</td></tr>";}

    echo "<tr><td>Write configs/conn.php.</td>";
    if(file_put_contents(__DIR__.'/configs/conn.php', $conn_file) !== false){echo "<td class='Good'>Success</td></tr>";}
    else{echo "<td class='Emerg'>Failed to write configs/conn.php - check folder permissions.</td></tr>";}

    # Create the template folders here, as the web server user, rather than leaving Smarty
    # to make them on the first page load. Done lazily they can end up owned by whoever
    # happened to trigger it, and the failure then surfaces as an uncaught Smarty error on
    # a page rather than as something the installer could have told you about.
    echo "<tr><td>Create template cache folders.</td>";
    $tpl_dirs = array(uns_data_dir('templates_c', true), uns_data_dir('templates_cache', true));
    $tpl_bad = array();
    foreach($tpl_dirs as $dir)
    {
        if(!is_dir($dir) || !is_writable($dir)){$tpl_bad[] = $dir;}
    }
    if(!$tpl_bad)
    {
        echo "<td class='Good'>Success<br /><font size='1'>".htmlspecialchars(uns_path_for_shell($tpl_dirs[0]), ENT_QUOTES)."</font></td></tr>";
    }
    else
    {
        # List every folder that needs attention, not just the first. Reporting one at a
        # time means fixing it, reloading, and then being told about the next one.
        echo "<td class='Emerg'>Could not create or write ".count($tpl_bad)." template folder"
            .(count($tpl_bad) === 1 ? "" : "s").". Smarty compiles templates there on every page,"
            ." so they must stay writable by <b>".htmlspecialchars(uns_web_user(), ENT_QUOTES)."</b>.";
        foreach($tpl_bad as $dir)
        {
            echo uns_cmd_html(uns_cmd_make_dir($dir, uns_web_user()));
        }
        echo "</td></tr>";
    }

    echo "</table>";

    # Post-install hardening. The installer needed write access to configs/ to create
    # vars.php and conn.php, but the running app does not - so that access should be
    # taken away again. SQLite is the exception: it needs to keep writing its database
    # (and the -wal/-shm files beside it) for as long as UNS is in use.
    $raw_user  = uns_web_user();
    $raw_cfg   = __DIR__.'/configs';
    $web_user  = htmlspecialchars($raw_user, ENT_QUOTES);
    $cfg_dir   = htmlspecialchars(uns_path_for_shell($raw_cfg), ENT_QUOTES);
    $db_in_cfg = ($db_driver === 'sqlite' && rtrim(str_replace('\\', '/', $sqlite_dir), '/') === rtrim(str_replace('\\', '/', $raw_cfg), '/'));

    echo "<h3>Recommended hardening</h3>";
    echo "<p><font size='1'>Commands below are for ".(uns_is_windows() ? "Windows/IIS (run in an elevated command prompt)" : "Linux/Apache")
        .", the platform this installer is running on.</font></p><ol>";

    echo "<li><b>Delete this installer.</b> It cannot re-run while conn.php exists, but it still"
        ." reports your PHP version, loaded extensions and full paths to anyone who visits it."
        .uns_cmd_html(uns_cmd_delete(__FILE__))."</li>";

    if($db_driver === 'sqlite')
    {
        $sq_dir = htmlspecialchars(uns_path_for_shell($sqlite_dir), ENT_QUOTES);
        echo "<li><b>Keep the database folder writable.</b> SQLite writes -wal and -shm files"
            ." next to the database, so <b>".$sq_dir."</b> must stay writable by <b>".$web_user."</b>"
            ." - do not lock this one down."
            .uns_cmd_html(uns_cmd_make_writable($sqlite_dir, $raw_user))."</li>";

        if($db_in_cfg)
        {
            echo "<li><b>Move the database out of the web root.</b> It currently sits inside"
                ." configs/, so it is only protected by the deny rules in configs/.htaccess"
                ." (Apache) and configs/web.config (IIS) - and .htaccess is ignored entirely"
                ." under <code>AllowOverride None</code>. Moving the database somewhere the web"
                ." server does not serve removes that dependency: move the .sqlite file, then"
                ." update <code>\$db</code> in configs/conn.php to the new path."
                ."<br />Because the database lives here, configs/ must stay writable, so the"
                ." read-only step below does not apply until you move it.</li>";
        }
        else
        {
            echo "<li><b>Restrict configs/ to the web server.</b> It has to stay writable - saving the"
                ." UNS Options page rewrites configs/vars.php - but nothing other than the web server"
                ." needs any access to it."
                .uns_cmd_html(uns_cmd_make_writable($raw_cfg, $raw_user))."</li>";
        }
    }
    else
    {
        echo "<li><b>Restrict configs/ to the web server.</b> It has to stay writable - saving the"
            ." UNS Options page rewrites configs/vars.php - but nothing other than the web server"
            ." needs any access to it."
            .uns_cmd_html(uns_cmd_make_writable($raw_cfg, $raw_user))."</li>";
    }

    echo "<li><b>Restrict the credentials file.</b> configs/conn.php holds your database login"
        ." in plain text; it should be readable by the web server and nobody else."
        .uns_cmd_html(uns_cmd_restrict_file($raw_cfg.'/conn.php', $raw_user))."</li>";

    echo "<li><b>Keep the template cache writable.</b> Smarty compiles templates into"
        ." <b>".htmlspecialchars(uns_path_for_shell(uns_data_dir('templates_c')), ENT_QUOTES)."</b>"
        ." on every page, so it must stay writable by <b>".$web_user."</b> - locking it down"
        ." produces a Smarty error on the next page load."
        .uns_cmd_html(uns_cmd_make_writable(uns_data_dir('templates_c'), $raw_user))."</li>";

    echo "<li><b>Retire the built-in admin.</b> Once you have added your own users, disable the"
        ." <b>".htmlspecialchars($admin_user, ENT_QUOTES)."</b> account from the admin panel's options page.</li>";

    echo "</ol>";

    echo "<p>Install complete. Log in as <b>".htmlspecialchars($admin_user, ENT_QUOTES)."</b> with the password from the form."
        ." <a href='admin/index.php'>Go to the Admin Panel</a>.</p></div></body></html>";
    die();
}
?>
<body class="main_body" align="center">
<div align="center">
    <script type="text/javascript">
        function endisable() {
            document.forms['UNS_Install'].elements['ldap_domain'].disabled = !document.forms['UNS_Install'].elements['ldap'].checked;
            document.forms['UNS_Install'].elements['ldap_port'].disabled = !document.forms['UNS_Install'].elements['ldap'].checked;
        }
        function updateDriver() {
            var driver = document.forms['UNS_Install'].elements['db_driver'].value;
            var isSqlite = (driver === 'sqlite');
            var serverFields = document.getElementsByClassName('sql-server-field');
            for (var i = 0; i < serverFields.length; i++) {
                serverFields[i].style.display = isSqlite ? 'none' : '';
            }
            document.getElementById('sqlite-field').style.display = isSqlite ? '' : 'none';
        }
    </script>
    <form name="UNS_Install" action="?installing=1" method="post">
        <table border="1" width="75%" class="main_cell">
            <tr class="client_table_head"><th colspan="2">URL Notification System Installer</th></tr>
            <tr class="client_table_head"><th colspan="2">Checking prerequisites</th></tr>
            <tr class="pre">
                <td width="50%">PHP Version &gt;= 7.4?</td>
                <td><?php
                    if(PHP_VERSION_ID >= 80000){echo "<font color='limegreen'>GOOD! {".PHP_VERSION."}</font>";}
                    elseif(PHP_VERSION_ID >= 70400){echo "<font color='orange'>OK {".PHP_VERSION."} - supported, but 7.4 is past end-of-life upstream; 8.x is recommended.</font>";}
                    else{echo "<font color='red'>PHP version is too old.<br />".PHP_VERSION."</font>";}
                ?></td>
            </tr>
            <tr class="pre">
                <td>PDO extension?</td>
                <td><?php echo extension_loaded("pdo") ? "<font color='limegreen'>GOOD!</font>" : "<font color='red'>pdo extension is not loaded.</font>"; ?></td>
            </tr>
            <tr class="pre">
                <td>configs/ writable, including vars.php and conn.php?</td>
                <td><?php
                    # Needed for every install: the installer rewrites configs/vars.php and
                    # configs/conn.php. Both ship with UNS, so they already exist - a folder
                    # the web server can add to may still hold files it cannot replace.
                    $cfg_dir = __DIR__.'/configs';
                    $cfg_problem = uns_config_write_problem();
                    if($cfg_problem === ''){echo "<font color='limegreen'>GOOD! {".htmlspecialchars(uns_web_user(), ENT_QUOTES)."}</font>";}
                    else
                    {
                        echo "<font color='red'>".htmlspecialchars($cfg_problem, ENT_QUOTES)."."
                            ." PHP runs as <b>".htmlspecialchars(uns_web_user(), ENT_QUOTES)."</b>;"
                            ." it must be able to replace those files, not just add new ones."
                            .uns_cmd_html(uns_cmd_make_writable($cfg_dir, uns_web_user()))."</font>";
                    }
                ?></td>
            </tr>
            <tr class="pre">
                <td>Template folders writable?</td>
                <td><?php
                    # Checked here, before anything is configured, rather than only during the
                    # install. Smarty needs these on every page, and finding out afterwards
                    # means the form has already been filled in and a database created.
                    #
                    # This creates them as a side effect, which is exactly what is wanted: on a
                    # normal install they simply exist by the time the form is submitted.
                    $pre_tpl = array(uns_data_dir('templates_c', true), uns_data_dir('templates_cache', true));
                    $pre_bad = array();
                    foreach($pre_tpl as $dir)
                    {
                        if(!is_dir($dir) || !is_writable($dir)){$pre_bad[] = $dir;}
                    }
                    if(!$pre_bad)
                    {
                        echo "<font color='limegreen'>GOOD!</font>"
                            ."<br /><font size='1'>".htmlspecialchars(uns_path_for_shell(dirname($pre_tpl[0])), ENT_QUOTES)."</font>";
                    }
                    else
                    {
                        echo "<font color='red'>Cannot create or write ".count($pre_bad)." template folder"
                            .(count($pre_bad) === 1 ? "" : "s").". Smarty compiles templates there on every"
                            ." page, so fix this before installing:";
                        foreach($pre_bad as $dir){echo uns_cmd_html(uns_cmd_make_dir($dir, uns_web_user()));}
                        echo "</font>";
                    }
                ?></td>
            </tr>
            <tr class="pre">
                <td>SQLite database safe from web download?</td>
                <td><?php
                    # A SQLite database under configs/ sits inside the web root, so without a
                    # server rule it can simply be downloaded - which would hand over every
                    # password hash and session hash. We ship configs/.htaccess for Apache;
                    # nginx and IIS ignore it and need an equivalent rule set by hand.
                    $server_sw = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
                    $is_apache = stripos($server_sw, 'apache') !== false;
                    $is_iis    = stripos($server_sw, 'iis') !== false;
                    $guard     = $is_iis ? 'configs/web.config' : 'configs/.htaccess';
                    $has_guard = file_exists(__DIR__.'/'.$guard);

                    if(($is_apache || $is_iis) && $has_guard)
                    {
                        echo "<font color='limegreen'>GOOD! ".$guard." is in place.</font><br /><font size='1'>";
                        echo $is_iis
                            ? "Blocks .sqlite downloads via request filtering."
                            : "Only takes effect if this vhost permits overrides - <code>AllowOverride None</code> makes Apache ignore it silently.";
                        echo " UNS puts the database outside the web root by default, which does not depend on this.</font>";
                    }
                    elseif($is_apache || $is_iis)
                    {
                        echo "<font color='red'>".$guard." is missing - a SQLite database placed in configs/ could be downloaded over the web. Restore it from the UNS release before continuing.</font>";
                    }
                    else
                    {
                        echo "<font color='orange'>This server (".htmlspecialchars($server_sw !== '' ? $server_sw : 'unknown', ENT_QUOTES).") reads neither .htaccess nor web.config."
                            ." If you pick SQLite, add a rule denying web access to *.sqlite under configs/, or the database can be downloaded."
                            ." Safest option is to put the database outside the web root entirely.</font>";
                    }
                ?></td>
            </tr>
            <tr class="pre">
                <td>XML Functions?</td>
                <td><?php echo function_exists("xml_parser_create") ? "<font color='limegreen'>GOOD!</font>" : "<font color='red'>XML functions are not available, RSS Feeds will not work.</font>"; ?></td>
            </tr>
            <tr class="pre">
                <td>LDAP Functions?</td>
                <td><?php echo function_exists("ldap_connect") ? "<font color='limegreen'>GOOD!</font>" : "<font color='orange'>LDAP functions not found, Active Directory login will not work.</font>"; ?></td>
            </tr>
            <tr class="client_table_head"><th colspan="2">Choose your database</th></tr>
            <tr class="client_table_body">
                <td>Database Type</td>
                <td>
                    <select name="db_driver" onchange="updateDriver()">
                        <option value="mysql">MySQL / MariaDB</option>
                        <option value="sqlsrv">Microsoft SQL Server</option>
                        <option value="sqlite">SQLite (self-contained, no server needed)</option>
                    </select>
                </td>
            </tr>
            <tr class="client_table_body sql-server-field"><td>SQL Host</td><td><input type="text" name="sql_host" style="width:50%" value="localhost"/></td></tr>
            <tr class="client_table_body sql-server-field"><td>SQL Admin User</td><td><input type="text" name="sql_root_usr" style="width:50%" value="root"/></td></tr>
            <tr class="client_table_body sql-server-field"><td>SQL Admin Password</td><td><input type="password" name="sql_root_pwd" style="width:50%" value=""/></td></tr>
            <tr class="client_table_body sql-server-field"><td>UNS SQL Username
                <font size="1">(randomised so the account name is not guessable - edit if you prefer)</font></td>
                <td><input type="text" name="uns_sql_usr" style="width:50%" value="uns_user_<?php echo uns_random_token(); ?>"/></td></tr>
            <tr class="client_table_body sql-server-field"><td>UNS SQL Password
                <br /><font size="1"><b style="color:#8B0000">Generated for you - shown in clear text so you can record it.</b>
                UNS saves it into configs/conn.php and uses it from there, so you will never have to type it again.
                Keep a copy only if you want to manage this database account yourself later.
                Replace it with your own if you prefer.</font></td>
                <td><input type="text" name="uns_sql_pwd" style="width:50%" value="<?php echo htmlspecialchars(uns_random_password(), ENT_QUOTES); ?>"/></td></tr>
            <tr class="client_table_body sql-server-field"><td>Database Name</td><td><input type="text" name="db_name" style="width:50%" value="uns"/></td></tr>
            <tr class="client_table_body sql-server-field"><td>UNS SQL User's allowed host <font size="1">(usually "localhost")</font></td><td><input type="text" name="sql_user_host" style="width:50%" value="localhost"/></td></tr>
            <tr class="client_table_body" id="sqlite-field" style="display:none"><td>SQLite file name
                <?php
                    $preview_dir = uns_sqlite_dir();
                    echo "<font size='1'>(saved in ".htmlspecialchars($preview_dir, ENT_QUOTES).")</font>";
                    if(uns_path_is_public($preview_dir))
                    {
                        echo "<br /><font size='1' color='orange'>This folder is inside the web root."
                            ." UNS could not find a writable folder outside it, so the database will rely on"
                            ." configs/.htaccess (Apache only) to stay private.</font>";
                    }
                ?>
            </td><td><input type="text" name="sqlite_name" style="width:50%" value="uns"/></td></tr>
            <tr class="client_table_head"><th colspan="2">Set your variables</th></tr>
            <tr class="client_table_body"><td>Instance Name</td><td><input type="text" name="uns_name" style="width:50%" value="URL Notification System"/></td></tr>
            <tr class="client_table_body"><td>Hostname</td><td><input type="text" name="hostname" style="width:50%" value="your.uns.server"/></td></tr>
            <tr class="client_table_body"><td>HTTP root for UNS</td><td><input type="text" name="root" style="width:50%" value="uns/"/></td></tr>
            <tr class="client_table_body"><td>Session Timeout <font size="1">(seconds)</font></td><td><input type="text" name="timeout" style="width:50%" value="3600"/></td></tr>
            <tr class="client_table_body"><td>SSL Admin Folder?</td><td><input type="checkbox" name="ssl" value="1"/></td></tr>
            <tr class="client_table_body"><td>Timezone <font size="1">(PHP timezone identifier, eg. America/New_York)</font></td><td><input type="text" name="tz" style="width:50%" value="UTC"/></td></tr>
            <tr class="client_table_body"><td>Use LDAP?</td><td><input type="checkbox" name="ldap" value="1" onchange="endisable()"/></td></tr>
            <tr class="client_table_body"><td>LDAP Domain</td><td><input type="text" name="ldap_domain" style="width:50%" value="example.local" disabled/></td></tr>
            <tr class="client_table_body"><td>LDAP Port</td><td><input type="text" name="ldap_port" style="width:50%" value="3268" disabled/></td></tr>
            <tr class="client_table_body"><td>Redirect Page Timeout <font size="1">(0 = instant redirect)</font></td><td><input type="text" name="page_timeout" style="width:50%" value="0"/></td></tr>
            <tr class="client_table_body"><td>Default URL Refresh time</td><td><input type="text" name="refresh" style="width:50%" value="30"/></td></tr>
            <tr class="client_table_body"><td>Internal Admin Username
                <br /><font size="1">The account you log in to UNS with. Randomised so it is not a guessable target
                like "admin" - change it to whatever you prefer.</font></td>
                <td><input type="text" name="uns_admin_usr" style="width:50%" value="unsadmin_<?php echo uns_random_token(3); ?>" required/></td></tr>
            <tr class="client_table_body"><td>Internal Admin Password
                <br /><font size="1"><b style="color:#8B0000">Write these two down before you submit - you log in with them.</b>
                Generated for you and shown in clear text; replace with something memorable if you prefer.
                Needed even if LDAP is enabled. You can change it later from the admin panel.</font></td>
                <td><input type="text" name="uns_admin_pwd" style="width:50%" value="<?php echo htmlspecialchars(uns_random_password(16), ENT_QUOTES); ?>" required/></td></tr>
            <tr class="client_table_body"><td>Max Number of Archived links per Client</td><td><input type="text" name="max_arch" style="width:50%" value="10"/></td></tr>
            <tr class="client_table_body"><td>Max Number of Connection History per Client</td><td><input type="text" name="max_conns" style="width:50%" value="10"/></td></tr>
            <tr class="client_table_tail"><td align="center" colspan="2"><input type="submit" value="Submit" /></td></tr>
        </table>
    </form>
</div>
</body>
</html>
