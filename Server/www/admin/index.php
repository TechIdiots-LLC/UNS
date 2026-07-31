<?php
#    index.php, Main source code for the UNS administration
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

include "../shared.php";
if(!check_install('..'))
{
    echo "You need to Install or Upgrade first.<br /><a href='../install.php'>Install Page</a>";
    die();
}
include "../configs/vars.php";
if($led_blink){blinky('admin');}

gen_base_urls("..");
$proto = $GLOBALS['proto'];
$admin_url = $GLOBALS['admin_url'];
$reg_url = $GLOBALS['reg_url'];

$func_1 = filter_input(INPUT_GET, 'func', FILTER_SANITIZE_ENCODED);
if(str_replace("/","",$host) != $_SERVER['SERVER_NAME'])
{
    ?>
       <script>location.href = '<?php echo $admin_url;?>admin/index.php';</script>
    <?php
}


date_default_timezone_set($TZ);

if($func_1 === "logout")
{
    if(@$_COOKIE['login_yes'])
    {
        include "../configs/conn.php";
        if(!isset($driver)){$driver = 'mysql';}
        $conn = db_connect($server, $username, $password, $db, $driver);
        $cookie_exp = explode(":", filter_input(INPUT_COOKIE, 'login_yes', FILTER_SANITIZE_SPECIAL_CHARS));
        $cookie_hash = $cookie_exp[0];

        $stmt = $conn->prepare("SELECT * FROM hash_links where hash = ?");
        $stmt->execute([$cookie_hash]);
        $links = $stmt->fetch(PDO::FETCH_ASSOC);
        $del_ok = true;
        if(!empty($links['id']))
        {
            $del_stmt = $conn->prepare("DELETE FROM hash_links where id = ?");
            $del_ok = $del_stmt->execute([$links['id']]);
        }
        if($del_ok)
        {
            if(setcookie("login_yes", "", time()-3600 , "/".$root."admin", '', $SSL, 1))
            {echo "Logged out";
            ?>
       <script>location.href = '<?php echo $admin_url;?>admin/index.php';</script>
                    <?php
            die();}
        }else
        {
            setcookie("login_yes", "", time()-3600 , "/".$root."admin", '', $SSL, 1);
            echo "Failed to remove session from table.";
            die();
        }
    }else
    {
        echo "Logged out";
            ?>
      <script>location.href = '<?php echo $admin_url;?>admin/index.php';</script>
                    <?php
            die();
    }
}

$GET_login = filter_input(INPUT_GET, 'login', FILTER_SANITIZE_ENCODED);
if($GET_login)
{
    include "../configs/conn.php";
    #var_dump($_POST); echo"<br />";
    $usr = filter_input(INPUT_POST, 'user', FILTER_SANITIZE_SPECIAL_CHARS);
    $pwd = filter_input(INPUT_POST, 'pass', FILTER_SANITIZE_SPECIAL_CHARS);
    #var_dump($usr); echo"<br />";
    if($usr == "")
    {
        login_form("Username cannot be Blank.");
    }
    if($pwd == "")
    {
        login_form("Password cannot be Blank.");
    }
    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    $stmt = $conn->query("SELECT * FROM settings limit 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if($LDAP)
    {
        $internal = 0;
        $usr_exp = explode("\\", strtolower($usr));
        if(!@$usr_exp[1])
        {
            $user = $usr_exp[0];
            $internal = 1;
        }
        if(!$internal)
        {
            $user = $usr_exp[1];
            $u_domain = $usr_exp[0];
            $stmt = $conn->prepare("SELECT * FROM allowed_users where domain = ? AND username = ?");
            $stmt->execute([$u_domain, $user]);
            $array = $stmt->fetch(PDO::FETCH_ASSOC);
            if($array && $user == $array['username'])
            {
                $ldap = ldap_connect($domain, $port);
                $bind = @ldap_bind($ldap, $usr, $pwd);
                if(!$bind){ login_form("Error: Failed to connect to $domain."); }
                ldap_unbind($ldap);
                if(create_cookie($array['username']))
                {
                    die("Logged In!");
                }else
                {
                    login_form("Login Failed.");
                }
            }else
            {
                login_form("User is not allowed...");
            }
        }
    }
    $stmt = $conn->prepare("SELECT * FROM allowed_users where username = ?");
    $stmt->execute([$usr]);
    $array = $stmt->fetch(PDO::FETCH_ASSOC);
    if($array && $usr == $array['username'])
    {
        # The built-in admin account name is chosen at install time and stored in
        # configs/vars.php. Older installs predate that setting, so fall back to the
        # original hardcoded name rather than locking anyone out on upgrade.
        if(!isset($admin_user) || $admin_user === ''){$admin_user = 'unsadmin';}

        # Previously this only ever authenticated the built-in admin: both branches tested
        # "$usr == unsadmin", so any other internal user created from the admin panel fell
        # through to a blank page and could never log in - even though the Add User form
        # collects a password and stores a hash for them. Any account that exists in both
        # allowed_users and internal_users and is not disabled can now log in.
        if($usr == $admin_user && $settings['built_in_admin'])
        {
            # The built-in account has its own on/off switch on the user permissions page.
            login_form("User is Disabled.");
        }
        else
        {
            $stmt = $conn->prepare("SELECT username,password,disabled FROM internal_users where username = ?");
            $stmt->execute([$usr]);
            $array = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!$array)
            {
                # Listed in allowed_users but with no internal password - an LDAP-only
                # account being tried while LDAP is off.
                login_form("Login Failed.");
            }
            elseif(!empty($array['disabled']))
            {
                login_form("User is Disabled.");
            }
            else
            {
                $stored_pwd = $array['password'] ?? '';
                $valid = false;
                if($stored_pwd !== '' && password_get_info($stored_pwd)['algo'] !== null)
                {
                    # modern password_hash() format
                    $valid = password_verify($pwd, $stored_pwd);
                }elseif($stored_pwd !== '' && hash_equals($stored_pwd, md5($pwd.$seed)))
                {
                    # legacy md5(password.seed) format - accept it, then opportunistically upgrade
                    $valid = true;
                    $new_hash = password_hash($pwd, PASSWORD_DEFAULT);
                    $upd_stmt = $conn->prepare("UPDATE internal_users SET password = ? WHERE username = ?");
                    $upd_stmt->execute([$new_hash, $usr]);
                }
                if($valid)
                {
                    if($cook = create_cookie($array['username']))
                    {echo "Logged In!";}else{login_form("cookie failed: ".$cook);}
                }
                else{login_form("Login Failed.");}
            }
        }
    }else
    {
        login_form("User is not allowed...");
    }
    die();
}

if(!login_check())
{
    login_form("");
}

if(login_check())
{
    include "../configs/vars.php";
    include "../configs/conn.php";
    if(!isset($driver)){$driver = 'mysql';}
    $cookie = explode(":", filter_input(INPUT_COOKIE, 'login_yes', FILTER_SANITIZE_SPECIAL_CHARS));
    $cookie_hash = $cookie[0];
    $conn = db_connect($server, $username, $password, $db, $driver);
    $stmt = $conn->prepare("SELECT * FROM hash_links where hash = ?");
    $stmt->execute([$cookie_hash]);
    $hash = $stmt->fetch(PDO::FETCH_ASSOC);
    $ID = $hash['id'] ?? null;
    # The username is always taken from the server-side hash_links row, never from the
    # client-supplied cookie value - otherwise anyone with any valid session hash could
    # edit their own cookie to claim to be a different (eg. admin) user.
    $cookie_user = $hash['username'] ?? null;
    if($ID !== null && $cookie_user !== null && time() < $hash['time'])
    {
        ?>
<html>
    <head>
        <title>UNS Admin Panel</title>
        <link rel="stylesheet" href="../configs/styles.css">
    </head>
    <body class="main_body">
        <?php
        $stmt = $conn->prepare("SELECT tz FROM allowed_users where username = ?");
        $stmt->execute([$cookie_user]);
        $tx_array = $stmt->fetch(PDO::FETCH_ASSOC);
        $exp = explode(":", $tx_array['tz'] ?? 'ewt:0');
        $tz_list = timezone_abbreviations_list();
        date_default_timezone_set($tz_list[$exp[0]][$exp[1]]["timezone_id"]);
        $func = filter_input(INPUT_GET, 'func', FILTER_SANITIZE_SPECIAL_CHARS);
        admin_panel($cookie_user, $func, $proto);
    }else
    {
        if($root == "" or $root == "/"){$path = "/admin";}else{$path = "/".$root."admin";}
        setcookie("login_yes", "", time()-3600 , $path, '', $SSL, 1);
        login_form("Session timed out. Log In Again.");
        $now = time();
        $del_stmt = $conn->prepare("DELETE FROM hash_links where time < ?");
        if(!$del_stmt->execute([$now]))
        {
            echo db_error($del_stmt);
        }
    }

}
?>
        <div align="center">
            <font size="1">
                Powered by <a class="links" href="http://uns.techidiots.net/ver.htm#1">UNS v<?php
                    # Prefer the VERSION file, which reflects the code actually deployed -
                    # after an upgrade that is newer than the value vars.php recorded at
                    # install time. $uns_ver is the fallback for deployments where VERSION
                    # did not come along.
                    $footer_ver = uns_version();
                    if($footer_ver === 'unknown' && isset($uns_ver) && $uns_ver !== ''){$footer_ver = $uns_ver;}
                    echo htmlspecialchars($footer_ver);
                ?></a><br />
                (
                <!-- replace with final release date -->
                <?php echo date("Y-m", filemtime('index.php'));?>
                ) Phillip Ferland / Random Intervals,
                Andrew Calcutt / TechIdiots LLC
            </font>
        </div>
    </body>
</html>

<?php

function admin_panel($usr, $func, $proto)
{
    include '../configs/vars.php';
    include '../configs/conn.php';
    
    #gen_base_urls("..");
    $proto = $GLOBALS['proto'];
    $admin_url = $GLOBALS['admin_url'];
    $reg_url = $GLOBALS['reg_url'];
    
    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    ?>
    <table width="100%">
        <tr>
            <td width="10px">
                <img src="../html/logo.png" title="UNS Logo">
            </td>
            <td align="left" valign="center">
                <font size="5"><?php echo $name_title;?> Notification System Administration Panel</font>
            </td>
            <td align="right">
                <form name="tz_change" action="?func=chg_tz" method="POST">
                    <select name="cl_timezone" onchange='this.form.submit()'>
                    <?php
                    $stmt = $conn->prepare("SELECT tz FROM allowed_users where username = ?");
                    $stmt->execute([$usr]);
                    $array = $stmt->fetch(PDO::FETCH_ASSOC);
                    $user_TZ = explode(":", $array['tz'] ?? 'ewt:0');
                    echo htmlspecialchars($array['tz'] ?? '', ENT_QUOTES);
                    foreach(timezone_abbreviations_list() as $key=>$TZ_L)
                    {
                        foreach($TZ_L as $key1=>$TL)
                        {
                            if(($key1 == $user_TZ[1])&&($key == $user_TZ[0]))
                            {
                                ?><option value="<?php echo $key;?>:<?php echo $key1;?>" selected="yes"><?php echo $TL["timezone_id"];?> (<?php echo ($TL["offset"]/60)/60;?>)</option><?php
                            }else
                            {
                                ?><option value="<?php echo $key;?>:<?php echo $key1;?>"><?php echo $TL["timezone_id"];?> (<?php echo ($TL["offset"]/60)/60;?>)</option><?php
                            }
                        }
                    }
                    ?>
                    </select>
                </form>
            </td>
        </tr>
    </table>
    
    <?php
    $result = $conn->query("SELECT emerg FROM settings");
    $emerg = $result->fetch(PDO::FETCH_ASSOC);
    if($emerg['emerg'])
    {
        ?>
        <table border="1px" width="100%">
            <tr class="Emerg">
                <td align="center"><font size="6">The Emergency Message is set.</font></td>
            </tr>
        </table>
        <?php
    }
    $stmt = $conn->prepare("SELECT * FROM allowed_users where username = ?");
    $stmt->execute([$usr]);
    $perms = $stmt->fetch(PDO::FETCH_ASSOC);
    #############
    $o=0;
    if($perms['edit_urls'])
    {
        $nav_bar[] = '<td align="center" class="navtd">Edit Clients: <br /><font color="lawngreen">Allowed</font></td>';
        $side_bar[] = '<p><a href="?" class="side_links">List Clients</a></p>';
    }else
    {
        $nav_bar[] = '<td align="center" class="navtd">Edit Clients: <br /><font color="red">Denied</font></td>';
        $side_bar[] = '';
        $o++;
    }
    #############
    if($perms['edit_emerg'])
    {
        $nav_bar[] = '<td align="center" class="navtd">Emergency Messages: <br /><font color="lawngreen">Allowed</font></td>';
        $side_bar[] = '<p><a href="?func=edit_emerg" class="side_links">Emergency Messages</a></p>';
    }else
    {
        $nav_bar[] = '<td align="center" class="navtd">Emergency Messages: <br /><font color="red">Denied</font></td>';
        $side_bar[] = '';
        $o++;
    }
    #############
    if($perms['edit_users'])
    {
        $nav_bar[] = '<td align="center" class="navtd">Edit Users: <br /><font color="lawngreen">Allowed</font></td>';
        $side_bar[] = '<p><a href="?func=view_users" class="side_links">User Permissions</a></p>';
    }else
    {
        $nav_bar[] = '<td align="center" class="navtd">Edit Users: <br /><font color="red">Denied</font></td>';
        $side_bar[] = '';
        $o++;
    }
    #############
    if($perms['c_messages'])
    {
        $nav_bar[] = '<td align="center" class="navtd">Custom Messages: <br /><font color="lawngreen">Allowed</font></td>';
        $side_bar[] = '<p><a href="?func=c_messages" class="side_links">Custom Messages</a></p>';
    }else
    {
        $nav_bar[] = '<td align="center" class="navtd">Custom Messages: <br /><font color="red">Denied</font></td>';
        $side_bar[] = '';
        $o++;
    }
    #############
    if($perms['rss_feeds'])
    {
        $nav_bar[] = '<td align="center" class="navtd">RSS Feeds: <br /><font color="lawngreen">Allowed</font></td>';
        $side_bar[] = '<p><a href="?func=rss_feeds" class="side_links">RSS Feeds</a></p>';
    }else
    {
        $nav_bar[] = '<td align="center" class="navtd">RSS Feeds: <br /><font color="red">Denied</font></td>';
        $side_bar[] = '';
        $o++;
    }
    if($perms['edit_options'])
    {
        $nav_bar[] = '<td align="center" class="navtd">UNS Options: <br /><font color="lawngreen">Allowed</font></td>';
        $side_bar[] = '<p><a href="?func=edit_options" class="side_links">UNS Options</a></p>';
    }else
    {
        $nav_bar[] = '<td align="center" class="navtd">UNS Options: <br /><font color="red">Denied</font></td>';
        $side_bar[] = '';
        $o++;
    }
    $side_bar[] = '<p><a href="?func=logout" class="side_links">Logout ('.$usr.')</a></p>';
    #############

    if($o == count($nav_bar))
    {
        $side_bar[0] = "No Permissions :-(";
    }

    ?>
    <table border="1px" width="100%">
        <tr>
            <td class="side_bar" valign="top" width="16%">
                <?php
                foreach($side_bar as $side)
                {
                    echo $side."\r\n";
                }
                ?>
            </td>
            <td valign="top" class="main_cell">
                <table border="1px" width="100%">
                    <tr class="nav_bar">
                        
                        
    <?php
    foreach($nav_bar as $nav)
    {
        echo $nav."\r\n";
    }
    ?>
                    </tr>
                </table>
    <?php

    switch($func)
    {
        case "chg_tz":
            $cl_timezone = filter_input(INPUT_POST, 'cl_timezone', FILTER_SANITIZE_SPECIAL_CHARS);
            $stmt = $conn->prepare("UPDATE allowed_users SET tz = ? WHERE username = ?");
            if($stmt->execute([$cl_timezone, $usr]))
            {
                echo "Changed Time Zone.";
                ?>
    <script>
        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=rss_feeds'",<?php echo $page_timeout;?>);
    </script>
                <?php
            }else
            {
                echo "Failed to Change Time Zone.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
            }
            break;
        case "del_backup":
            if(!empty($_POST['remove']) && is_array($_POST['remove']))
            {
                foreach($_POST['remove'] as $rem)
                {
                    # strip any path components entirely rather than trying to blacklist "../" -
                    # naive substring stripping can be bypassed (eg. "....//") and doesn't
                    # account for "\" on Windows.
                    $rem = basename(str_replace('\\', '/', (string)$rem));
                    if($rem === '' || !preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $rem))
                    {
                        echo "Skipped invalid filename.<br/>";
                        continue;
                    }
                    if(unlink(getcwd()."/backups/".$rem))
                    {
                        echo "Removed Old DB dump (".htmlspecialchars($rem, ENT_QUOTES).")<br/>";
                    }else
                    {
                        echo "Failed to Remove Old DB dump (".htmlspecialchars($rem, ENT_QUOTES).")<br />";
                    }
                }
            }
            break;
        case "backup_options":
            ?>
                <script type="text/javascript">
                <!--
                function SetAllCheckBoxes(FormName, FieldName, CheckValue)
                {
                        if(!document.forms[FormName])
                                return;
                        var objCheckBoxes = document.forms[FormName].elements[FieldName];
                        if(!objCheckBoxes)
                                return;
                        var countCheckBoxes = objCheckBoxes.length;
                        if(!countCheckBoxes)
                                objCheckBoxes.checked = CheckValue;
                        else
                                // set the check value for all check boxes
                                for(var i = 0; i < countCheckBoxes; i++)
                                        objCheckBoxes[i].checked = CheckValue;
                }
                // -->
                </script>
                <div align='center'>
                <table>
                    <tr>
                        <td width="50%" align="center">
                            <form name="bk_now" action="?func=backup" method="POST">
                                <input type="submit" value="Backup Database Now" />
                            </form>
                        </td>
                    </tr>
                </table>
                    <form name="bk_files" action="?func=del_backup" method="POST">
                <table width="75%" border="1">
                    <tr class="client_table_head">
                        <th colspan="3">
                            Previous Backups
                        </th>
                    </tr>
                    <?php
            $dir = getcwd()."/backups/";
            $dh = opendir($dir);
            $bk_files = array();
            while (($file = readdir($dh)) !== false)
            {
                if(strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== "sql"){continue;}
                $bk_files[] = $file;
            }
            closedir($dh);
            rsort($bk_files);
                    ?>
                    <tr class="client_table_head">
                        <th>Name</th><th>Size</th><th>Delete</th>
                    </tr>
            <?php
            foreach($bk_files as $file)
            {
                $file_esc = htmlspecialchars($file, ENT_QUOTES);
                echo "<tr class='client_table_body'><td align='center'><a href='".$admin_url."admin/backups/$file_esc'>$file_esc</a></td><td align='center'>".format_bytes(filesize($dir.$file))."</td><td align='center'><input type='checkbox' name='remove[]' value='$file_esc'></td></tr>";
            }
            ?>
                <tr>
                    <td class="client_table_tail">&nbsp;</td>
                    <td class="client_table_tail" align='center'><input type="submit" value="Delete"/></td>
                    <td class="client_table_tail" align='center'>
                        <input type="button" onclick="SetAllCheckBoxes('bk_files', 'remove[]', true);" value="Check"> 
                        <input type="button" onclick="SetAllCheckBoxes('bk_files', 'remove[]', false);" value="Uncheck">
                    </td>
                </table></form></div><?php
            break;
        case "backup":
            $bak_fldr = getcwd()."/backups/";
            $filename = 'UNS_'.date("Y-m-d-H-i-s") . '.sql';
            $backupFile = $bak_fldr.$filename;

            $command = escapeshellcmd($mysql_dump_bin)." -v -h ".escapeshellarg($server)." -u ".escapeshellarg($username)." --password=".escapeshellarg($password)." -B ".escapeshellarg($db).">".escapeshellarg($backupFile);
            exec($command,$sys, $ret);

            if(@filesize($backupFile) > 0)
            {
                echo "Backed up to <a href='".$admin_url."admin/backups/".htmlspecialchars($filename, ENT_QUOTES)."' target='_blank'>".htmlspecialchars($filename, ENT_QUOTES)."</a><br /><a href='javascript:history.go(-1)'>Go back</a>";
                ?>
    <!--<script>
        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=edit_options'",<?php echo $page_timeout;?>);
    </script>-->
                <?php
            }else
            {
                echo "Failed to Backup DB.<br /><a href='javascript:history.go(-1)'>Go back</a>";
            }
            break;
        case "restore":
            $restore = $_FILES['restore_sql']['tmp_name'];
            # basename() strips any directory components the client-supplied filename might
            # contain - without it this was an arbitrary-file-write (upload "../../x.sql" etc).
            $restore_name = basename(str_replace('\\', '/', (string)$_FILES['restore_sql']['name']));
            $saved = getcwd().'/restores/'.$restore_name;
            if(strtolower(pathinfo($restore_name, PATHINFO_EXTENSION)) !== "sql"){echo "Wrong File type.<br/>";break;}
            $command = "mysql -h ".escapeshellarg($server)." -u ".escapeshellarg($username)." --password=".escapeshellarg($password)." < ".escapeshellarg($saved);
            if(move_uploaded_file($restore, $saved))
            {
                $sys = system($command, $ret);
                echo "Ran MySQL Restore<br/>You may need to logout, then log back in.<br /><a onclick='history.back()'>Go back</a>";
            }else
            {
                echo "Failed to move temp file.<br/><a onclick='history.back()'>Go back</a>";
            }
            break;
        case "edit_opt_proc":
            include ('../configs/vars.php');
            # The current database settings, so this page can rewrite conn.php without the
            # form having to re-supply every value. Without it $driver was out of scope and
            # fell back to 'mysql' below, which silently broke SQLite and SQL Server installs
            # the first time anyone saved the options page.
            include ('../configs/conn.php');

            $sql_host = @filter_input(INPUT_POST, 'sql_host', FILTER_SANITIZE_ENCODED);
            $uns_sql_usr = @filter_input(INPUT_POST, 'uns_sql_usr', FILTER_SANITIZE_ENCODED);
            $uns_sql_pwd = @filter_input(INPUT_POST, 'uns_sql_pwd', FILTER_SANITIZE_SPECIAL_CHARS);
            $db_name = @filter_input(INPUT_POST, 'db_name', FILTER_SANITIZE_ENCODED);

            # A blank field means "leave this as it is", not "set it to empty". The password
            # box is always rendered empty, so demanding a value here meant a SQLite install -
            # which has no database password at all - could never save the options page, and a
            # server install had to have its password retyped on every unrelated change.
            if($sql_host === null || $sql_host === false || $sql_host === ''){$sql_host = isset($server) ? $server : '';}
            if($uns_sql_usr === null || $uns_sql_usr === false || $uns_sql_usr === ''){$uns_sql_usr = isset($username) ? $username : '';}
            if($uns_sql_pwd === null || $uns_sql_pwd === false || $uns_sql_pwd === ''){$uns_sql_pwd = isset($password) ? $password : '';}
            if($db_name === null || $db_name === false || $db_name === ''){$db_name = isset($db) ? $db : '';}
            
            $hostname = html_entity_decode(@filter_input(INPUT_POST, 'hostname', FILTER_SANITIZE_SPECIAL_CHARS));
            $uns_name = html_entity_decode(@filter_input(INPUT_POST, 'uns_name', FILTER_SANITIZE_SPECIAL_CHARS));
            $root1 = @filter_input(INPUT_POST, 'root', FILTER_SANITIZE_SPECIAL_CHARS);
            $timeout1 = @filter_input(INPUT_POST, 'timeout', FILTER_SANITIZE_ENCODED)+0;
            $SSL1 = @filter_input(INPUT_POST, 'ssl', FILTER_SANITIZE_ENCODED)+0;

            $domain1 = @filter_input(INPUT_POST, 'ldap_domain', FILTER_SANITIZE_ENCODED);
            $domain_port1 = @$_POST['ldap_port']+0;
            $page_timeout1 = @filter_input(INPUT_POST, 'page_timeout', FILTER_SANITIZE_ENCODED)+0;
            
            $refresh1 = @filter_input(INPUT_POST, 'refresh', FILTER_SANITIZE_ENCODED)+0;
            $max_arch1 = @filter_input(INPUT_POST, 'max_arch', FILTER_SANITIZE_ENCODED)+0;
            $max_conns1 = @filter_input(INPUT_POST, 'max_conns', FILTER_SANITIZE_ENCODED)+0;
            $ldap1 = @filter_input(INPUT_POST, 'ldap', FILTER_SANITIZE_ENCODED)+0;
            
            $leds = @filter_input(INPUT_POST, 'leds', FILTER_SANITIZE_ENCODED)+0;
            $lpt_binary = html_entity_decode(@filter_input(INPUT_POST, 'lpt_binary', FILTER_SANITIZE_SPECIAL_CHARS));
            $portctl = html_entity_decode(@filter_input(INPUT_POST, 'portctl', FILTER_SANITIZE_SPECIAL_CHARS));

            $mysql_dump_binary = @filter_input(INPUT_POST, 'mysql_dump', FILTER_SANITIZE_ENCODED);

            # Emergency monitor settings. The feed URL is a real URL, so it is validated
            # rather than merely escaped - it gets fetched by a scheduled script, and only
            # http/https should ever be requested.
            $emerg_feed_url1 = trim((string)html_entity_decode(@filter_input(INPUT_POST, 'emerg_feed_url', FILTER_SANITIZE_SPECIAL_CHARS)));
            if($emerg_feed_url1 !== '')
            {
                $scheme = strtolower((string)parse_url($emerg_feed_url1, PHP_URL_SCHEME));
                if(!filter_var($emerg_feed_url1, FILTER_VALIDATE_URL) || !in_array($scheme, array('http', 'https'), true))
                {
                    echo "Emergency feed URL is not a valid http/https URL - leaving it unset.<br />";
                    $emerg_feed_url1 = '';
                }
            }
            $emerg_display_minutes1 = @filter_input(INPUT_POST, 'emerg_display_minutes', FILTER_SANITIZE_ENCODED)+0;
            if($emerg_display_minutes1 < 1){$emerg_display_minutes1 = 30;}
            $emerg_publish_message1 = @filter_input(INPUT_POST, 'emerg_publish_message', FILTER_SANITIZE_ENCODED) ? 1 : 0;
            $emerg_allowed_status1 = trim((string)@filter_input(INPUT_POST, 'emerg_allowed_status', FILTER_SANITIZE_SPECIAL_CHARS));
            if($emerg_allowed_status1 === ''){$emerg_allowed_status1 = 'Actual';}
            $emerg_min_severity1 = (string)@filter_input(INPUT_POST, 'emerg_min_severity', FILTER_SANITIZE_SPECIAL_CHARS);
            if(!in_array($emerg_min_severity1, array('Unknown', 'Minor', 'Moderate', 'Severe', 'Extreme'), true)){$emerg_min_severity1 = 'Unknown';}
            $emerg_max_items1 = @filter_input(INPUT_POST, 'emerg_max_items', FILTER_SANITIZE_ENCODED)+0;
            if($emerg_max_items1 < 1){$emerg_max_items1 = 5;}
            $emerg_follow_cap_links1 = @filter_input(INPUT_POST, 'emerg_follow_cap_links', FILTER_SANITIZE_ENCODED) ? 1 : 0;

            # These go in the uns_config table rather than vars.php, so they are covered by
            # the database backup and readable by the scheduled monitor without it needing
            # to read a PHP file out of the web root.
            $emerg_cfg = array(
                'emerg_feed_url'         => $emerg_feed_url1,
                'emerg_display_minutes'  => $emerg_display_minutes1,
                'emerg_publish_message'  => $emerg_publish_message1,
                'emerg_allowed_status'   => $emerg_allowed_status1,
                'emerg_min_severity'     => $emerg_min_severity1,
                'emerg_max_items'        => $emerg_max_items1,
                'emerg_follow_cap_links' => $emerg_follow_cap_links1,
            );
            $emerg_cfg_ok = true;
            foreach($emerg_cfg as $cfg_k => $cfg_v)
            {
                if(!uns_config_set($conn, $driver, $cfg_k, $cfg_v)){$emerg_cfg_ok = false;}
            }
            echo $emerg_cfg_ok
                ? "Saved emergency monitor settings.<br />"
                : "Failed to save some emergency monitor settings: ".htmlspecialchars(db_error($conn), ENT_QUOTES)."<br />";

            # var_export() is used for every value below (rather than interpolating raw
            # strings into single-quoted PHP literals) - these files get written to disk and
            # then include()'d on every request, so a submitted value containing a stray quote
            # or backslash could otherwise inject arbitrary PHP code into the running app.
            $vars_file = "<?php\n"
                ."\$name_title     = ".var_export($uns_name, true)."; # Name of your Install, Will be displayed on all pages\n"
                ."\$host           = ".var_export($hostname, true)."; # The HTTP server the clients will connect to.\n"
                ."\$root           = ".var_export($root1, true)."; # Folder UNS lives in\n"
                ."\$timeout        = ($timeout1); # Cookie Time out\n"
                ."\$SSL            = $SSL1; # Cookie SSL only?\n"
                ."\$domain         = ".var_export($domain1, true)."; # LDAP Domain to connect to for user authentication\n"
                ."\$port           = $domain_port1; # LDAP Port\n"
                ."\$TZ             = ".var_export($TZ, true)."; # Local Time Zone\n"
                ."\$page_timeout   = $page_timeout1; # Refresh time for page to forward in seconds.\n"
                ."\$refresh        = $refresh1; # Time for client pages to refresh.\n"
                ."\$seed           = ".var_export($seed, true)."; # Only used for internal user logins, to hash the password and store that.\n"
                ."\$LDAP           = $ldap1; # If this flag is set, internal users will be overridden, except for the Admin.\n"
                ."\$max_archives   = $max_arch1; # The Maximum number of Archived URL lists that will be kept before the oldest is killed\n"
                ."\$max_conn_hist  = $max_conns1; # The Maximum number of Connection histories that will be kept per client.\n"
                ."\$lpt_read_app   = ".var_export($portctl, true)."; # Bin for LPT value reader\n"
                ."\$lpt_set_app    = ".var_export($lpt_binary, true)."; # Bin for the LPT LED blinker\n"
                ."\$led_blink      = $leds; # Variable to turn on the LPT LED blinking\n"
                ."\$mysql_dump_bin = ".var_export($mysql_dump_binary, true)."; # Name or location of the mysqldump binary\n"
                ."\n# The Template variables for RSS feeds\n"
                ."\$template_head_rss = ".var_export($template_head_rss, true).";\n"
                ."\$template_foot_rss = ".var_export($template_foot_rss, true).";\n"
                ."\n# The Template variables for Custom Messages\n"
                ."\$template_head_cmsg = ".var_export($template_head_cmsg, true).";\n"
                ."\$template_foot_cmsg = ".var_export($template_foot_cmsg, true).";\n";
            $cwd = str_replace("admin","",getcwd());

            if($fp = fopen($cwd."configs/vars.php", 'w+'))
            {fwrite($fp, $vars_file); fclose($fp);echo "Wrote Vars Config File.<br />";}
            else{echo "Failed to write Vars Config File.<br />";}
            sleep(1);

            # This form doesn't let you switch database drivers (that would need a real
            # migration, not just new connection strings) - it only preserves whatever
            # $driver the existing configs/conn.php already has.
            $conn_file = "<?php\n"
                ."\$driver = ".var_export($driver ?? 'mysql', true).";\n"
                ."\$server = ".var_export($sql_host, true).";  # DB Host (unused for sqlite)\n"
                ."\$username = ".var_export($uns_sql_usr, true).";      # DB user (unused for sqlite)\n"
                ."\$password = ".var_export($uns_sql_pwd, true).";      # DB password (unused for sqlite)\n"
                ."\$db = ".var_export($db_name, true).";            # Database name (mysql/sqlsrv) or file path (sqlite)\n";

            if($fp1 = fopen($cwd."configs/conn.php", 'w+'))
            {fwrite($fp1, $conn_file);echo "Wrote Conn Config File.<br />";
            ?>
    <!--<script>
        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=edit_options'",<?php echo $page_timeout;?>);
    </script>-->
            <?php
            }
            else{echo "Failed to write Conn Config File.<br />";}
            break;
        case "edit_options":
             ?>
                <script type="text/javascript">
                    function endisable( ) {
                        document.forms['edit_options'].elements['ldap_domain'].disabled =! document.forms['edit_options'].elements['ldap'].checked;
                        document.forms['edit_options'].elements['ldap_port'].disabled =! document.forms['edit_options'].elements['ldap'].checked;
                    }
                    function endisable_led( ) {
                        document.forms['edit_options'].elements['lpt_binary'].disabled =! document.forms['edit_options'].elements['leds'].checked;
                        document.forms['edit_options'].elements['portctl'].disabled =! document.forms['edit_options'].elements['leds'].checked;
                    }
                </script>
                <div align="center">
                    <table border="1">
                        <tr class="client_table_head">
                            <td width="50%" align="center">
                                <a href="?func=backup_options">Backup Options</a>
                            </td>
                            <td align="center">
                                <form enctype="multipart/form-data" name="backup_restore_options" action="?func=restore" method="POST">
                                    <input type="hidden" name="MAX_FILE_SIZE" value="1000000"/>
                                    <input type="file" name="restore_sql" ACCEPT="text/plain" /><br />
                                    <input type="submit" value="Restore Database" />
                                </form>
                            </td>
                        </tr>
                    </table>
                    <form name="edit_options" action="?func=edit_opt_proc" method="POST">
                        <table border="1">
                            <tr class="client_table_head">
                                <th colspan="2">
                                    UNS Options Editor
                                </th>
                            </tr>
                            <tr class="client_table_head">
                                <th colspan="2">
                                    SQL Settings
                                </th>
                            </tr>
                            <tr class="client_table_body">
                                <td width="250px">
                                    SQL Host
                                </td>
                                <td width="200px">
                                    <input type="text" name="sql_host" style="width:100%" value="<?php echo htmlspecialchars(html_entity_decode($server), ENT_QUOTES);?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    UNS SQL Username
                                </td>
                                <td>
                                    <input type="text" name="uns_sql_usr" style="width:100%" value="<?php echo $username;?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    UNS SQL Password
                                </td>
                                <td>
                                    <input type="password" name="uns_sql_pwd" style="width:100%" value=""/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Database Name
                                </td>
                                <td>
                                    <input type="text" name="db_name" style="width:100%" value="<?php echo $db;?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_head">
                                <th colspan="2">
                                    UNS Variables
                                </th>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Instance Name
                                </td>
                                <td>
                                    <input type="text" name="uns_name" style="width:100%" value="<?php echo html_entity_decode($name_title);?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Hostname
                                </td>
                                <td>
                                    <input type="text" name="hostname" style="width:100%" value="<?php echo html_entity_decode($host);?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                        HTTP root for UNS
                                </td>
                                <td>
                                    <input type="text" name="root" style="width:100%" value="<?php echo html_entity_decode($root);?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Session Timeout <font size="1">( Seconds )</font>
                                </td>
                                <td>
                                    <input type="text" name="timeout" style="width:100%" value="<?php echo $timeout;?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    SSL Admin Folder?
                                </td>
                                <td>
                                    <input type="checkbox" name="ssl" value="1" <?php if($SSL){echo "checked";}?>/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Use LDAP?
                                </td>
                                <td>
                                    <input type="checkbox" name="ldap" value="1" <?php if($LDAP){echo "checked";}?> onchange="endisable()"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    LDAP Domain
                                </td>
                                <td>
                                    <input type="text" name="ldap_domain" style="width:100%" value="<?php echo $domain;?>" <?php if(!$LDAP){echo "disabled";}?>/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    LDAP Port
                                </td>
                                <td>
                                    <input type="text" name="ldap_port" style="width:100%" value="<?php echo $port;?>" <?php if(!$LDAP){echo "disabled";}?>/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Redirect Page Timeout <font size="1">( Zero [0], will be an instant redirect. )</font>
                                </td>
                                <td>
                                    <input type="text" name="page_timeout" style="width:100%" value="0"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Default URL Refresh time
                                </td>
                                <td>
                                    <input type="text" name="refresh" style="width:100%" value="30"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Max Number of Archived links per Client
                                </td>
                                <td>
                                    <input type="text" name="max_arch" style="width:100%" value="<?php echo $max_archives;?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Max Number of Connection History per Client.
                                </td>
                                <td>
                                    <input type="text" name="max_conns" style="width:100%" value="<?php echo $max_conn_hist;?>"/>
                                </td>
                            </tr>
                            <?php
                            # Settings for Scripts/EmergencyMonitor, read from the uns_config
                            # table. The table is created on demand, so an install that predates
                            # it simply shows the defaults here until the form is first saved.
                            if(!isset($driver)){$driver = 'mysql';}
                            $ef_cfg      = uns_config_all($conn, $driver);
                            $ef_url      = isset($ef_cfg['emerg_feed_url']) ? $ef_cfg['emerg_feed_url'] : '';
                            $ef_minutes  = isset($ef_cfg['emerg_display_minutes']) ? (int)$ef_cfg['emerg_display_minutes'] : 30;
                            $ef_publish  = isset($ef_cfg['emerg_publish_message']) ? (int)$ef_cfg['emerg_publish_message'] : 1;
                            $ef_status   = isset($ef_cfg['emerg_allowed_status']) ? $ef_cfg['emerg_allowed_status'] : 'Actual';
                            $ef_severity = isset($ef_cfg['emerg_min_severity']) ? $ef_cfg['emerg_min_severity'] : 'Unknown';
                            $ef_max      = isset($ef_cfg['emerg_max_items']) ? (int)$ef_cfg['emerg_max_items'] : 5;
                            $ef_follow   = isset($ef_cfg['emerg_follow_cap_links']) ? (int)$ef_cfg['emerg_follow_cap_links'] : 1;
                            ?>
                            <tr class="client_table_head">
                                <td colspan="2" align="center">Emergency Alert Monitor
                                    <br /><font size="1">Used by the scheduled script in Scripts/EmergencyMonitor.
                                    Leave the feed URL empty to switch it off.</font></td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Alert feed URL
                                    <br /><font size="1">CAP, RSS or Atom - the format is detected automatically</font>
                                </td>
                                <td>
                                    <input type="text" name="emerg_feed_url" style="width:100%" value="<?php echo htmlspecialchars($ef_url, ENT_QUOTES);?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Display time (minutes)
                                    <br /><font size="1">Only used when the feed gives no expiry. CAP alerts carry their own.</font>
                                </td>
                                <td>
                                    <input type="text" name="emerg_display_minutes" style="width:100%" value="<?php echo $ef_minutes;?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Publish the alert text to displays?
                                    <br /><font size="1">Writes the alert into a custom message and points an emergency URL at it</font>
                                </td>
                                <td>
                                    <input type="checkbox" name="emerg_publish_message" value="1" <?php if($ef_publish){echo "checked";}?>/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    CAP status values to act on
                                    <br /><font size="1">Comma separated. Adding Test or Exercise lets drills take over displays.</font>
                                </td>
                                <td>
                                    <input type="text" name="emerg_allowed_status" style="width:100%" value="<?php echo htmlspecialchars($ef_status, ENT_QUOTES);?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Minimum CAP severity
                                </td>
                                <td>
                                    <select name="emerg_min_severity" style="width:100%">
                                        <?php
                                        foreach(array('Unknown', 'Minor', 'Moderate', 'Severe', 'Extreme') as $sev)
                                        {
                                            echo "<option value=\"".$sev."\"".($ef_severity === $sev ? " selected" : "").">".$sev."</option>";
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Feed entries to check
                                    <br /><font size="1">Several alerts can be in force at once, so more than the newest is checked</font>
                                </td>
                                <td>
                                    <input type="text" name="emerg_max_items" style="width:100%" value="<?php echo $ef_max;?>"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Follow feed links to CAP documents?
                                    <br /><font size="1">For feeds whose entries link to CAP rather than embedding it</font>
                                </td>
                                <td>
                                    <input type="checkbox" name="emerg_follow_cap_links" value="1" <?php if($ef_follow){echo "checked";}?>/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Use LEDs?
                                </td>
                                <td>
                                    <input type="checkbox" name="leds" value="1" <?php if($led_blink){echo "checked";}?> onchange="endisable_led()"/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    LPT Binary
                                </td>
                                <td>
                                    <input type="text" name="lpt_binary" style="width:100%" value="<?php echo html_entity_decode($lpt_set_app);?>" <?php if(!$led_blink){echo "disabled";}?>/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Portctl Binary
                                </td>
                                <td>
                                    <input type="text" name="portctl" style="width:100%" value="<?php echo html_entity_decode($lpt_read_app);?>" <?php if(!$led_blink){echo "disabled";}?>/>
                                </td>
                            </tr>
                            <tr class="client_table_body">
                                <td>
                                    Mysql Dump Binary
                                </td>
                                <td>
                                    <input type="text" name="mysql_dump" style="width:100%" value="<?php echo html_entity_decode($mysql_dump_bin);?>" />
                                </td>
                            </tr>
                            <tr class="client_table_tail">
                                <td align="center"colspan="2">
                                    <input type="submit" value="Submit" />
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>
                    <?php
            break;
        case "rss_feeds":
            if($perms['rss_feeds'])
            {
                $mode = @filter_input(INPUT_GET, 'mode', FILTER_SANITIZE_SPECIAL_CHARS);
                switch($mode)
                {
                    case "add_rss":
                        $url = @filter_input(INPUT_POST, 'url_n', FILTER_SANITIZE_SPECIAL_CHARS);
                        $name = @filter_input(INPUT_POST, 'name_n', FILTER_SANITIZE_SPECIAL_CHARS);
                        $maxlines = (int)@filter_input(INPUT_POST, 'maxlines_n', FILTER_SANITIZE_SPECIAL_CHARS);
                        $stmt = $conn->prepare("INSERT INTO rss_feeds (name, url, maxlines) VALUES (?, ?, ?)");
                        if($stmt->execute([$name, $url, $maxlines]))
                        {
                            echo "Added Feeds.";
                            ?>
                <script>
                    setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=rss_feeds'",<?php echo $page_timeout;?>);
                </script>
                            <?php
                        }else
                        {
                            echo "Failed to update Feeds<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                        break;
                    case "edit_rss":
                        if(@$_POST['remove'] == "Remove")
                        {
                            if(!@$_POST['remove_'])
                            {
                                ?>
                        <script>
                            setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=rss_feeds'",<?php echo $page_timeout;?>);
                        </script>
                                <?php
                                break;
                            }
                            foreach($_POST['remove_'] as $key=>$del)
                            {
                                $del_esc = htmlspecialchars($del, ENT_QUOTES);
                                $search = $reg_url."html/template.php?type=rss&id=$del";
                                $stmt = $conn->prepare("SELECT id FROM emerg WHERE url = ?");
                                $stmt->execute([$search]);
                                $id = $stmt->fetch(PDO::FETCH_ASSOC);
                                if(!empty($id['id']))
                                {
                                    echo "Found message in Emergency Table.....";
                                    $del_stmt = $conn->prepare("DELETE FROM emerg WHERE id = ?");
                                    if($del_stmt->execute([$id['id']]))
                                    {echo "Removed!<br />";}
                                    else{echo "Failed to Remove<br />";}
                                }else{echo "Custom Message [$del_esc] was not in the Emergency Table<br />";}
                                #Gather client ids list.
                                $result = $conn->query("SELECT client_name FROM allowed_clients");
                                $cl_c = 0;
                                #Check Client lists for Custom Messages
                                $link = db_connect($server, $username, $password, $db, $driver);
                                while($clid = $result->fetch(PDO::FETCH_ASSOC))
                                {
                                    $cl = $clid['client_name'];
                                    if(!is_safe_client_id($cl)){continue;}
                                    $like_pattern = '%rss&id='.$del;
                                    $stmt1 = $link->prepare("SELECT id FROM ".$cl."_links WHERE url LIKE ?");
                                    $stmt1->execute([$like_pattern]);
                                    $id = $stmt1->fetch(PDO::FETCH_ASSOC);
                                    if(!empty($id['id']))
                                    {
                                        echo (int)$id['id']."<br />";
                                        echo "Found message in ".htmlspecialchars($cl, ENT_QUOTES)." Link Table.....";
                                        $del_stmt1 = $link->prepare("DELETE FROM ".$cl."_links WHERE id = ?");
                                        if($del_stmt1->execute([$id['id']]))
                                        {echo "Removed!<br />";}
                                        else{echo "Failed to Remove<br />";}
                                        $cl_c++;
                                    }else{echo "none<br />";}
                                    echo "<hr />";
                                }
                                $link = null;
                                if(!$cl_c){echo "<br /><br />Couldnt Find Any Clients with Custom Message [$del_esc]<br />";}
                                else{echo "<br /><br />Found [$cl_c] Clients with Custom Message [$del_esc].<br />";}
                                #remove Custom message
                                $del_stmt2 = $conn->prepare("DELETE FROM rss_feeds WHERE id = ?");
                                if($del_stmt2->execute([$del]))
                                {
                                    echo "Removed message [$del_esc].";
                                    ?>
                        <script>
                            setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=rss_feeds'",<?php echo $page_timeout;?>);
                        </script>
                                    <?php
                                }else
                                {
                                    echo "Failed to Remove message [$del_esc].<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                        }else
                        {
                            foreach($_POST['id'] as $key=>$id)
                            {
                                $url = $_POST['body'][$key];
                                $name = $_POST['name'][$key];
                                $maxlines = (int)$_POST['maxlines'][$key];
                                $stmt = $conn->prepare("UPDATE rss_feeds SET name = ?, url = ?, maxlines = ? WHERE id = ?");
                                $id_esc = htmlspecialchars((string)$id, ENT_QUOTES);
                                $name_esc = htmlspecialchars((string)$name, ENT_QUOTES);
                                if($stmt->execute([$name, $url, $maxlines, $id]))
                                {
                                    echo "Updated Feed [$id_esc] ($name_esc).";
                                    ?>
                        <script>
                            setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=rss_feeds'",<?php echo $page_timeout;?>);
                        </script>
                                    <?php
                                }else
                                {
                                    echo "Failed to Update Feed [$id_esc] ($name_esc).<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                        }
                        break;
                    default:
                        ?>
                <script type="text/javascript">
                <!--
                function SetAllCheckBoxes(FormName, FieldName, CheckValue)
                {
                        if(!document.forms[FormName])
                                return;
                        var objCheckBoxes = document.forms[FormName].elements[FieldName];
                        if(!objCheckBoxes)
                                return;
                        var countCheckBoxes = objCheckBoxes.length;
                        if(!countCheckBoxes)
                                objCheckBoxes.checked = CheckValue;
                        else
                                // set the check value for all check boxes
                                for(var i = 0; i < countCheckBoxes; i++)
                                        objCheckBoxes[i].checked = CheckValue;
                }
                function expandcontract(tbodyid,ClickIcon)
                {
                        if (document.getElementById(ClickIcon).innerHTML == "+")
                        {
                                document.getElementById(tbodyid).style.display = "";
                                document.getElementById(ClickIcon).innerHTML = "-";
                        }else{
                                document.getElementById(tbodyid).style.display = "none";
                                document.getElementById(ClickIcon).innerHTML = "+";
                        }
                }
                // -->
                </script>
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <form name="save_new" action="?func=rss_feeds&mode=edit_rss" method="POST">
                        <th colspan="6">RSS Feeds</th>
                    </tr>
                    <tr class="client_table_head">
                        <th colspan="6"><input type='submit' name="update_rss" value="Update All Feeds"></th>
                    </tr>
                    <tr class="client_table_head">
                        <th>+/-</th><th>Name</th><th>Max lines</th><th>RSS Feed URL</th><th>Options</th>
                    </tr>
                    <?php
                        $link = db_connect($server, $username, $password, $db, $driver);
                        $result = $link->query("SELECT * FROM rss_feeds ORDER by name ASC");
                        $rss_all = $result->fetchAll(PDO::FETCH_ASSOC);
                        if(count($rss_all))
                        {
                            $tablerowid=0;
                            foreach($rss_all as $links)
                            {
                                $rss_id = (int)$links['id'];
                                $rss_name = htmlspecialchars($links['name'], ENT_QUOTES);
                                $rss_url = htmlspecialchars($links['url'], ENT_QUOTES);
                            ?>
                        <tr class="client_table_body">
                            <td onclick="expandcontract('mesgRow<?php echo $tablerowid;?>','mesgClickIcon<?php echo $tablerowid;?>')"
                                id="mesgClickIcon<?php echo $tablerowid;?>" style="cursor: pointer; cursor: hand;">+</td>
                            </td>
                            <td style="width:25%;">
                                <input type="hidden" name="id[]" value="<?php echo $rss_id;?>"/>
                                <input type="text" name="name[]" style="width:90%;" value="<?php echo $rss_name;?>"/>
                            </td>
                            <td>
                                <input type="text" name="maxlines[]" style="width:45px;" value="<?php echo (int)$links['maxlines'];?>"/>
                            </td>
                            <td>
                                <a class="links" href="<?php echo $reg_url;?>html/template.php?type=rss&id=<?php echo $rss_id;?>" target="_blank"><?php echo $reg_url;?>html/template.php?type=rss&id=<?php echo $rss_id;?></a>
                            </td>
                            <td align="center">
                                <input type="checkbox" name="remove_[]" value="<?php echo $rss_id;?>"/>
                            </td>
                        </tr>
                        <tbody id="mesgRow<?php echo $tablerowid;?>" style="display:none">
                        <tr>
                            <td colspan="6">
                                <input type="text" name="body[]" style="width:100%" value="<?php echo $rss_url;?>" />
                                <br />
                                <br />
                            </td>
                        </tr>
                        </tbody>
                            <?php
                                $tablerowid++;
                            }
                        }else
                        {
                        ?>
                        <tr>
                            <td align="center" colspan="5">
                                There are no RSS Feeds yet.
                            </td>
                        </tr>
                        <?php
                        }
                        ?>
                    <tr class="client_table_tail">
                        <td align="center" colspan="4">
                        </td>
                        <td align="center">
                            <table width="100%">
                                <tr>
                                    <td align="center">
                                        <input type='submit' name="remove" value='Remove'>
                                    </td>
                                    <td align="center">
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', true);" value="Check"><br />
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', false);" value="Uncheck">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="6" align="center">
                            <form name="save_new1" action="?func=rss_feeds&mode=add_rss" method="POST">
                            <table>
                                <tr>
                                    <td valign="center">
                                        Name:
                                    </td>
                                    <td>
                                        <input type="text" name="name_n" style="width:400px;" value="">
                                    </td>
                                </tr>
                                <tr>
                                    <td valign="center">
                                        RSS URL:
                                    </td>
                                    <td>
                                        <input type="text" name="url_n" style="width:400px;" value="http://">
                                    </td>
                                </tr>
                                <tr>
                                    <td valign="center">
                                        Max Lines:
                                    </td>
                                    <td>
                                        <input type="text" name="maxlines_n" style="width:45px" value="<?php echo $refresh; ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center">
                                        <input type='submit' value='Add RSS'>
                                    </td>
                                </tr>
                            </table>
                            </form>
                        </td>
                    </tr>
                </table>
                        <?php
                        break;
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "c_messages":
            if($perms['c_messages'])
            {
                $mode = @filter_input(INPUT_GET, 'mode', FILTER_SANITIZE_SPECIAL_CHARS);
                switch($mode)
                {
                    case "add_messg":
                        $body = @filter_input(INPUT_POST, 'body_n', FILTER_SANITIZE_SPECIAL_CHARS);
                        $name = @filter_input(INPUT_POST, 'name_n', FILTER_SANITIZE_SPECIAL_CHARS);
                        $wrapper = (int)@filter_input(INPUT_POST, 'wrapper', FILTER_SANITIZE_SPECIAL_CHARS);
                        $stmt = $conn->prepare("INSERT INTO c_messages (name, body, wrapper) VALUES (?, ?, ?)");
                        if($stmt->execute([$name, $body, $wrapper]))
                        {
                            echo "Updated message.";
                            ?>
                <script>
                    setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=c_messages'",<?php echo $page_timeout;?>);
                </script>
                            <?php
                        }else
                        {
                            echo "Failed to update message<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                        break;
                    case "edit_messg":
                        if(@$_POST['remove'] == "Remove")
                        {
                            if(!@$_POST['remove_'])
                            {
                                ?>
                        <script>
                            setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=c_messages'",<?php echo $page_timeout;?>);
                        </script>
                                <?php
                                break;
                            }
                            foreach($_POST['remove_'] as $key=>$del)
                            {
                                $del_esc = htmlspecialchars($del, ENT_QUOTES);
                                #Check Emerg for Custom messages
                                $search = $reg_url."html/template.php?type=c_message&id=$del";
                                $stmt = $conn->prepare("SELECT id FROM emerg WHERE url = ?");
                                $stmt->execute([$search]);
                                $id = $stmt->fetch(PDO::FETCH_ASSOC);
                                if(!empty($id['id']))
                                {
                                    echo "Found message in Emergency Table.....";
                                    $del_stmt = $conn->prepare("DELETE FROM emerg WHERE id = ?");
                                    if($del_stmt->execute([$id['id']]))
                                    {echo "Removed!<br />";}
                                    else{echo "Failed to Remove<br />";}
                                }else{echo "Custom Message [$del_esc] was not in the Emergency Table<br />";}
                                #Gather client ids list.
                                $result = $conn->query("SELECT client_name FROM allowed_clients");
                                $cl_c = 0;
                                #Check Client lists for Custom Messages
                                $link = db_connect($server, $username, $password, $db, $driver);
                                while($clid = $result->fetch(PDO::FETCH_ASSOC))
                                {
                                    $cl = $clid['client_name'];
                                    if(!is_safe_client_id($cl)){continue;}
                                    $like_pattern = '%c_message&id='.$del;
                                    $stmt1 = $link->prepare("SELECT id FROM ".$cl."_links WHERE url LIKE ?");
                                    $stmt1->execute([$like_pattern]);
                                    $id = $stmt1->fetch(PDO::FETCH_ASSOC);
                                    if(!empty($id['id']))
                                    {
                                        echo (int)$id['id']."<br />";
                                        echo "Found message in ".htmlspecialchars($cl, ENT_QUOTES)." Link Table.....";
                                        $del_stmt1 = $link->prepare("DELETE FROM ".$cl."_links WHERE id = ?");
                                        if($del_stmt1->execute([$id['id']]))
                                        {echo "Removed!<br />";}
                                        else{echo "Failed to Remove<br />";}
                                        $cl_c++;
                                    }else{echo "none<br />";}
                                    echo "<hr />";
                                }
                                $link = null;
                                if(!$cl_c){echo "<br /><br />Couldnt Find Any Clients with Custom Message [$del_esc]<br />";}
                                else{echo "<br /><br />Found [$cl_c] Clients with Custom Message [$del_esc].<br />";}
                                #remove Custom message
                                $del_stmt2 = $conn->prepare("DELETE FROM c_messages WHERE id = ?");
                                if($del_stmt2->execute([$del]))
                                {
                                    echo "Removed message [$del_esc].";
                                    ?>
                        <script>
                            setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=c_messages'",<?php echo $page_timeout;?>);
                        </script>
                                    <?php
                                }else
                                {
                                    echo "Failed to Remove message [$del_esc].<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                        }else
                        {
                            if(!@$_POST['body'])
                            {
                                ?>
                        <script>
                            setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=c_messages'",<?php echo $page_timeout;?>);
                        </script>
                                <?php
                                break;
                            }
                            foreach($_POST['body'] as $key=>$body)
                            {
                                $body = htmlentities($body, ENT_QUOTES);
                                $id = (int)$_POST['id'][$key];
                                $name = $_POST['name'][$key];
                                $wrapper = (int)$_POST['wrapper'][$key];
                                $stmt = $conn->prepare("UPDATE c_messages SET name = ?, body = ?, wrapper = ? WHERE id = ?");
                                $name_esc = htmlspecialchars((string)$name, ENT_QUOTES);
                                if($stmt->execute([$name, $body, $wrapper, $id]))
                                {
                                    echo "Updated message [$id] ($name_esc).<br/>";
                                    ?>
                        <script>
                            setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=c_messages'",<?php echo $page_timeout;?>);
                        </script>
                                    <?php
                                }else
                                {
                                    echo "Failed to Update message [$id] ($name_esc).<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                        }
                        break;
                    default:
                        ?>
                <script type="text/javascript">
                <!--
                function SetAllCheckBoxes(FormName, FieldName, CheckValue)
                {
                        if(!document.forms[FormName])
                                return;
                        var objCheckBoxes = document.forms[FormName].elements[FieldName];
                        if(!objCheckBoxes)
                                return;
                        var countCheckBoxes = objCheckBoxes.length;
                        if(!countCheckBoxes)
                                objCheckBoxes.checked = CheckValue;
                        else
                                // set the check value for all check boxes
                                for(var i = 0; i < countCheckBoxes; i++)
                                        objCheckBoxes[i].checked = CheckValue;
                }
                function expandcontract(tbodyid,ClickIcon)
                {
                        if (document.getElementById(ClickIcon).innerHTML == "+")
                        {
                                document.getElementById(tbodyid).style.display = "";
                                document.getElementById(ClickIcon).innerHTML = "-";
                        }else{
                                document.getElementById(tbodyid).style.display = "none";
                                document.getElementById(ClickIcon).innerHTML = "+";
                        }
                }
                // -->
                </script>
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <form name="save_new" action="?func=c_messages&mode=edit_messg" method="POST">
                        <th colspan="5">Custom Messages</th>
                    </tr>
                    <tr class="client_table_head">
                        <th colspan="5"><input type='submit' name="update_body" value="Update All Messages"></th>
                    </tr>
                    <tr class="client_table_head">
                        <th style="width:1px;">+/-</th><th>Name</th><th>Message URL</th><th width="1px">Options</th>
                    </tr>
                    <?php
                        $link = db_connect($server, $username, $password, $db, $driver);
                        $result = $link->query("SELECT * FROM c_messages ORDER by id ASC");
                        $cm_all = $result->fetchAll(PDO::FETCH_ASSOC);
                        if(count($cm_all))
                        {
                            $tablerowid=0;
                            foreach($cm_all as $links)
                            {
                                $cm_id = (int)$links['id'];
                                $cm_name = htmlspecialchars($links['name'], ENT_QUOTES);
                            ?>
                        <tr class="client_table_body">
                            <td onclick="expandcontract('mesgRow<?php echo $tablerowid;?>','mesgClickIcon<?php echo $tablerowid;?>')"
                                id="mesgClickIcon<?php echo $tablerowid;?>" style="cursor: pointer; cursor: hand;">+
                            </td>
                            <td style="width:25%;">
                                <input type="hidden" name="id[]" value="<?php echo $cm_id;?>"/>
                                <input type="text" name="name[]" style="width:90%;" value="<?php echo $cm_name;?>"/>
                            </td>
                            <td>
                                <a class="links" href="<?php echo  $reg_url;?>html/template.php?type=c_message&id=<?php echo $cm_id;?>" target="_blank"><?php echo $reg_url;?>html/template.php?type=c_message&id=<?php echo $cm_id;?></a>
                            </td>
                            <td style="width:1%;" align="center">
                                <input type="checkbox" name="remove_[]" value="<?php echo $cm_id;?>"/>
                            </td>
                        </tr>
                        <tbody id="mesgRow<?php echo $tablerowid;?>" style="display:none">
                        <tr>
                            <td colspan="5">
                                <textarea name="body[]" rows="10" style="width:90%"><?php echo $links['body'];?></textarea>
                                <br />
                                Use the UNS Wrapper? <input type="checkbox" name="wrapper[]" value="1" <?php if($links['wrapper']){echo "Checked";} ?>/>
                                <br />
                            </td>
                        </tr>
                        </tbody>
                            <?php
                                $tablerowid++;
                            }
                        }else
                        {
                        ?>
                        <tr>
                            <td align="center" colspan="5">
                                There are no custom messages yet.
                            </td>
                        </tr>
                        <?php
                        }
                        ?>
                    <tr class="client_table_tail">
                        <td align="center" colspan="3">
                        </td>
                        <td align="center">
                            <table>
                                <tr>
                                    <td align="center" valign="center">
                                        <input type='submit' name="remove" value='Remove'>
                                    </td>
                                    <td align="center" valign="center">
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', true);" value="Check"><br />
                                        <input type="button" onclick="SetAllCheckBoxes('save_new', 'remove_[]', false);" value="Uncheck">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="5" align="center">
                            <form name="save_new1" action="?func=c_messages&mode=add_messg" method="POST">
                            <table>
                                <tr>
                                    <td valign="center">
                                        Name:
                                    </td>
                                    <td>
                                        <input type="text" name="name_n" style="width:100%;" value="">
                                    </td>
                                </tr>
                                <tr>
                                    <td valign="center">
                                        Message:<br />
                                        <font size="1">In HTML</font>
                                    </td>
                                    <td>
                                        <textarea name="body_n" cols="100" rows="10">[Put Message Here]</textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center">
                                        Use the UNS Wrapper?
                                    </td>
                                    <td>
                                        <input type="checkbox" name="wrapper" value="1" Checked/>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center">
                                        <input type='submit' value='Add Message'>
                                    </td>
                                </tr>
                            </table>
                            </form>
                        </td>
                    </tr>
                </table>
                        <?php
                        break;
                }

            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "edit_urls":
            if($perms['edit_urls'])
            {
                $client_get = @filter_input(INPUT_GET, 'client', FILTER_SANITIZE_SPECIAL_CHARS);
                if(!$client_get)
                {
                    $result = $conn->query("SELECT client_name FROM allowed_clients");
                    $clients = array();
                    while($links = $result->fetch(PDO::FETCH_ASSOC))
                    {
                        $clients[] = $links['client_name'];
                    }
                    ?>
                    <table border="1px" width="100%">
                        <tr>
                            <th class="client_table_head"><font size="4">Edit Standard Message URLs for Clients</font></th>
                        </tr>
                        <tr>
                            <th class="client_table_head">Client Name</th>
                        </tr>
                        <?php
                    foreach($clients as $client)
                    {
                        $stmt1 = $conn->prepare("SELECT * FROM friendly WHERE client = ?");
                        $stmt1->execute([$client]);
                        $friendly = $stmt1->fetch(PDO::FETCH_ASSOC);
                        $client_esc = htmlspecialchars($client, ENT_QUOTES);
                        ?>
                            <tr class="client_table_body">
                                <td><a href="?func=edit_urls&client=<?php echo $client_esc;?>"><?php echo htmlspecialchars($friendly['friendly'] ?? '', ENT_QUOTES);?></a></td>
                            </tr>
                        <?php
                    }
                    ?>
                    </table>
                    <?php
                }else
                {
                    # $client_get becomes part of a dynamically-named "<client>_links" table
                    # below, which can't be parameterized in a prepared statement - reject
                    # anything that isn't a plain identifier before it's used that way.
                    if(!is_safe_client_id($client_get)){die("Invalid client.");}
                    $cl_func = filter_input(INPUT_GET, 'cl_func', FILTER_SANITIZE_SPECIAL_CHARS);
                    switch($cl_func)
                    {
                        case "copy2_proc":
                            foreach($_POST['copy_clients'] as $copy_client)
                            {
                                if(!is_safe_client_id($copy_client)){continue;}
                                $fail = 0;
                                $result = $conn->query("SELECT * FROM ".$copy_client."_links");
                                $links = array(); #get list of URLS from Client that you want to copy to

                                while($client_links = $result->fetch(PDO::FETCH_ASSOC))
                                {
                                    $links[] = $client_links['url']."~".$client_links['refresh'];
                                }
                                #lets get its friendly name
                                $stmt = $conn->prepare("SELECT friendly FROM friendly where client = ?");
                                $stmt->execute([$copy_client]);
                                $friendly = $stmt->fetch(PDO::FETCH_ASSOC);
                                $friend = $friendly['friendly'] ?? $copy_client;
                                $friend_esc = htmlspecialchars($friend, ENT_QUOTES);
                                if(!empty($links[0]))
                                {
                                    $name = "Backup of URLS for $friend on ".date("F j, Y \a\t g:i a");
                                    $imp_links = implode("||", $links);
                                    $now = time();
                                    $stmt = $conn->prepare("INSERT INTO archive_links (client, urls, name, details, date) VALUES (?, ?, ?, 'Automated backup.', ?)");
                                    if($stmt->execute([$copy_client, $imp_links, $name, $now]))
                                    {
                                        echo "URLs for Client: $friend_esc have been backed up.<br /><br />\r\n";
                                    }else
                                    {
                                        echo "URLs for Client: $friend_esc have <u><b>NOT</b></u> been backed up.<br /><br />\r\n";
                                        $fail = 1;
                                    }
                                }else
                                {
                                    echo "Client: $friend_esc Does not have any URLs yet.<br /><br />";
                                }
                                if(!$fail)
                                {
                                    $ids = explode("|", $_POST['urls']);
                                    if(!db_truncate_table($conn, $driver, $copy_client."_links")){echo "Error Truncating table<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);}
                                    foreach($ids as $id)
                                    {
                                        $id_esc = htmlspecialchars($id, ENT_QUOTES);
                                        echo "Start Copy of ID: $id_esc for Client: $friend_esc<br />";
                                        $stmt = $conn->prepare("SELECT * FROM ".$client_get."_links where id = ?");
                                        $stmt->execute([$id]);
                                        $copy_link = $stmt->fetch(PDO::FETCH_ASSOC);
                                        if($copy_link)
                                        {
                                            $ins_stmt = $conn->prepare("INSERT INTO ".$copy_client."_links (url, disabled, refresh) VALUES (?, 0, ?)");
                                        }
                                        if(!$copy_link || !$ins_stmt->execute([$copy_link['url'], $copy_link['refresh']]))
                                        {
                                            echo "Failed to copy URL [$id_esc] to client: $friend_esc.<br /><br />";
                                        }else
                                        {
                                            echo "Copied URL [$id_esc] to Client: $friend_esc.<br /><br />";
                                        }
                                    }
                                }else
                                {
                                    echo "URLs for Client: $friend_esc have <u><b>NOT</b></u> been copied.<br /><br />\r\n";
                                }
                                ?>
                   <script>
                        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                                <?php
                                echo "---------------<br />";
                            }
                            break;
                        case "edit_proc":
                            # Copy / Save To List / Remove all act on the ticked boxes in the Select
                            # column. With nothing ticked $_POST['urls'] is absent, which makes the
                            # implode() calls below raise a TypeError on PHP 8 - and Save To List
                            # went on to store an empty list that looked blank when expanded.
                            if((@$_POST['copy2'] || @$_POST['save_list'] || @$_POST['remove'])
                                && (empty($_POST['urls']) || !is_array($_POST['urls'])))
                            {
                                echo "No URLs were selected. Tick the boxes in the <b>Select</b> column first, then press the button.<br />";
                                ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",3000);
                    </script>
                                <?php
                                break;
                            }
                            if(@$_POST['copy2'])
                                {
                                    ?>
                    <form name="client_copy" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=copy2_proc" method="POST">
                    <table>
                        <tr>
                            <th>Choose Clients to Copy URLs to:</th>
                        </tr>
                        <tr>
                            <td>
                                <select name="copy_clients[]" style="width:100%;" size="10" multiple="multiple">
                            <?php
                            $stmt = $conn->prepare("SELECT * FROM friendly where client != ?");
                            $stmt->execute([$client_get]);
                            $result = $stmt;
                            while($all_clients = $result->fetch(PDO::FETCH_ASSOC))
                            {
                                ?><option value="<?php echo htmlspecialchars($all_clients['client'], ENT_QUOTES);?>"><?php echo htmlspecialchars($all_clients['friendly'], ENT_QUOTES);?></option><?php
                            }
                            $urls_imp = htmlspecialchars(implode("|", $_POST['urls']), ENT_QUOTES);
                            ?>
                                </select>
                                <input type="hidden" name="urls" value="<?php echo $urls_imp; ?>">
                            </td>
                        </tr>
                        <tr>
                            <td align="center">
                                <input type='submit' name="submit" value='submit'>
                            </td>
                        </tr>
                    </table>
                    </form>
                            <?php
                                }
                                if(@$_POST['save_list'])
                                {
                                    $urls_imp = implode("|", $_POST['urls']);
                                    ?>
                    <form name="save_new" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=save_new" method="POST">
                    <table>
                        <tr>
                            <th>Save to List:</th>
                        </tr>
                        <tr>
                            <td valign="center">
                                Name:
                            </td>
                            <td>
                                <input type="text" name="name" value="">
                                <input type="hidden" name="urls" value="<?php echo $urls_imp; ?>">
                            </td>
                        </tr>
                        <tr>
                            <td valign="center">
                                Details:
                            </td>
                            <td>
                                <textarea name="details" cols="40" rows="10"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td align="center">
                                <input type='submit' name="submit" value='submit'>
                            </td>
                        </tr>
                    </table>
                        <hr />
                    </form>
                    <form name="save_append" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=save_append" method="POST">
                    <table>
                        <tr>
                            <th>Append to List:</th>
                        </tr>
                        <tr>
                            <td>
                                <select name="saved" style="width:100%;" size="10">
                            <?php
                            $result = $conn->query("SELECT * FROM saved_lists");
                            while($all_clients = $result->fetch(PDO::FETCH_ASSOC))
                            {
                                ?><option value="<?php echo (int)$all_clients['id'];?>"><?php echo htmlspecialchars($all_clients['name'], ENT_QUOTES);?></option><?php
                            }
                            $urls_imp = htmlspecialchars(implode("|", $_POST['urls']), ENT_QUOTES);
                            ?>
                                </select>
                                <input type="hidden" name="urls" value="<?php echo $urls_imp; ?>">
                            </td>
                        </tr>
                        <tr>
                            <td align="center">
                                <input type='submit' name="submit" value='submit'>
                            </td>
                        </tr>
                    </table>
                    </form>
                            <?php
                                }
                                if(@$_POST['remove'])
                                {
                                    $urls = array();
                                    $freindly = htmlspecialchars(gen_friendly($client_get), ENT_QUOTES);
                                    $remove_urls = is_array($_POST['urls']) ? $_POST['urls'] : array($_POST['urls']);
                                    foreach($remove_urls as $url)
                                    {
                                        $url_esc = htmlspecialchars((string)$url, ENT_QUOTES);
                                        $stmt = $conn->prepare("SELECT * FROM ".$client_get."_links WHERE id = ?");
                                        $stmt->execute([$url]);
                                        $link = $stmt->fetch(PDO::FETCH_ASSOC);
                                        if(!$link)
                                        {
                                            echo "URL Does not Exsist any more.<br />";
                                            continue;
                                        }
                                        $del_stmt = $conn->prepare("DELETE FROM ".$client_get."_links WHERE id = ?");
                                        if($del_stmt->execute([$url]))
                                        {
                                            echo "Removed Link [$url_esc] from ($freindly)'s list.<br />";
                                            $urls[] = $link['url']."~".$link['refresh'];
                                        }else
                                        {
                                            echo "Failed to Remove Link [$url_esc] from ($freindly)'s list.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                        }
                                    }
                                    if(empty($urls))
                                    {
                                        echo "No URLS were deleted, none to back up.<br />";
                                        ?>
                   <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                                        <?php
                                    }else
                                    {
                                        $url_imp = implode("|", $urls);
                                        $time = time();
                                        $name = "Automated Backup on ".date("F j, Y, g:i a");
                                        $details = "Automated Backup of removed URLs $client_get";
                                        $stmt = $conn->prepare("INSERT INTO archive_links (client, urls, name, details, date) VALUES (?, ?, ?, ?, ?)");
                                        if($stmt->execute([$client_get, $url_imp, $name, $details, $time]))
                                        {
                                            echo "Backed up Links for ($client_get).";
                                    ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                                    <?php
                                        }else
                                        {
                                            echo "Failed to Back up Links for ($client_get).<br />\r\n".db_error($conn);
                                        }
                                    }
                                }
                                if($_POST['refresh'])
                                {
                                    $URLid = $_POST['URLid'];
                                    $refresh = $_POST['refresh_time'];
                                    foreach($URLid as $key=>$id)
                                    {
                                        $id_esc = htmlspecialchars((string)$id, ENT_QUOTES);
                                        $r_val = (int)$refresh[$key];
                                        $stmt = $conn->prepare("UPDATE ".$client_get."_links set refresh = ? WHERE id = ?");
                                        if($stmt->execute([$r_val, $id]))
                                        {
                                            echo "Updated URL [$id_esc] Refresh Time on Client ($client_get).<br />\r\n";
                                        }else
                                        {
                                            echo "Failed to update URL [$id_esc] status on Client ($client_get).<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                        }
                                    }
                                    ?>
                                <script>
                                    setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                                </script>
                                    <?php
                                }
                            break;
                        case "add_url_batch":
                            $urls = (string)filter_input(INPUT_POST, 'URLS', FILTER_SANITIZE_SPECIAL_CHARS);
                            $refresh = (int)filter_input(INPUT_POST, 'refresh', FILTER_SANITIZE_SPECIAL_CHARS);
                            # FILTER_SANITIZE_SPECIAL_CHARS turns the textarea's line breaks into
                            # &#13;&#10; entities. Decode them back first, then split on any line
                            # ending: the old explode() matched the CRLF pair literally, so a browser
                            # submitting bare LF would have stored the whole box as a single URL.
                            # Decoding also stops URLs containing "&" being stored double-encoded.
                            $url_exp = preg_split('/\r\n|\r|\n/', html_entity_decode($urls, ENT_QUOTES));
                            $i=0;
                            $skipped=0;
                            foreach($url_exp as $url_)
                            {
                                $url_ = trim($url_);
                                # Blank lines, and the bare scheme this form is pre-filled with, were
                                # previously inserted as rows. That is where the empty entry in the
                                # client's URL list came from - and, once ticked, in saved lists too.
                                if($url_ === '' || $url_ === 'http://' || $url_ === 'https://')
                                {
                                    $skipped++;
                                    continue;
                                }
                                $stmt = $conn->prepare("INSERT INTO ".$client_get."_links (url, disabled, refresh) VALUES (?, 0, ?)");
                                if($stmt->execute([$url_, $refresh]))
                                {
                                    echo "Added: ".htmlspecialchars($url_, ENT_QUOTES)."<br />\r\n";
                                    $i++;
                                }else
                                {
                                    echo "Failed to add URL....<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                            $stmt = $conn->prepare("SELECT friendly FROM friendly WHERE client = ?");
                            $stmt->execute([$client_get]);
                            $friendly = $stmt->fetch(PDO::FETCH_ASSOC);
                            if($i > 0)
                            {
                                echo "Added ($i) New URL for Client. (".htmlspecialchars($friendly['friendly'] ?? '', ENT_QUOTES).")<br />";
                                if($skipped > 0){echo "Skipped ($skipped) blank line(s).<br />";}
                                ?>
                    <script>
                        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                                <?php
                            }else
                            {
                                echo "None Passed.... :-(";
                            }
                            break;
                        case "save_new":
                            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS);
                            $url_imp = filter_input(INPUT_POST, 'urls', FILTER_SANITIZE_SPECIAL_CHARS);
                            $details = filter_input(INPUT_POST, 'details', FILTER_SANITIZE_SPECIAL_CHARS);
                            $url_exp = explode("|", $url_imp);
                            $links = array();
                            foreach($url_exp as $id)
                            {
                                $stmt = $conn->prepare("SELECT * FROM ".$client_get."_links WHERE id = ?");
                                $stmt->execute([$id]);
                                $link = $stmt->fetch(PDO::FETCH_ASSOC);
                                if($link){$links[] = $link['url'].'~'.$link['refresh'];}
                            }
                            $urls_imp = implode("|", $links);
                            $time = time();
                            $stmt = $conn->prepare("INSERT INTO saved_lists (urls, name, details, date) VALUES (?, ?, ?, ?)");
                            if($stmt->execute([$urls_imp, $name, $details, $time]))
                            {
                                echo "Saved List. (".htmlspecialchars((string)$name, ENT_QUOTES).")";
                                ?>
                    <script>
                        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                                <?php
                            }else
                            {
                                echo "Failed to save list....<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                            }
                            break;
                        case "save_append":
                            $urls_imp = filter_input(INPUT_POST, 'urls', FILTER_SANITIZE_SPECIAL_CHARS);
                            $saved = (int)filter_input(INPUT_POST, 'saved', FILTER_SANITIZE_SPECIAL_CHARS);

                            $stmt = $conn->prepare("SELECT * FROM saved_lists WHERE id = ?");
                            $stmt->execute([$saved]);
                            $saved_array = $stmt->fetch(PDO::FETCH_ASSOC);

                            $urls_imp = ($saved_array['urls'] ?? '')."|".$urls_imp;
                            $time = time();
                            $stmt = $conn->prepare("UPDATE saved_lists SET urls= ?, date= ? WHERE id = ?");
                            if($stmt->execute([$urls_imp, $time, $saved]))
                            {
                                echo "Updated List.";
                                ?>
                    <script>
                        setTimeout("location.href = ' $proto.<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                                <?php
                            }else
                            {
                                echo "Failed to update list.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                            }
                            break;
                        case "restore":
                            check_archives($client_get);
                            $urls_imp = filter_input(INPUT_POST, 'urls', FILTER_SANITIZE_SPECIAL_CHARS);
                            $url_exp = explode("|", $urls_imp);
                            $urls = array();
                            $friendly = htmlspecialchars(gen_friendly($client_get), ENT_QUOTES);
                            $result = $conn->query("SELECT * FROM ".$client_get."_links");
                            while($link = $result->fetch(PDO::FETCH_ASSOC))
                            {
                                $del_stmt = $conn->prepare("DELETE FROM ".$client_get."_links WHERE id = ?");
                                if($del_stmt->execute([$link['id']]))
                                {
                                    echo "Removed Link [".(int)$link['id']."] from ($friendly)'s list.<br />";
                                    $urls[] = $link['url']."~".$link['refresh'];
                                }else
                                {
                                    echo "Failed to Remove Link [".(int)$link['id']."] from ($friendly)'s list.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                            if(!empty($urls))
                            {
                                $url_imp = implode("|", $urls);
                                $time = time();
                                $name = "Automated Backup on ".date("F j, Y, g:i a");
                                $details = "Automated Backup of removed URLs $friendly";
                                $stmt = $conn->prepare("INSERT INTO archive_links (client, urls, name, details, date) VALUES (?, ?, ?, ?, ?)");
                                if($stmt->execute([$client_get, $url_imp, $name, $details, $time]))
                                {
                                    echo "Backed up Links for ($friendly).";
                                }else
                                {
                                    echo "Failed to Back up Links for ($friendly).<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                    $fail = 1;
                                }
                            }else
                            {
                                echo "No need to archive, no URLs for client.";
                            }
                            if(!@$fail)
                            {
                                if(db_truncate_table($conn, $driver, $client_get."_links"))
                                {
                                    foreach($url_exp as $data)
                                    {
                                        $data_exp = explode("~", $data);
                                        $url = $data_exp[0];
                                        $refresh = (int)($data_exp[1] ?? 0);
                                        $stmt = $conn->prepare("INSERT INTO ".$client_get."_links (url,disabled, refresh) VALUES (?, 0, ?)");
                                        if($stmt->execute([$url, $refresh]))
                                        {
                                            echo "Added URL<br />\r\n";
                                        }else
                                        {
                                            echo "Failed to Add URL<br />\r\n";
                                        }
                                    }
                                    ?>
                    <script>
                        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                                    <?php
                                }else
                                {
                                    echo "Failed to truncate.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                            break;
                        case "remove":
                            $id = (int)filter_input(INPUT_POST, 'id', FILTER_SANITIZE_SPECIAL_CHARS);
                            $stmt = $conn->prepare("DELETE FROM saved_lists WHERE id = ?");
                            if($stmt->execute([$id]))
                            {
                                echo "Removed Saved List";
                               ?>
            <script>
                setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
            </script>
                            <?php
                            }else
                            {
                                echo "Failed to Removed Saved List.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                            }
                            break;
                        default:
                             echo "ummmm....... O_o";
                            break;
                    }
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "edit_emerg":
            if($perms['edit_emerg'])
            {
                ?>
                <script type="text/javascript">
                function SetAllCheckBoxes(FormName, FieldName, CheckValue)
                {
                        if(!document.forms[FormName])
                                return;
                        var objCheckBoxes = document.forms[FormName].elements[FieldName];
                        if(!objCheckBoxes)
                                return;
                        var countCheckBoxes = objCheckBoxes.length;
                        if(!countCheckBoxes)
                                objCheckBoxes.checked = CheckValue;
                        else
                                // set the check value for all check boxes
                                for(var i = 0; i < countCheckBoxes; i++)
                                        objCheckBoxes[i].checked = CheckValue;
                }
                </script>
                    <?php
                    $result = $conn->query("SELECT emerg FROM settings");
                    $settings = $result->fetch(PDO::FETCH_ASSOC);
                    ?>
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th colspan="6">
                            <form name="emerg_toggle" action="?func=emerg_set" method="POST">
                                <input type="hidden" name="toggle" value="<?php if(!$settings['emerg']){echo '1';}else{ echo '0';}?>">
                                <input type="submit" style="font-size:18;" value="<?php if(!$settings['emerg']){echo 'Enable';}else{ echo 'Disable';}?> Global Emergency Messages?">
                                <br /><font size="4">This will disable normal messages on all Clients.</font>
                            </form>
                        </th>
                    </tr>
                </table>
                <hr />
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th colspan="6">
                            Edit Emergency Messages for all Clients
                        </th>
                    </tr>
                    <tr class="client_table_head">
                        <th width="50px">Enabled?</th><th width="700px">URL</th><th width="90px">Refresh Time</th><th width="90px">Options</th>
                    </tr>
                    <?php
                    $link = db_connect($server, $username, $password, $db, $driver);
                    $result1 = $link->query("SELECT * FROM emerg");
                    $emerg_all = $result1->fetchAll(PDO::FETCH_ASSOC);
                    if(count($emerg_all) > 0)
                    {
                        ?><form name="client_edit" action="?func=update_emerg" method="POST"><?php
                        foreach($emerg_all as $emerg_links)
                        {
                            $emerg_url_esc = htmlspecialchars($emerg_links['url'], ENT_QUOTES);
                            ?>
                    <tr class="client_table_body">
                        <td align="center">
                            <?php if($emerg_links['enabled']){echo "&#x2713;";}else{echo "&#x2717;";}?>
                        </td>
                        <td>
                            <?php
                            echo '<a class="links" href="'.$emerg_url_esc.'" target="_blank">'.$emerg_url_esc.'</a>';
                            $parse_url = parse_url($emerg_links['url']);
                            if(str_replace("/","",$host) == ($parse_url['host'] ?? null))
                            {
                                $exp_url = explode("?", html_entity_decode($emerg_links['url']));
                                $query_url = html_entity_decode($exp_url[1] ?? '');

                                $query_ = array();
                                $exp = explode('&',$query_url);
                                foreach($exp as $e)
                                {
                                    $qur = explode("=", $e);
                                    $query_[$qur[0]] = $qur[1] ?? '';
                                }
                                $id = (int)($query_['id'] ?? 0);
                                switch($query_['type'] ?? '')
                                {
                                    case "rss":
                                        $stmt2 = $link->prepare("SELECT * FROM rss_feeds WHERE id = ?");
                                        $stmt2->execute([$id]);
                                        $rss = $stmt2->fetch(PDO::FETCH_ASSOC);
                                        if($rss){echo " (".htmlspecialchars($rss['name'], ENT_QUOTES).")";}
                                        break;
                                    case "c_message":
                                        $stmt2 = $link->prepare("SELECT * FROM c_messages WHERE id = ?");
                                        $stmt2->execute([$id]);
                                        $c_mesg = $stmt2->fetch(PDO::FETCH_ASSOC);
                                        if($c_mesg){echo " (".htmlspecialchars($c_mesg['name'], ENT_QUOTES).")";}
                                        break;
                                }
                            }
                            ?>
                        </td>
                        <td align="center">
                                <input type="hidden" name="url_id[]" value="<?php echo (int)$emerg_links['id'];?>">
                                <input type="text" name="refresh_t[]" style="width: 49px" value="<?php echo (int)$emerg_links['refresh'];?>">
                        </td>
                        <td align="center">
                                <input type="hidden" name="url_t[]" value="<?php if($emerg_links['enabled']){echo "0";}else{echo "1";}?>">
                                <input type="checkbox" name="urls[]" value="<?php echo (int)$emerg_links['id'];?>">
                        </td>
                    </tr>
                        <?php
                        }
                    }else
                    {
                        ?>
                    <tr>
                        <td align="center" colspan="5">
                            No URLS, add some.
                        </td>
                    </tr>
                        <?php
                    }
                    ?>
                    <tr class="client_table_tail">
                        <td colspan="2"></td>
                        <td align="center">
                            <input type="submit" name="refresh" value="Update">
                        </td>
                        <td>
                            <table align="center">
                                <tr>
                                    <td align="center">
                                        <input type="submit" name="delete" value="Delete"><br />
                                        <input type="submit" name="toggle" value="Enable/Disable">
                                    </td>
                                    <td align="center">
                                        <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', true);" value="Check"><br />
                                        <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', false);" value="Uncheck">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="6">
                            <form name="save_new" action="?func=add_emerg" method="POST">
                            <table>
                                <tr>
                                    <td valign="center">
                                        URLs:
                                    </td>
                                    <td>
                                        <textarea name="URLS" cols="80" rows="10">http://</textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <td valign="center">
                                        Refresh Times for all:
                                    </td>
                                    <td>
                                        <input type="text" name="refresh" value="<?php echo $refresh; ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td>
                                        <input type='submit' value='Add URLs'>
                                    </td>
                                </tr>
                            </table>
                            </form>
                        </td>
                    </tr>
                </table>
                <?php
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "emerg_set":
            if($perms['edit_emerg'])
            {
                $toggle = (int)filter_input(INPUT_POST, 'toggle', FILTER_SANITIZE_SPECIAL_CHARS);
                $stmt = $conn->prepare("UPDATE settings set emerg = ? WHERE id = 1");
                if($stmt->execute([$toggle]))
                {
                    if($led_blink){emerg_blink($toggle);}
                    if($toggle){echo "Enabled";}else{echo "Disabled";}
                    echo " Global Emergency Messages";
                    ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=edit_emerg'",<?php echo $page_timeout;?>);
                    </script>
                    <?php
                }else
                {
                    echo "Failed to ";
                    if($toggle){echo "Enabled";}else{echo "Disabled";}
                    echo "Global Emergency Messages<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "update_emerg":
            if($perms['edit_emerg'])
            {
                if(@$_POST['toggle'] === 'Enable/Disable')
                {
                    if(!@$_POST['urls'])
                    {
                        ?>
                        <script>
                            setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=edit_emerg'",<?php echo $page_timeout;?>);
                        </script>
                        <?php
                        break;
                    }
                    foreach($_POST['urls'] as $key=>$id)
                    {
                        $id = (int)$id;
                        $url_t = (int)$_POST['url_t'][$key];
                        $stmt = $conn->prepare("UPDATE emerg set enabled = ? WHERE id = ?");
                        if($stmt->execute([$url_t, $id]))
                        {
                            echo "Updated URL [$id].<br />";
                            ?>
                            <script>
                                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=edit_emerg'",<?php echo $page_timeout;?>);
                            </script>
                            <?php
                        }else
                        {
                            echo "Failed to updated URL [$id].<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                    }
                }elseif(@$_POST['delete'] === 'Delete')
                {
                    if(!@$_POST['urls'])
                    {
                        ?>
                        <script>
                            setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=edit_emerg'",<?php echo $page_timeout;?>);
                        </script>
                        <?php
                        break;
                    }
                    foreach($_POST['urls'] as $key=>$id)
                    {
                        $id = (int)$id;
                        $stmt = $conn->prepare("DELETE FROM emerg WHERE id = ?");
                        if($stmt->execute([$id]))
                        {
                            echo "Removed [$id].<br />";
                            ?>
                            <script>
                                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=edit_emerg'",<?php echo $page_timeout;?>);
                            </script>
                            <?php
                        }else
                        {
                            echo "Failed to Remove [$id].<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                    }
                }elseif(@$_POST['refresh'] === 'Update')
                {
                    foreach($_POST['url_id'] as $key=>$id)
                    {
                        $id = (int)$id;
                        $refresh = (int)$_POST['refresh_t'][$key];
                        $stmt = $conn->prepare("UPDATE emerg set refresh = ? WHERE id = ?");
                        if($stmt->execute([$refresh, $id]))
                        {
                            echo "Updated URL [$id] Refresh Time.";
                            ?>
                            <script>
                                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=edit_emerg'",<?php echo $page_timeout;?>);
                            </script>
                            <?php
                        }else
                        {
                            echo "Failed to updated URL [$id] status<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                    }
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "add_emerg":
            if($perms['edit_emerg'])
            {
                $urls = filter_input(INPUT_POST, 'URLS', FILTER_SANITIZE_SPECIAL_CHARS);
                $refresh = (int)filter_input(INPUT_POST, 'refresh', FILTER_SANITIZE_SPECIAL_CHARS);
                $url_exp = explode("&#13;&#10;", $urls);
                $i=0;
                foreach($url_exp as $url_)
                {
                    $url_ = trim($url_);
                    $stmt = $conn->prepare("INSERT INTO emerg (url, enabled, refresh) VALUES (?, 1, ?)");
                    if($stmt->execute([$url_, $refresh]))
                    {
                        echo "Added: ".htmlspecialchars($url_, ENT_QUOTES)."<br />\r\n";
                        $i++;
                    }else
                    {
                        echo "Failed to add URL....<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }
                if($i > 0)
                {
                    echo "Added ($i) New Emergency URL's<br />";
                    ?>
        <script>
            setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=edit_emerg'",<?php echo $page_timeout;?>);
        </script>
                    <?php
                }else
                {
                    echo "None Passed.... :-(";
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "rename_client":
            if($perms['edit_urls'])
            {
                $client_get = filter_input(INPUT_GET, 'client', FILTER_SANITIZE_SPECIAL_CHARS);
                $client_name = filter_input(INPUT_POST, 'client_name', FILTER_SANITIZE_SPECIAL_CHARS);
                $client_id = (int)filter_input(INPUT_POST, 'client_id', FILTER_SANITIZE_SPECIAL_CHARS);
                $stmt = $conn->prepare("UPDATE friendly SET friendly = ? WHERE id = ?");
                if($stmt->execute([$client_name, $client_id]))
                {
                    echo "Renamed Client [$client_id] ".htmlspecialchars((string)$client_name, ENT_QUOTES).".";
                    ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                    <?php
                }else
                {
                    echo "Failed to Rename Client [$client_id]<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "client_led_set":
            $cl_id = filter_input(INPUT_POST, 'cl_id', FILTER_SANITIZE_SPECIAL_CHARS);
            $led_id = (int)filter_input(INPUT_POST, 'cl_led_id', FILTER_SANITIZE_SPECIAL_CHARS);
            $stmt = $conn->prepare("UPDATE allowed_clients SET led = ? WHERE client_name = ?");
            if($stmt->execute([$led_id, $cl_id]))
            {
                echo "Updated LED Group to #$led_id<br/>";
                ?>
                <script>
                    setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_client&client=<?php echo $cl_id;?>'",<?php echo $page_timeout;?>);
                </script>
                <?php
            }else
            {
                echo "failed update<br/>";
            }
            break;
        case "view_client":
            if($perms['edit_urls'])
            {
                $client_get = filter_input(INPUT_GET, 'client', FILTER_SANITIZE_SPECIAL_CHARS);
                if(!is_safe_client_id($client_get)){die("Invalid client.");}
                ?>
                <script type="text/javascript">
                function SetAllCheckBoxes(FormName, FieldName, CheckValue)
                {
                        if(!document.forms[FormName])
                                return;
                        var objCheckBoxes = document.forms[FormName].elements[FieldName];
                        if(!objCheckBoxes)
                                return;
                        var countCheckBoxes = objCheckBoxes.length;
                        if(!countCheckBoxes)
                                objCheckBoxes.checked = CheckValue;
                        else
                                // set the check value for all check boxes
                                for(var i = 0; i < countCheckBoxes; i++)
                                        objCheckBoxes[i].checked = CheckValue;
                }
                function expandcontract(tbodyid,ClickIcon)
                {
                        if (document.getElementById(ClickIcon).innerHTML == "+")
                        {
                                document.getElementById(tbodyid).style.display = "";
                                document.getElementById(ClickIcon).innerHTML = "-";
                        }else{
                                document.getElementById(tbodyid).style.display = "none";
                                document.getElementById(ClickIcon).innerHTML = "+";
                        }
                }
                </script>
                <?php
                $stmt = $conn->prepare("SELECT * FROM friendly WHERE client = ?");
                $stmt->execute([$client_get]);
                $friendly = $stmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <table border="1px" align="center">
                    <tr valign="center" class="client_table_head">
                        <td>
                            Client Name:
                        </td>
                        <td>
                            <table width="100%">
                                <tr>
                                    <td width="80%">
                                    <br />
                                        <form name="client_rename" action="?func=rename_client&client=<?php echo $client_get;?>" method="POST">
                                            <input type="text" name="client_name" style="width:400px;" value="<?php echo htmlspecialchars($friendly['friendly'] ?? '', ENT_QUOTES); ?>"/>
                                            <input type="hidden" name="client_id" value="<?php echo (int)($friendly['id'] ?? 0); ?>"/>
                                            <input type="submit" value="Rename"/>
                                        </form>
                                    </td>
                                    <td>
                                        <?php
                                        if($led_blink)
                                        {
                                            $stmt = $conn->prepare("SELECT led FROM allowed_clients WHERE client_name = ?");
                                            $stmt->execute([$client_get]);
                                            $led = $stmt->fetch(PDO::FETCH_ASSOC);
                                        ?>
                                        LED Group:<br/>
                                        <form name="client_led" action="?func=client_led_set" method="POST">
                                            <input type="hidden" name="cl_id" value="<?php echo $client_get; ?>"/>
                                            <select name="cl_led_id" onchange='this.form.submit()'>
                                                <option value="1" <?php if($led['led'] == '1')echo "selected='yes'"; ?>>LED 1</option>
                                                <option value="2" <?php if($led['led'] == '2')echo "selected='yes'"; ?>>LED 2</option>
                                                <option value="3" <?php if($led['led'] == '3')echo "selected='yes'"; ?>>LED 3</option>
                                                <option value="4" <?php if($led['led'] == '4')echo "selected='yes'"; ?>>LED 4</option>
                                                <option value="5" <?php if($led['led'] == '5')echo "selected='yes'"; ?>>LED 5</option>
                                                <option value="6" <?php if($led['led'] == '6')echo "selected='yes'"; ?>>LED 6</option>
                                            </select>
                                        </form>
                                        <?php
                                        }
                                        ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr class="client_table_body">
                        <td>
                            Client URL:
                        </td>
                        <td>
                            <a class="links" href="<?php echo $reg_url.'index.php?id='.$friendly['client'];?>" target="_blank"><?php echo $reg_url.'index.php?id='.$friendly['client'];?></a>
                        </td>
                    </tr>
                </table>
                <hr />
                <form name="client_edit" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=edit_proc" method="POST">
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th colspan="4">
                            Messages
                        </th>
                    </tr>
                    <tr class="client_table_head">
                        <th>URL</th><th>Set Refresh</th><th width="120px">Select</th>
                    </tr>
                    <?php
                    $link = db_connect($server, $username, $password, $db, $driver);
                    $result1 = $link->query("SELECT * FROM ".$client_get."_links ORDER BY url ASC");
                    $client_links_all = $result1->fetchAll(PDO::FETCH_ASSOC);
                    if(count($client_links_all) > 0)
                    {
                        foreach($client_links_all as $links)
                        {
                            $link_url_esc = htmlspecialchars($links['url'], ENT_QUOTES);
                            ?>
                    <tr class="client_table_body">
                        <td>
                            <?php
                            echo '<a class="links" href="'.$link_url_esc.'" target="_blank">'.$link_url_esc.'</a>';
                            $parse_url = parse_url($links['url']);
                            if(str_replace("/","",$host) == ($parse_url['host'] ?? null))
                            {
                                $exp_url = explode("?", html_entity_decode($links['url']));
                                $query_url = $exp_url[1] ?? '';
                                $query_ = array();
                                $exp = explode('&',$query_url);
                                foreach($exp as $e)
                                {
                                    $qur = explode("=", $e);
                                    $query_[$qur[0]] = $qur[1] ?? '';
                                }
                                $id = (int)($query_['id'] ?? 0);

                                switch($query_['type'] ?? '')
                                {
                                    case "rss":
                                        $stmt2 = $link->prepare("SELECT * FROM rss_feeds WHERE id = ?");
                                        $stmt2->execute([$id]);
                                        $rss = $stmt2->fetch(PDO::FETCH_ASSOC);
                                        if($rss){echo " (".htmlspecialchars($rss['name'], ENT_QUOTES).")";}
                                        break;
                                    case "c_message":
                                        $stmt2 = $link->prepare("SELECT * FROM c_messages WHERE id = ?");
                                        $stmt2->execute([$id]);
                                        $c_mesg = $stmt2->fetch(PDO::FETCH_ASSOC);
                                        if($c_mesg){echo " (".htmlspecialchars($c_mesg['name'], ENT_QUOTES).")";}
                                        break;
                                }
                            }
                            ?>
                        </td>
                        <td align="center">
                            <input type='text' style="width:45px;" name="refresh_time[]" value='<?php echo (int)$links['refresh'];?>'>
                            <input type="hidden" name="URLid[]" value="<?php echo (int)$links['id'];?>">
                        </td>
                        <th><input type="checkbox" name="urls[]" value="<?php echo (int)$links['id'];?>"></th>

                    </tr>
                            <?php
                        }
                    }else
                    {
                        ?>
                    <tr class="client_table_body">
                        <td align="center" colspan="4">There are no URLs added yet.</td>
                    </tr>
                        <?php
                    }
                    ?>
                    <tr class="client_table_tail">
                        <td align="center">
                            <input type='submit' name="copy2" value='Copy'>
                            <input type='submit' name="save_list" value='Save To List'>
                            <input type='submit' name="remove" value='Remove'>
                        </td>
                        <td align="center">
                            <input type='submit' name="refresh" value='Set all'>
                        </td>
                        <td align="center">
                            <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', true);" value="Check">
                            <input type="button" onclick="SetAllCheckBoxes('client_edit', 'urls[]', false);" value="Uncheck">
                        </td>
                    </tr>
                    </form>
                    <tr class="client_table_tail">
                        <td colspan="4">
                            <form name="save_new" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=add_url_batch" method="POST">
                            <table style="width: 100%">
                                <tr>
                                    <td style="width: 200px" valign="center">
                                        URLs:
                                    </td>
                                    <td>
                                        <textarea name="URLS" rows="10" style="border:1px; solid #999999; width:90%; margin:5px 0; padding:3px;">http://</textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <td valign="center">
                                        Refresh Times for all:
                                    </td>
                                    <td>
                                        <input type="text" name="refresh" value="<?php echo $refresh; ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                    </td>
                                    <td>
                                        <input type='submit' value='Add URLs'>
                                    </td>
                                </tr>
                            </table>
                            </form>
                        </td>
                    </tr>
                </table>
                <hr />
                <table border="1px" class="all_tables">
                    <tr class="client_table_head">
                        <th colspan="5">Saved Lists</th>
                    </tr>
                    <tr class="client_table_head">
                        <th>+/-</th><th>Name</th><th>Date</th><th>Options</th>
                    </tr>
                <?php
                $result = $conn->query("SELECT * FROM saved_lists ORDER by id DESC");
                $tablerowid = 0;
                while($client_arc = $result->fetch(PDO::FETCH_ASSOC))
                {
                    $arc_urls_esc = htmlspecialchars($client_arc['urls'], ENT_QUOTES);
                    ?>
                    <tr class="client_table_body">
                        <td
                            onclick="expandcontract('SavedRow<?php echo $tablerowid;?>','SavedClickIcon<?php echo $tablerowid;?>')"
                            id="SavedClickIcon<?php echo $tablerowid;?>" style="cursor: pointer; cursor: hand;">+</td>
                        <td>
                            <?php echo htmlspecialchars($client_arc['name'], ENT_QUOTES);?>
                        </td>
                        <td>
                            <?php echo date('F j, Y, g:i a', $client_arc['date']);?>
                        </td>
                        <td>
                            <table>
                                <tr>
                                    <td>
                                        <form name="saved" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=restore" method="POST">
                                            <input type="hidden" name="urls" value="<?php echo $arc_urls_esc; ?>">
                                            <input type='submit' value='Restore'>
                                        </form>
                                    </td>
                                    <td>
                                        <form name="saved" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=remove" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$client_arc['id']; ?>">
                                            <input type='submit' value='Remove'>
                                        </form>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <?php
                    $exp = explode("|", $client_arc['urls']);
                    ?>
                    <tbody id="SavedRow<?php echo $tablerowid;?>" style="display:none">
                        <tr>
                            <td colspan="4">
                                <table border="1" width="100%">
                        <?php
                        # Entries are stored as "url~refresh", so show the two parts in their own
                        # columns rather than printing the raw separator. An empty list would
                        # previously render as one blank row with no explanation.
                        $shown = 0;
                        foreach($exp as $url)
                        {
                            $url = trim($url);
                            if($url === ''){continue;}
                            $parts = explode('~', $url, 2);
                            $shown++;
                            ?>
                        <tr class="client_table_body">
                            <td><?php echo htmlspecialchars($parts[0], ENT_QUOTES);?></td>
                            <td align="center" style="width:120px;">refresh: <?php echo isset($parts[1]) ? (int)$parts[1] : '-';?></td>
                        </tr>
                        <?php
                        }
                        if($shown === 0)
                        {
                            ?>
                        <tr class="client_table_body">
                            <td><i>This saved list is empty - no URLs were selected when it was saved.</i></td>
                        </tr>
                        <?php
                        }
                        ?>
                                </table>
                            </td>
                        </tr>
                    </tbody>
                    <?php
                    $tablerowid++;
                }
                ?>
                </table>
                <hr />
                <table border="1px" class="all_tables">
                    <tr class="client_table_head">
                        <th colspan="4">Clients Archived Links</th>
                    </tr>
                    <tr class="client_table_head">
                        <th>+/-</th><th>Name</th><th>Date</th><th>Options</th>
                    </tr>
                <?php
                $stmt = $conn->prepare("SELECT * FROM archive_links WHERE client = ? ORDER by date ASC");
                $stmt->execute([$client_get]);
                $result = $stmt;
                $tablerowid = 0;
                while($client_arc = $result->fetch(PDO::FETCH_ASSOC))
                {
                    $arc_urls_esc = htmlspecialchars($client_arc['urls'], ENT_QUOTES);
                    ?>
                    <tr class="client_table_body">
                        <td onclick="expandcontract('Row<?php echo $tablerowid;?>','ClickIcon<?php echo $tablerowid;?>')"
                            id="ClickIcon<?php echo $tablerowid;?>" style="cursor: pointer; cursor: hand;">+</td>
                        <td><?php echo htmlspecialchars($client_arc['name'], ENT_QUOTES);?></td>
                        <td><?php echo date('F j, Y, g:i a', $client_arc['date']);?></td>
                        <td>
                            <table>
                                <tr>
                                    <td>
                                        <form name="saved" action="?func=edit_urls&client=<?php echo $client_get;?>&cl_func=restore" method="POST">
                                            <input type="hidden" name="urls" value="<?php echo $arc_urls_esc; ?>">
                                            <input type='submit' name="copy" value='Restore'>
                                        </form>
                                    </td>
                                    <td>
                                        <form name="saved" action="?func=rm_arc_urls&client=<?php echo $client_get;?>" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$client_arc['id']; ?>">
                                            <input type='submit' name="copy" value='Remove'>
                                        </form>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <?php
                    $exp = explode("|", $client_arc['urls']);
                    ?>
                    <tbody id="Row<?php echo $tablerowid;?>" style="display:none">
                        <tr>
                            <td colspan="4">
                                <table border="1" width="100%">
                        <?php
                        foreach($exp as $url)
                        {
                            ?>
                        <tr class="client_table_body">
                            <td><?php echo htmlspecialchars($url, ENT_QUOTES);?></td>
                        </tr>
                        <?php
                        }
                        ?>
                                </table>
                            </td>
                        </tr>
                    </tbody>
                    <?php
                    $tablerowid++;
                }
                ?>
                </table>
                <?php
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "rm_arc_urls":
            if($perms['edit_urls'])
            {
                $client_get = filter_input(INPUT_GET, 'client', FILTER_SANITIZE_SPECIAL_CHARS);
                $id = (int)filter_input(INPUT_POST, 'id', FILTER_SANITIZE_SPECIAL_CHARS);
                $stmt = $conn->prepare("DELETE FROM archive_links WHERE id = ?");
                if($stmt->execute([$id]))
                {
                    echo "Removed Archived List";
                   ?>
                    <script>
                        setTimeout("location.href = '<?php echo  $admin_url;?>admin/index.php?func=view_client&client=<?php echo $client_get;?>'",<?php echo $page_timeout;?>);
                    </script>
                <?php
                }else
                {
                    echo "Failed to Removed Archived List.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }

            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "add_client":
            if($perms['edit_urls'])
            {
                $friendly = filter_input(INPUT_POST, 'friendly', FILTER_SANITIZE_SPECIAL_CHARS);
                $friendly_esc = htmlspecialchars((string)$friendly, ENT_QUOTES);
                # This becomes a bare (unquoted) "<id>_links" table name - MySQL tolerates a
                # leading digit there but SQL Server/SQLite don't, so it's prefixed with a
                # letter to guarantee a valid identifier on every supported driver.
                $client_ID = 'c'.md5(random_int(0, PHP_INT_MAX).microtime());
                $stmt = $conn->prepare("INSERT INTO friendly (friendly, client) VALUES (?, ?)");
                if($stmt->execute([$friendly, $client_ID]))
                {
                    $stmt2 = $conn->prepare("INSERT INTO allowed_clients (client_name) VALUES (?)");
                    if($stmt2->execute([$client_ID]))
                    {
                        # $client_ID is always a 32-char hex md5, always a safe bare identifier here.
                        if(db_create_links_table($conn, $driver, $client_ID."_links"))
                        {
                            echo "Created link table for `$friendly_esc`<br />";
                            ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php'",<?php echo $page_timeout;?>);
                    </script>
                            <?php
                        }else
                        {
                            echo "Failed to create link table for `$friendly_esc`<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                    }else
                    {
                        echo "Failed to insert into allowed_clients table<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }else
                {
                    echo "Failed to insert into friendly table<br />Probably a Duplicate name, check the SQL error below<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "view_users":
            if($perms['edit_users'])
            {
                ?>
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th>Username</th>
                        <?php
                        if($LDAP)
                        {?>
                        <th>Domain</th><?php
                        }else{ ?>
                        <th>Password</th><?php
                        }
                        ?><th>Permissions</th><th>Options</th>
                    </tr>
                    <?php
                    if(!isset($admin_user) || $admin_user === ''){$admin_user = 'unsadmin';}
                    # The built-in admin is kept out of the list below because its row has no
                    # editable permissions and must not be removable. It is still shown, as its
                    # own row, so the account is not invisible on the page that manages users.
                    $bia_stmt = $conn->query("SELECT built_in_admin FROM settings");
                    $bia_row = $bia_stmt ? $bia_stmt->fetch(PDO::FETCH_ASSOC) : false;
                    $bia_off = !empty($bia_row['built_in_admin']);
                    ?>
                    <tr class="client_table_body">
                        <td align="Center">
                            <b><?php echo htmlspecialchars($admin_user, ENT_QUOTES);?></b>
                            <br /><font size="1">built-in admin</font>
                        </td>
                        <td align="Center"><font size="1">set at install; use "Reset Admin Password" below</font></td>
                        <td align="Center">Full access</td>
                        <td align="Center">
                            <?php echo $bia_off
                                ? "<font color='red'>Disabled</font>"
                                : "<font color='green'>Enabled</font>";?>
                            <br /><font size="1">use the button below to change</font>
                        </td>
                    </tr>
                    <?php
                    $au_stmt = $conn->prepare("SELECT * FROM allowed_users WHERE username != ?");
                    $au_stmt->execute([$admin_user]);
                    $allowed_users_all = $au_stmt->fetchAll(PDO::FETCH_ASSOC);
                    if(count($allowed_users_all) > 0)
                    {
                        foreach($allowed_users_all as $array)
                        {
                        ?>
                    <tr class="client_table_body">
                        <td align="Center">
                                <?php echo htmlspecialchars($array['username'], ENT_QUOTES);?>
                        </td>
                        <?php
                        $link = db_connect($server, $username, $password, $db, $driver);
                        $stmt1 = $link->prepare("SELECT id,password FROM internal_users WHERE username = ?");
                        $stmt1->execute([$array['username']]);
                        $int_usr = $stmt1->fetch(PDO::FETCH_ASSOC);
                        if(empty($int_usr['password']))
                        {?>
                        <td align="Center">
                                <?php echo htmlspecialchars($array['domain'], ENT_QUOTES);?>
                        </td>
                        <?php
                        }else
                        {
                            ?>
                        <td align="Center">
                            <form action="?func=edit_user&set=reset_pwd" method="POST">
                                <input type="hidden" name="id" value="<?php echo (int)$int_usr['id'];?>"/>
                                <input type="password" name="password" value=""/>
                                <input type="submit" value="Reset Password" />
                            </form>
                        </td>
                        <?php
                        }
                        ?>
                        <td width="500px">
                            <table>
                                <tr>
                                    <td align="center">
                                        <form action="?func=edit_user&set=urls" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$array['id'];?>"/>
                                            <input type="hidden" name="edit_urls" value="<?php if($array['edit_urls']){echo "0";}else{echo "1";}?>"/>
                                            <input type="submit" value="<?php if($array['edit_urls']){echo "Deny";}else{echo "Allow";}?> Edit Clients" />
                                        </form>
                                    </td>
                                    <td align="center">
                                        <form action="?func=edit_user&set=emerg" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$array['id'];?>"/>
                                            <input type="hidden" name="edit_emerg" value="<?php if($array['edit_emerg']){echo "0";}else{echo "1";}?>"/>
                                            <input type="submit" value="<?php if($array['edit_emerg']){echo "Deny";}else{echo "Allow";}?> Edit Emergency" />
                                        </form>
                                    </td>
                                    <td align="center">
                                        <form action="?func=edit_user&set=user" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$array['id'];?>"/>
                                            <input type="hidden" name="edit_user" value="<?php if($array['edit_users']){echo "0";}else{echo "1";}?>"/>
                                            <input type="submit" value="<?php if($array['edit_users']){echo "Deny";}else{echo "Allow";}?> Edit Users" />
                                        </form>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center">
                                        <form action="?func=edit_user&set=c_messages" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$array['id'];?>"/>
                                            <input type="hidden" name="c_messages" value="<?php if($array['c_messages']){echo "0";}else{echo "1";}?>"/>
                                            <input type="submit" value="<?php if($array['c_messages']){echo "Deny";}else{echo "Allow";}?> Custom Messages" />
                                        </form>
                                    </td>
                                    <td align="center">
                                        <form action="?func=edit_user&set=rss_feeds" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$array['id'];?>"/>
                                            <input type="hidden" name="rss_feeds" value="<?php if($array['rss_feeds']){echo "0";}else{echo "1";}?>"/>
                                            <input type="submit" value="<?php if($array['rss_feeds']){echo "Deny";}else{echo "Allow";}?> Rss Feeds" />
                                        </form>
                                    </td>
                                    <td align="center">
                                        <form action="?func=edit_user&set=edit_options" method="POST">
                                            <input type="hidden" name="id" value="<?php echo (int)$array['id'];?>"/>
                                            <input type="hidden" name="edit_options" value="<?php if($array['edit_options']){echo "0";}else{echo "1";}?>"/>
                                            <input type="submit" value="<?php if($array['edit_options']){echo "Deny";}else{echo "Allow";}?> UNS Options" />
                                        </form>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td align="Center">
                            <form action="?func=remove_user" method="POST">
                                <input type="hidden" name="id" value="<?php echo (int)$array['id'];?>"/>
                                <input type="submit" value="Remove" />
                            </form>
                        </td>
                    </tr>
                        <?php
                        }
                    }else
                    {
                        ?>
                    <tr class="client_table_body">
                        <td colspan="6" align="center">There are no Users, lets add some</td>
                    </tr>
                        <?php
                    }
                    ?>
                    <tr class="client_table_tail">
                        <td colspan="6" align="Center">
                            <br />
                            <form name="client_add" action="?func=add_user" method="POST">
                            <table border="1px">
                                <tr class="client_table_body">
                                    <td>
                                        Username:
                                    </td>
                                    <td>
                                        <input type="text" name="user_N" />
                                    </td>
                                </tr>
                                <?php
                                if($LDAP)
                                {
                                ?>
                                <tr class="client_table_body">
                                    <td>
                                        Domain:
                                    </td>
                                    <td>
                                        <input type="text" name="domain_N" />
                                    </td>
                                </tr>
                                <?php
                                }else
                                {
                                    ?>
                                <tr class="client_table_body">
                                    <td>
                                        Password:
                                    </td>
                                    <td>
                                        <input type="hidden" name="internal_user" value="internal_user" />
                                        <input name="pwd_N" type="password" />
                                    </td>
                                </tr>
                                <?php
                                }
                                ?>
                                <tr>
                                    <td colspan="2" align="center" class="client_table_body">
                                        <input type="submit" value="Add User" />
                                    </td>
                                </tr>
                            </table>
                            </form>
                            
                            <table border="1px">
                                <tr class="client_table_body">
                                    <td align="Center">
                                        <form action="?func=edit_user&set=reset_pwd" method="POST">
                                            <?php
                                            if(!isset($admin_user) || $admin_user === ''){$admin_user = 'unsadmin';}
                                            $ad_stmt = $conn->prepare("SELECT id FROM internal_users WHERE username = ?");
                                            $ad_stmt->execute([$admin_user]);
                                            $admusr = $ad_stmt->fetch(PDO::FETCH_ASSOC);
                                            ?>
                                            <input type="hidden" name="id" value="<?php echo (int)($admusr['id'] ?? 0);?>" />
                                            <input type="password" name="password" value="" />
                                            <input type="submit" value="Reset Admin Password" />
                                        </form>
                                    </td>
                                    <td>
                                        <form action="?func=toggle_builtin" method="POST">
                                         <?php
                                        $result = $conn->query("SELECT * FROM settings");
                                        $array1 = $result->fetch(PDO::FETCH_ASSOC);
                                        ?>
                                            <?php
                                            # built_in_admin is a "disabled" flag: 1 means the
                                            # built-in account cannot log in. The button used to be
                                            # labelled from that flag directly, so it read "Enable"
                                            # while the account was working - and pressing it
                                            # disabled the account you were logged in as.
                                            $bia_disabled = !empty($array1['built_in_admin']);
                                            ?>
                                            <input type="hidden" name="toggle_admin" value="<?php echo $bia_disabled ? "0" : "1";?>"/>
                                            <input type="submit" value="<?php echo $bia_disabled ? "Enable" : "Disable";?> Built in Admin" />
                                        </form>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <?php
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "remove_user":
            if($perms['edit_users'])
            {
                $id = (int)filter_input(INPUT_POST, 'id', FILTER_SANITIZE_SPECIAL_CHARS);
                $stmt = $conn->prepare("SELECT username,domain,edit_users FROM allowed_users WHERE id = ?");
                $stmt->execute([$id]);
                $array1 = $stmt->fetch(PDO::FETCH_ASSOC);

                # Removing the last account that can administer UNS leaves nobody able to get
                # back in, so refuse rather than let it happen silently.
                if(!isset($admin_user) || $admin_user === ''){$admin_user = 'unsadmin';}
                if($array1 && !empty($array1['edit_users'])
                    && uns_admin_count($conn, $admin_user, !empty($LDAP), $array1['username']) === 0)
                {
                    echo "Not removing <b>".htmlspecialchars($array1['username'], ENT_QUOTES)."</b>: it is the only"
                        ." account that can log in and manage users, so removing it would lock you out.";
                    ?>
            <script>
                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",4000);
            </script>
                    <?php
                    break;
                }

                $del_stmt = $conn->prepare("DELETE FROM allowed_users WHERE id = ?");
                if($del_stmt->execute([$id]))
                {
                    if(($array1['domain'] ?? '')=='')
                    {
                        $del_stmt2 = $conn->prepare("DELETE FROM internal_users WHERE username = ?");
                        if($del_stmt2->execute([$array1['username']]))
                        {
                            echo "Removed Internal user.";
                            ?>
                            <script>
                                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",<?php echo $page_timeout;?>);
                            </script>
                            <?php
                        }else
                        {
                            echo "Failed to Remove Internal User.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                            break;
                        }
                    }else
                    {
                        echo "Removed User.";
                        ?>
                        <script>
                            setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",<?php echo $page_timeout;?>);
                        </script>
                        <?php
                    }
                }else
                {
                    echo "Failed to remove user ($id).<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "toggle_builtin":
            if($perms['edit_users'])
            {
                $toggle_admin = (int)filter_input(INPUT_POST, 'toggle_admin', FILTER_SANITIZE_SPECIAL_CHARS);

                # Refuse to switch the built-in account off unless some other account can
                # actually log in, otherwise this button locks everyone out of the admin
                # panel with no way back in short of editing the database by hand. LDAP
                # counts, since those accounts authenticate without an internal_users row.
                if($toggle_admin)
                {
                    if(!isset($admin_user) || $admin_user === ''){$admin_user = 'unsadmin';}
                    if(uns_admin_count($conn, $admin_user, !empty($LDAP), $admin_user) === 0)
                    {
                        echo "Not disabling the built-in admin: it is the only account that can log in and manage users,"
                            ." so this would lock you out. Add another user, grant them <b>Edit Users</b>, check they can"
                            ." sign in, then disable this account.";
                        ?>
            <script>
                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",4000);
            </script>
                        <?php
                        break;
                    }
                }

                $stmt = $conn->prepare("UPDATE settings SET built_in_admin = ? WHERE id = 1");
                if($stmt->execute([$toggle_admin]))
                {
                    if($toggle_admin){echo "Disabled";}else{echo "Enabled";}
                    echo " Built in Admin";
                    ?>
            <script>
                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",<?php echo $page_timeout;?>);
            </script>
                    <?php
                }else
                {
                    echo "Failed Update.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "add_user":
            if($perms['edit_users'])
            {
                $user = filter_input(INPUT_POST, 'user_N', FILTER_SANITIZE_SPECIAL_CHARS);
                $internal_user = @filter_input(INPUT_POST, 'internal_user', FILTER_SANITIZE_SPECIAL_CHARS);
                if(!$internal_user)
                {
                    $domain = @filter_input(INPUT_POST, 'domain_N', FILTER_SANITIZE_SPECIAL_CHARS);
                    $stmt = $conn->prepare("INSERT INTO allowed_users (username, domain, edit_urls, edit_emerg, edit_users) VALUES (?, ?, 1, 0, 0)");
                    if($stmt->execute([$user, $domain]))
                    {
                        echo "Added new User (".htmlspecialchars((string)$domain, ENT_QUOTES)."\\".htmlspecialchars((string)$user, ENT_QUOTES).").";
                        ?>
                      <script>
                            setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",<?php echo $page_timeout;?>);
                        </script>
                        <?php
                    }else
                    {
                        echo "Failed to add new User.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }else
                {
                    $pwd = @filter_input(INPUT_POST, 'pwd_N', FILTER_SANITIZE_SPECIAL_CHARS);
                    $pwd_hash = password_hash((string)$pwd, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO allowed_users (username, domain, edit_urls, edit_emerg, edit_users) VALUES (?, '', 1, 0, 0)");
                    if($stmt->execute([$user]))
                    {
                        $stmt2 = $conn->prepare("INSERT INTO internal_users (username, password, disabled, failed) VALUES (?, ?, 0, 0)");
                        if($stmt2->execute([$user, $pwd_hash]))
                        {
                            echo "Added new Internal User (".htmlspecialchars((string)$user, ENT_QUOTES).").";
                            ?>
                           <script>
                                setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",<?php echo $page_timeout;?>);
                            </script>
                            <?php
                        }else
                        {
                            echo "Failed to add new User.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                    }else
                    {
                        echo "Failed to add new User.<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "edit_user":
            if($perms['edit_users'])
            {
                $id = (int)filter_input(INPUT_POST, 'id', FILTER_SANITIZE_SPECIAL_CHARS);
                $set = filter_input(INPUT_GET, 'set', FILTER_SANITIZE_SPECIAL_CHARS);
                # map each toggle to its column name, since the update logic (and the
                # SQL injection / escaping fix) is otherwise identical for all of them.
                $toggle_fields = array(
                    'urls' => array('edit_urls', 'edit_urls', 'Edit_URL'),
                    'emerg' => array('edit_emerg', 'edit_emerg', 'Edit_Emerg'),
                    'user' => array('edit_user', 'edit_users', 'Edit_User'),
                    'c_messages' => array('c_messages', 'c_messages', 'c_messages'),
                    'rss_feeds' => array('rss_feeds', 'rss_feeds', 'rss_feeds'),
                    'edit_options' => array('edit_options', 'edit_options', 'edit_options'),
                );
                if(isset($toggle_fields[$set]))
                {
                    list($post_field, $column, $label) = $toggle_fields[$set];
                    $value = (int)filter_input(INPUT_POST, $post_field, FILTER_SANITIZE_SPECIAL_CHARS);

                    # Revoking Edit Users from the last remaining administrator leaves nobody
                    # able to grant it back, so treat it the same as removing them.
                    if($column === 'edit_users' && !$value)
                    {
                        if(!isset($admin_user) || $admin_user === ''){$admin_user = 'unsadmin';}
                        $tgt_stmt = $conn->prepare("SELECT username FROM allowed_users WHERE id = ?");
                        $tgt_stmt->execute([$id]);
                        $tgt = $tgt_stmt->fetch(PDO::FETCH_ASSOC);
                        if($tgt && uns_admin_count($conn, $admin_user, !empty($LDAP), $tgt['username']) === 0)
                        {
                            echo "Not removing <b>Edit Users</b> from <b>".htmlspecialchars($tgt['username'], ENT_QUOTES)."</b>:"
                                ." it is the only account that can log in and manage users, so nobody could grant it back.";
                            ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",4000);
                    </script>
                            <?php
                            break;
                        }
                    }

                    $stmt = $conn->prepare("UPDATE allowed_users SET $column = ? WHERE id = ?");
                    if($stmt->execute([$value, $id]))
                    {
                        echo "Updated $label field.";
                        ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",<?php echo $page_timeout;?>);
                    </script>
                            <?php
                    }else
                    {
                        echo "Failed Update.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }elseif($set === "reset_pwd")
                {
                    $reset_pwd = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_SPECIAL_CHARS);
                    $r_pwd = password_hash((string)$reset_pwd, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE internal_users SET password = ? WHERE id = ?");
                    if($stmt->execute([$r_pwd, $id]))
                    {
                        echo "Changed User Password.";
                        ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php?func=view_users'",<?php echo $page_timeout;?>);
                    </script>
                            <?php
                    }else
                    {
                        echo "Failed to Update User Password.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "remove_cl":
            if($perms['edit_urls'])
            {
                if(!@$_POST['remove'])
                {
                    ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php'",<?php echo $page_timeout;?>);
                    </script>
                    <?php
                    break;
                }
                foreach($_POST['remove'] as $id)
                {
                    $id_esc = htmlspecialchars((string)$id, ENT_QUOTES);
                    if(!is_safe_client_id($id))
                    {
                        echo "Skipped invalid client [$id_esc]<br />\r\n";
                        continue;
                    }
                    $stmt = $conn->prepare("DELETE FROM allowed_clients WHERE client_name = ?");
                    if($stmt->execute([$id]))
                    {
                        $stmt2 = $conn->prepare("DELETE FROM friendly WHERE client = ?");
                        if($stmt2->execute([$id]))
                        {
                            # $id is whitelisted by is_safe_client_id() above, safe as a table name here.
                            if($conn->query("DROP TABLE ".$id."_links"))
                            {
                                echo "Removed client [$id_esc]<br />\r\n";
                                ?>
                    <script>
                        setTimeout("location.href = '<?php echo $admin_url;?>admin/index.php'",<?php echo $page_timeout;?>);
                    </script>
                            <?php
                            }else
                            {
                                echo "Failed to drop table ".$id_esc."_links<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                            }
                        }else
                        {
                            echo "Failed to remove client [$id_esc] from friendly<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                    }else
                    {
                        echo "Failed to remove client [$id_esc] from allowed list<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        default:
            if($perms['edit_urls'])
            {
                ?>
            <meta http-equiv="refresh" content="240;">
                <script type="text/javascript">
                <!--
                function SetAllCheckBoxes(FormName, FieldName, CheckValue)
                {
                        if(!document.forms[FormName])
                                return;
                        var objCheckBoxes = document.forms[FormName].elements[FieldName];
                        if(!objCheckBoxes)
                                return;
                        var countCheckBoxes = objCheckBoxes.length;
                        if(!countCheckBoxes)
                                objCheckBoxes.checked = CheckValue;
                        else
                                // set the check value for all check boxes
                                for(var i = 0; i < countCheckBoxes; i++)
                                        objCheckBoxes[i].checked = CheckValue;
                }
                // -->
                </script>
                <form name="client_List" action="?func=remove_cl" method="POST">
                <table border="1px" width="100%">
                    <tr class="client_table_head">
                        <th>Client</th><th>Last Connect</th><th>Last URL</th><th>Remove</th>
                    </tr>
                    <?php
                    $result = $conn->query("SELECT allowed_clients.id, friendly.friendly, friendly.client
FROM allowed_clients, friendly
WHERE friendly.client = allowed_clients.client_name
ORDER BY friendly+0, friendly");
                    $rows = 0;
                    $conn2 = db_connect($server, $username, $password, $db, $driver);
                    while($array = $result->fetch(PDO::FETCH_ASSOC))
                    {
                        $client = $array['client'];
                        $client_esc = htmlspecialchars($client, ENT_QUOTES);
                        $stmt1 = $conn2->prepare("SELECT * FROM connections WHERE client = ? ORDER by last_conn DESC");
                        $stmt1->execute([$client]);
                        $array2 = $stmt1->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <tr class="client_table_body">
                        <td align="center">
                            <a class="links" href="?func=view_client&client=<?php echo $client_esc;?>"><?php echo htmlspecialchars($array['friendly'], ENT_QUOTES);?></a>
                        </td>
                        <td align="Center"><?php
                        if(!empty($array2['last_conn'])){echo date("F j, Y, g:i a",$array2['last_conn']);}
                        ?>
                        </td>
                        <td align="Center">
                            <?php
                        if(!empty($array2['last_conn']))
                        {
                            switch($array2['last_url'])
                            {
                                case "no_urls":
                                    echo "Client Has No URLS";
                                    break;
                                default:
                                    $last_url_esc = htmlspecialchars($array2['last_url'], ENT_QUOTES);
                                    echo '<a class="links" target="_blank" href="'.$last_url_esc.'">'.$last_url_esc.'</a>';
                                    break;
                            }
                        }else
                        {
                            echo "Has not connected yet...";
                        }
                        ?>
                        </td>
                        <td align="Center">
                            <input type="checkbox" name="remove[]" value="<?php echo $client_esc; ?>"/>
                        </td>
                    </tr>
                    <?php
                    $rows++;
                    }
                    if($rows == 0)
                    {
                        ?>
                        <tr class="client_table_body">
                            <td colspan="4" align="center">There are no Clients, lets add some</td>
                        </tr>
                        <?php
                    }
                    ?>
                        <tr class="client_table_tail">
                            <td colspan="2">
                            </td>
                            <td align="center">
                                <input type="submit" value="Remove Selected" />
                            </td>
                        <td align="center">
                            <input type="button" onclick="SetAllCheckBoxes('client_List', 'remove[]', true);" value="Check">
                            <input type="button" onclick="SetAllCheckBoxes('client_List', 'remove[]', false);" value="Uncheck">
                            </form>
                        </td>
                        </tr>
                </form>
                        <tr class="client_table_tail">
                            <td colspan="4" align="Center">
                                <br />
                                <form name="client_add" action="?func=add_client" method="POST">
                                <table border="1px">
                                    <tr class="client_table_body">
                                        <td>
                                            Client Name:
                                        </td>
                                        <td>
                                            <input type="text" name="friendly" />
                                        </td>
                                        <td rowspan="3">
                                            <input type="submit" value="Add Client" />
                                        </td>
                                    </tr>
                                </table>
                                </form>
                            </td>
                        </tr>
                        <?php
                    ?>
                </table>
                <?php
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
    }
?>
            </td>
        </tr>
    </table>
<?php
}





function login_check()
{
        global $global_loggedin, $username, $privs_a;
        $cookie_name = 'login_yes';
       # echo "<br>".$cookie_name.":  ".@$_COOKIE[$cookie_name]."<br>";
        if(!@isset($_COOKIE[$cookie_name]))
        {
            return 0;
        }else{
            return 1;
        }
}

# Client names become part of dynamically-named tables ("<name>_links") in many places
# below, which can't be parameterized in a prepared statement - so they're validated
# against a strict whitelist wherever that happens, instead.
function is_safe_client_id($client)
{
    return is_string($client) && $client !== "" && preg_match('/^[A-Za-z0-9_]+$/', $client) === 1;
}

function gen_friendly($client)
{
    include '../configs/conn.php';
    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    $stmt = $conn->prepare("SELECT friendly FROM friendly WHERE client = ?");
    $stmt->execute([$client]);
    $friendly = $stmt->fetch(PDO::FETCH_ASSOC);
    return $friendly['friendly'] ?? '';
}

function format_bytes($size) {
    $units = array(' B', ' KB', ' MB', ' GB', ' TB');
    for ($i = 0; $size >= 1024 && $i < 4; $i++) $size /= 1024;
    return round($size, 2).$units[$i];
}

# Counts accounts that can BOTH log in and manage users, optionally ignoring one of them.
#
# Used to stop the last administrator being disabled, removed or demoted - any of which
# leaves nobody able to administer UNS, with no way back in short of editing the database
# by hand. "Can log in" is deliberately strict: an account listed in allowed_users is not
# enough on its own, it also needs a way to authenticate.
function uns_admin_count($conn, $admin_user, $ldap_on, $exclude_username = null)
{
    $stmt = $conn->query("SELECT built_in_admin FROM settings");
    $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    $builtin_enabled = empty($row['built_in_admin']);

    $res = $conn->query(
        "SELECT a.username, a.domain, i.username AS int_username, i.disabled AS int_disabled
         FROM allowed_users a
         LEFT JOIN internal_users i ON i.username = a.username
         WHERE a.edit_users = 1");
    if(!$res){return 0;}

    $count = 0;
    while($r = $res->fetch(PDO::FETCH_ASSOC))
    {
        if($exclude_username !== null && $r['username'] === $exclude_username){continue;}

        if($r['username'] === $admin_user)
        {
            # The built-in account additionally has its own on/off switch.
            if($builtin_enabled){$count++;}
            continue;
        }
        # An internal account can log in when it has an enabled internal_users row.
        if(!empty($r['int_username']) && empty($r['int_disabled'])){$count++; continue;}
        # An LDAP account can only log in while LDAP is switched on.
        if($ldap_on && ($r['domain'] ?? '') !== ''){$count++;}
    }
    return $count;
}

function create_cookie($username_login)
{
    include "../configs/vars.php";
    include "../configs/conn.php";
    $proto = $GLOBALS['proto'];;

    $admin_url = $GLOBALS['admin_url'];
    $reg_url = $GLOBALS['reg_url'];
    
    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    $hash = bin2hex(random_bytes(16)); # session token - must be unguessable, hence a CSPRNG rather than mt_rand()
    if($timeout > 0){$time = time()+$timeout;}else{$time = 0;}

    if($root == "" or $root == "/"){$path = "/admin";}else{$path = "/".$root."admin";}

    if(setcookie("login_yes", $hash.":".$username_login, $time , $path, '', $SSL, 1))
    {
        echo "Cookie Set\r\n";
        $stmt = $conn->prepare("INSERT INTO hash_links (hash, time, username) VALUES (?, ?, ?)");
        $result = $stmt->execute([$hash, $time, $username_login]);
        if($result)
        {
            echo "<h1>Logged In</h1>";
            ?>
                <script>location.href = '<?php echo $admin_url;?>admin/index.php';</script>
            <?php
            return 1;
        }else
        {
            echo db_error($conn)."\r\n";
            return 0;
        }
    }else
    {
        echo "cookie eaten\r\n";
        return 0;
    }
}

function check_archives($client)
{
    if(!is_safe_client_id($client)){return -1;}
    include "../configs/vars.php";
    include "../configs/conn.php";
    if(!isset($driver)){$driver = 'mysql';}
    if(!$conn = db_connect($server, $username, $password, $db, $driver))
    {return -1;}
    $stmt = $conn->prepare("SELECT * FROM archive_links WHERE client = ? ORDER BY date ASC");
    if(!$stmt->execute([$client]))
    {return 0;}
    $archived = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = count($archived);
    if($max_archives < $rows)
    {
        foreach($archived as $arcs)
        {
            if($rows+1 == $max_archives){break;}
            $del_stmt = $conn->prepare("DELETE FROM archive_links WHERE id = ?");
            if($del_stmt->execute([$arcs['id']]))
            {
                echo "Removed row [".(int)$arcs['id']."]<br />";
                $rows--;
            }else
            {
                die(htmlspecialchars(db_error($conn), ENT_QUOTES));
            }
        }
        return 2;
    }else
    {
        return 1;
    }
}

function login_form($mesg)
{
    # uns_smarty() turns on escape_html, so the raw string is passed through and escaped
    # on output - the old inline version called htmlspecialchars() here instead.
    $smarty = uns_smarty();
    $smarty->assign('message', (string)$mesg);
    $smarty->display('login.tpl');
    die();
}
?>