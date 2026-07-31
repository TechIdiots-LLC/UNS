<?php
#    install.php, First-run installer: creates the UNS database (MySQL, SQL Server, or
#    SQLite), imports the matching schema, creates the built-in admin account, and
#    writes configs/vars.php and configs/conn.php.
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

# Splits a schema file into individual statements. Neither mysqli's multi_query() nor
# PDO have a portable "run this whole .sql file" call, and our schema files never put a
# semicolon inside a string/value, so a plain split is safe here.
function db_run_schema_file($conn, $path)
{
    $sql = file_get_contents($path);
    if($sql === false){return false;}
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

    if($db_driver === 'sqlite')
    {
        # A single self-contained file under configs/ - no server, no separate DB user.
        $sqlite_name = filter_input(INPUT_POST, 'sqlite_name', FILTER_SANITIZE_SPECIAL_CHARS);
        if(!is_safe_identifier($sqlite_name)){$sqlite_name = 'uns';}
        $db_name = __DIR__.'/configs/'.$sqlite_name.'.sqlite';
        $sql_host = '';
        $uns_sql_usr = '';
        $uns_sql_pwd = '';

        echo "<tr><td>Create UNS SQLite database.</td>";
        $conn = db_connect('', '', '', $db_name, 'sqlite');
        if($conn){echo "<td class='Good'>Success</td></tr>";}
        else{die("<td class='Emerg'>Could not create ".htmlspecialchars($db_name, ENT_QUOTES)."</td></tr></table></div></body></html>");}

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
    $conn->exec("UPDATE settings SET uns_ver = ".$conn->quote(uns_version()));

    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $seed = '';
    for($p = 0; $p < 32; $p++){$seed .= $characters[random_int(0, strlen($characters)-1)];}
    $admin_pwd = (string)filter_input(INPUT_POST, 'uns_admin_pwd', FILTER_UNSAFE_RAW);
    if($admin_pwd === "")
    {
        die("<tr><td colspan='2' class='Emerg'>An internal admin password is required.</td></tr></table></div></body></html>");
    }
    # New installs get a modern hash straight away; the $seed above is kept around only
    # because the admin options page still displays/rewrites it for older installs.
    $password_hash = password_hash($admin_pwd, PASSWORD_DEFAULT);

    echo "<tr><td>Create UNS internal admin user.</td>";
    $stmt = $conn->prepare("INSERT INTO internal_users (username, password, disabled, failed) VALUES ('unsadmin', ?, 0, 0)");
    if($stmt->execute([$password_hash]))
    {
        $stmt2 = $conn->prepare("INSERT INTO allowed_users (username, domain, edit_urls, edit_emerg, edit_users, edit_options, c_messages, rss_feeds) VALUES ('unsadmin', '', 1, 1, 1, 1, 1, 1)");
        if($stmt2->execute()){echo "<td class='Good'>Success</td></tr>";}
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
    if($tz === "" || !in_array($tz, timezone_identifiers_list(), true)){$tz = "UTC";}
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
        ."\$LDAP           = $ldap; # If this flag is set, internal users will be overridden, except for the Admin.\n"
        ."\$max_archives   = $max_arch; # The Maximum number of Archived URL lists that will be kept before the oldest is killed\n"
        ."\$max_conn_hist  = $max_conns; # The Maximum number of Connection histories that will be kept per client.\n"
        ."\$lpt_set_app    = ''; # Bin for the LPT LED blinker\n"
        ."\$lpt_read_app   = ''; # Bin for LPT value reader\n"
        ."\$led_blink      = 0; # Variable to turn on the LPT LED blinking\n"
        ."\$mysql_dump_bin = 'mysqldump'; # Name or location of the mysqldump binary, used by the admin backup/restore feature\n"
        ."\n# The Template variables for RSS feeds\n"
        ."\$template_head_rss = '<html>\n    <head>\n        <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\n        <title>Powered by UNS</title>\n        <link rel=\"stylesheet\" href=\"../configs/rss_styles.css\">\n    </head>\n    <body class=\"body\">';\n"
        ."\$template_foot_rss = '\n    </body>\n</html>';\n"
        ."\n# The Template variables for Custom Messages\n"
        ."\$template_head_cmsg = '<html>\n\t<head>\n\t\t<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\n\t\t<title>Powered by UNS</title>\n\t\t<link rel=\"stylesheet\" href=\"../configs/cmsg_styles.css\">\n\t</head>\n\t<body style=\"background-color: #C0C0C0\">\n\t\t<table style=\"width: 80%; height: 100%;\" align=\"center\">\n\t\t\t<tr>\n\t\t\t\t<td class=\"cmsgheader\" style=\"height: 67px\">\n\t\t\t\t\t<img alt=\"Logo\" src=\"../html/logo.png\" width=\"462\" height=\"70\">\n\t\t\t\t</td>\n\t\t\t</tr>\n\t\t\t<tr class=\"InfoCell\">\n\t\t\t\t<td valign=\"top\"><br>\n\t\t\t\t\t<table style=\"width: 80%\" align=\"center\">\n\t\t\t\t\t\t<tr>\n\t\t\t\t\t\t\t<td>';\n"
        ."\$template_foot_cmsg = '\t\t\t\t\t\t\t</td>\n\n\t\t\t\t\t\t</tr>\n\t\t\t\t\t</table>\n\n\t\t\t\t</td>\n\t\t\t</tr>\n\t\t</table>\n\t</body>\n</html>';\n";

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

    echo "</table><p>Install complete. Log in as <b>unsadmin</b> with the password you chose. <a href='admin/index.php'>Go to the Admin Panel</a>.</p></div></body></html>";
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
                <td width="50%">PHP Version &gt;= 8.0?</td>
                <td><?php echo (PHP_VERSION_ID >= 80000) ? "<font color='limegreen'>GOOD! {".PHP_VERSION."}</font>" : "<font color='red'>PHP version is too old.<br />".PHP_VERSION."</font>"; ?></td>
            </tr>
            <tr class="pre">
                <td>PDO extension?</td>
                <td><?php echo extension_loaded("pdo") ? "<font color='limegreen'>GOOD!</font>" : "<font color='red'>pdo extension is not loaded.</font>"; ?></td>
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
            <tr class="client_table_body sql-server-field"><td>UNS SQL Username</td><td><input type="text" name="uns_sql_usr" style="width:50%" value="uns_user"/></td></tr>
            <tr class="client_table_body sql-server-field"><td>UNS SQL Password</td><td><input type="password" name="uns_sql_pwd" style="width:50%" value=""/></td></tr>
            <tr class="client_table_body sql-server-field"><td>Database Name</td><td><input type="text" name="db_name" style="width:50%" value="uns"/></td></tr>
            <tr class="client_table_body sql-server-field"><td>UNS SQL User's allowed host <font size="1">(usually "localhost")</font></td><td><input type="text" name="sql_user_host" style="width:50%" value="localhost"/></td></tr>
            <tr class="client_table_body" id="sqlite-field" style="display:none"><td>SQLite file name <font size="1">(saved under configs/)</font></td><td><input type="text" name="sqlite_name" style="width:50%" value="uns"/></td></tr>
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
            <tr class="client_table_body"><td>Internal Admin Password <font size="1">(needed even if LDAP is used)</font></td><td><input type="password" name="uns_admin_pwd" style="width:50%" value=""/></td></tr>
            <tr class="client_table_body"><td>Max Number of Archived links per Client</td><td><input type="text" name="max_arch" style="width:50%" value="10"/></td></tr>
            <tr class="client_table_body"><td>Max Number of Connection History per Client</td><td><input type="text" name="max_conns" style="width:50%" value="10"/></td></tr>
            <tr class="client_table_tail"><td align="center" colspan="2"><input type="submit" value="Submit" /></td></tr>
        </table>
    </form>
</div>
</body>
</html>
