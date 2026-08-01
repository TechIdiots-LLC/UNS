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
        $stmt = $conn->prepare("SELECT tz FROM allowed_users where username = ?");
        $stmt->execute([$cookie_user]);
        $tx_array = $stmt->fetch(PDO::FETCH_ASSOC);
        $exp = explode(":", $tx_array['tz'] ?? 'ewt:0');
        $tz_list = timezone_abbreviations_list();
        date_default_timezone_set($tz_list[$exp[0]][$exp[1]]["timezone_id"]);
        $func = filter_input(INPUT_GET, 'func', FILTER_SANITIZE_SPECIAL_CHARS);

        # The page shell is a template; the screens inside it are still PHP that echoes
        # markup, so their output is buffered and handed to the template as the content
        # block. That lets the shell move to Smarty now and the screens follow one at a
        # time, instead of one enormous rewrite of a 3,700 line file.
        ob_start();
        admin_panel($cookie_user, $func, $proto);
        $panel_body = ob_get_clean();

        $smarty = uns_smarty();
        $smarty->assign('content', $panel_body);
        $smarty->assign('footer_date', date("Y-m", filemtime('index.php')));
        $smarty->display('admin_page.tpl');
        die();
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

# The page shell, including this footer, is rendered from templates/admin_page.tpl
# by the branch above, which exits when it is done. Nothing reaches here except the
# session-timeout path, and login_form() exits too.


# Emits the "go here in a moment" redirect the admin screens use after an action.
#
# This was copy-pasted 45 times in ten slightly different shapes. One of them had a stray
# " $proto." inside the JavaScript string literal, which produced a broken URL - the kind
# of thing that survives indefinitely when the same markup is duplicated by hand.
#
# $query is appended to admin/index.php as a query string ('' for the front page), and
# $delay is in milliseconds - normally $page_timeout, which is 0 for an instant redirect.
function uns_redirect($query = '', $delay = 0)
{
    $admin_url = $GLOBALS['admin_url'] ?? '';
    $url = $admin_url.'admin/index.php'.($query !== '' ? '?'.$query : '');
    echo "
    <script>
"
        ."        setTimeout(\"location.href = '".$url."'\", ".(int)$delay.");
"
        ."    </script>
";
}

# Names the RSS feed or custom message a URL refers to, when it points back at this UNS
# install. Both the client view and the emergency list showed a column of near-identical
# template.php links without it, and both had their own copy of this logic.
function uns_url_label($conn, $host, $url)
{
    $parse_url = parse_url($url);
    if(str_replace("/", "", (string)$host) !== ($parse_url['host'] ?? null)){return '';}

    $exp_url = explode("?", html_entity_decode($url));
    $query_ = array();
    foreach(explode('&', html_entity_decode($exp_url[1] ?? '')) as $e)
    {
        $qur = explode("=", $e);
        $query_[$qur[0]] = $qur[1] ?? '';
    }

    $id = (int)($query_['id'] ?? 0);
    $table = null;
    switch($query_['type'] ?? '')
    {
        case "rss":       $table = 'rss_feeds'; break;
        case "c_message": $table = 'c_messages'; break;
    }
    if($table === null){return '';}

    $stmt = $conn->prepare("SELECT name FROM ".$table." WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['name'] : '';
}

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
    # Menu definition. Each entry is a permission column, the label shown in the
    # permission bar across the top, and the side-bar link it unlocks. This replaced six
    # near-identical if/else blocks that concatenated the same HTML by hand.
    $menu = array(
        array('perm' => 'edit_urls',    'label' => 'Edit Clients',       'href' => '?',                  'text' => 'List Clients'),
        # Groups configure client URL lists, so they ride on the same permission
        # rather than adding a seventh column to allowed_users.
        array('perm' => 'edit_urls',    'label' => '',                   'href' => '?func=client_groups','text' => 'Client Groups'),
        array('perm' => 'edit_emerg',   'label' => 'Emergency Messages', 'href' => '?func=edit_emerg',   'text' => 'Emergency Messages'),
        array('perm' => 'edit_emerg',   'label' => '',                   'href' => '?func=emerg_routes', 'text' => 'Alert Routing'),
        array('perm' => 'edit_users',   'label' => 'Edit Users',         'href' => '?func=view_users',   'text' => 'User Permissions'),
        array('perm' => 'c_messages',   'label' => 'Custom Messages',    'href' => '?func=c_messages',   'text' => 'Custom Messages'),
        array('perm' => 'rss_feeds',    'label' => 'RSS Feeds',          'href' => '?func=rss_feeds',    'text' => 'RSS Feeds'),
        array('perm' => 'edit_options', 'label' => 'UNS Options',        'href' => '?func=edit_options', 'text' => 'UNS Options'),
    );

    $nav_items   = array();
    $side_links  = array();
    foreach($menu as $item)
    {
        $allowed = !empty($perms[$item['perm']]);
        # An empty label means the entry only contributes a side-bar link - it shares a
        # permission that is already named in the bar across the top.
        if($item['label'] !== ''){$nav_items[] = array('label' => $item['label'], 'allowed' => $allowed);}
        if($allowed){$side_links[] = array('href' => $item['href'], 'text' => $item['text']);}
    }

    # Capture the screen so the whole panel - side bar, permission bar and content -
    # renders from one template, instead of a partial that leaves a table open.
    ob_start();

    switch($func)
    {
        case "chg_tz":
            $cl_timezone = filter_input(INPUT_POST, 'cl_timezone', FILTER_SANITIZE_SPECIAL_CHARS);
            $stmt = $conn->prepare("UPDATE allowed_users SET tz = ? WHERE username = ?");
            if($stmt->execute([$cl_timezone, $usr]))
            {
                echo "Changed Time Zone.";
                    uns_redirect('func=rss_feeds', $page_timeout);
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
                ."
";
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
            }
            else{echo "Failed to write Conn Config File.<br />";}
            break;
        case "edit_options":
            # Settings for Scripts/EmergencyMonitor live in the uns_config table; everything
            # else on this screen comes from configs/vars.php and configs/conn.php.
            if(!isset($driver)){$driver = 'mysql';}
            $ef_cfg = uns_config_all($conn, $driver);

            $eo = uns_smarty();
            $eo->assign('sql_host',  html_entity_decode((string)$server));
            $eo->assign('sql_user',  (string)$username);
            $eo->assign('db_name',   (string)$db);
            $eo->assign('uns_name',  html_entity_decode((string)$name_title));
            $eo->assign('hostname',  html_entity_decode((string)$host));
            $eo->assign('http_root', html_entity_decode((string)$root));
            $eo->assign('timeout',   (int)$timeout);
            $eo->assign('ssl',       !empty($SSL));
            $eo->assign('ldap',      !empty($LDAP));
            $eo->assign('ldap_domain', (string)$domain);
            $eo->assign('ldap_port', (int)$port);
            # These two used to be hardcoded in the markup as 0 and 30 rather than showing
            # what was configured, so saving any unrelated option silently reset them.
            $eo->assign('page_timeout', (int)$page_timeout);
            $eo->assign('refresh',      (int)$refresh);
            $eo->assign('max_arch',  (int)$max_archives);
            $eo->assign('max_conns', (int)$max_conn_hist);
            $eo->assign('leds',      !empty($led_blink));
            $eo->assign('lpt_binary', html_entity_decode((string)$lpt_set_app));
            $eo->assign('portctl',    html_entity_decode((string)$lpt_read_app));
            $eo->assign('mysql_dump', html_entity_decode((string)$mysql_dump_bin));
            $eo->assign('severities', array('Unknown', 'Minor', 'Moderate', 'Severe', 'Extreme'));
            $eo->assign('ef_url',      isset($ef_cfg['emerg_feed_url']) ? $ef_cfg['emerg_feed_url'] : '');
            $eo->assign('ef_minutes',  isset($ef_cfg['emerg_display_minutes']) ? (int)$ef_cfg['emerg_display_minutes'] : 30);
            $eo->assign('ef_publish',  isset($ef_cfg['emerg_publish_message']) ? (int)$ef_cfg['emerg_publish_message'] : 1);
            $eo->assign('ef_status',   isset($ef_cfg['emerg_allowed_status']) ? $ef_cfg['emerg_allowed_status'] : 'Actual');
            $eo->assign('ef_severity', isset($ef_cfg['emerg_min_severity']) ? $ef_cfg['emerg_min_severity'] : 'Unknown');
            $eo->assign('ef_max',      isset($ef_cfg['emerg_max_items']) ? (int)$ef_cfg['emerg_max_items'] : 5);
            $eo->assign('ef_follow',   isset($ef_cfg['emerg_follow_cap_links']) ? (int)$ef_cfg['emerg_follow_cap_links'] : 1);
            echo $eo->fetch('screens/edit_options.tpl');
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
                    uns_redirect('func=rss_feeds', $page_timeout);
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
                    uns_redirect('func=rss_feeds', $page_timeout);
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
                    uns_redirect('func=rss_feeds', $page_timeout);
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
                    uns_redirect('func=rss_feeds', $page_timeout);
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
                    <?php
                    # Presentation lives in screens/rss_feeds.tpl; the add/edit handlers
                    # above stay in PHP - they are actions, not markup.
                    $rf_stmt = $conn->query("SELECT * FROM rss_feeds ORDER by name ASC");
                    $rss_all = $rf_stmt ? $rf_stmt->fetchAll(PDO::FETCH_ASSOC) : array();
                    $feeds = array();
                    foreach($rss_all as $row)
                    {
                        $feeds[] = array(
                            'id'       => (int)$row['id'],
                            'name'     => $row['name'],
                            'url'      => $row['url'],
                            'maxlines' => (int)$row['maxlines'],
                            'feed_url' => $reg_url.'html/template.php?type=rss&id='.(int)$row['id'],
                        );
                    }
                    $rf = uns_smarty();
                    $rf->assign('feeds', $feeds);
                    $rf->assign('maxlines_default', (int)$refresh);
                    echo $rf->fetch('screens/rss_feeds.tpl');
                    ?>
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
                    uns_redirect('func=c_messages', $page_timeout);
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
                    uns_redirect('func=c_messages', $page_timeout);
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
                    uns_redirect('func=c_messages', $page_timeout);
                                }else
                                {
                                    echo "Failed to Remove message [$del_esc].<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                                }
                            }
                        }else
                        {
                            if(!@$_POST['body'])
                            {
                    uns_redirect('func=c_messages', $page_timeout);
                                break;
                            }
                            foreach($_POST['body'] as $key=>$body)
                            {
                                $body = htmlentities($body, ENT_QUOTES);
                                $id = (int)$_POST['id'][$key];
                                $name = $_POST['name'][$key];
                                # The checkbox is posted as wrapper[<row index>], so an unticked
                                # box is simply missing rather than shifting every later value
                                # up one row. Missing means off.
                                $wrapper = !empty($_POST['wrapper'][$key]) ? 1 : 0;
                                $stmt = $conn->prepare("UPDATE c_messages SET name = ?, body = ?, wrapper = ? WHERE id = ?");
                                $name_esc = htmlspecialchars((string)$name, ENT_QUOTES);
                                if($stmt->execute([$name, $body, $wrapper, $id]))
                                {
                                    echo "Updated message [$id] ($name_esc).<br/>";
                    uns_redirect('func=c_messages', $page_timeout);
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
                    <?php
                    # Presentation lives in screens/c_messages.tpl. The add/edit handlers
                    # above stay in PHP - they are actions, not markup.
                    $cm_stmt = $conn->query("SELECT * FROM c_messages ORDER by id ASC");
                    $cm_all  = $cm_stmt ? $cm_stmt->fetchAll(PDO::FETCH_ASSOC) : array();
                    $messages = array();
                    foreach($cm_all as $row)
                    {
                        $messages[] = array(
                            'id'      => (int)$row['id'],
                            'name'    => $row['name'],
                            'body'    => $row['body'],
                            'wrapper' => !empty($row['wrapper']),
                            'url'     => $reg_url.'html/template.php?type=c_message&id='.(int)$row['id'],
                        );
                    }
                    $cm = uns_smarty();
                    $cm->assign('messages', $messages);
                    echo $cm->fetch('screens/c_messages.tpl');
                    ?>
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
                    if(!is_safe_client_id($client_get))
                    {
                        # break, not die: the panel is captured with output buffering, so
                        # exiting here would flush raw markup with no page shell at all.
                        echo "Invalid client.";
                        break;
                    }
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, 3000);
                                break;
                            }
                            if(@$_POST['copy2'])
                            {
                                $cl_stmt = $conn->prepare("SELECT * FROM friendly where client != ?");
                                $cl_stmt->execute([$client_get]);
                                $other_clients = $cl_stmt->fetchAll(PDO::FETCH_ASSOC);

                                $cu = uns_smarty();
                                $cu->assign('client_id', $client_get);
                                $cu->assign('clients', $other_clients);
                                $cu->assign('urls_imp', implode("|", $_POST['urls']));
                                echo $cu->fetch('screens/copy_urls.tpl');
                            }
                            if(@$_POST['save_list'])
                            {
                                $sl_stmt = $conn->query("SELECT id,name FROM saved_lists");
                                $existing_lists = $sl_stmt ? $sl_stmt->fetchAll(PDO::FETCH_ASSOC) : array();

                                $su = uns_smarty();
                                $su->assign('client_id', $client_get);
                                $su->assign('saved_lists', $existing_lists);
                                $su->assign('urls_imp', implode("|", $_POST['urls']));
                                echo $su->fetch('screens/save_urls.tpl');
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                uns_emerg_ensure($conn, $driver);

                $es = $conn->query("SELECT emerg FROM settings");
                $settings_row = $es ? $es->fetch(PDO::FETCH_ASSOC) : false;

                # Names for the scope column, so a row reads "Building A" rather than
                # "group:3".
                $group_names = array();
                $gn = $conn->query("SELECT id, name FROM client_groups");
                if($gn){while($row = $gn->fetch(PDO::FETCH_ASSOC)){$group_names[(string)$row['id']] = $row['name'];}}
                $client_names = array();
                $cn = $conn->query("SELECT client, friendly FROM friendly");
                if($cn){while($row = $cn->fetch(PDO::FETCH_ASSOC)){$client_names[$row['client']] = $row['friendly'];}}

                $eu = $conn->query("SELECT * FROM emerg");
                $emerg_all = $eu ? $eu->fetchAll(PDO::FETCH_ASSOC) : array();
                $urls = array();
                foreach($emerg_all as $row)
                {
                    $scope  = isset($row['scope']) && $row['scope'] !== '' ? $row['scope'] : 'all';
                    $target = isset($row['target']) ? (string)$row['target'] : '';
                    if($scope === 'group'){$scope_label = 'Group: '.($group_names[$target] ?? $target);}
                    elseif($scope === 'client'){$scope_label = 'Client: '.($client_names[$target] ?? $target);}
                    else{$scope_label = 'All clients';}

                    $urls[] = array(
                        'id'          => (int)$row['id'],
                        'url'         => $row['url'],
                        'label'       => uns_url_label($conn, $host, $row['url']),
                        'refresh'     => (int)$row['refresh'],
                        'enabled'     => !empty($row['enabled']),
                        'scope_label' => $scope_label,
                    );
                }

                # Everything currently in a targeted emergency, so there is one place that
                # answers "what is live right now?".
                $now     = time();
                $targets = array();
                $ts = $conn->query("SELECT * FROM emerg_targets ORDER BY scope, target");
                if($ts)
                {
                    while($row = $ts->fetch(PDO::FETCH_ASSOC))
                    {
                        $target = (string)$row['target'];
                        $targets[] = array(
                            'id'     => (int)$row['id'],
                            'scope'  => $row['scope'],
                            'target' => $target,
                            'name'   => $row['scope'] === 'group'
                                ? ($group_names[$target] ?? $target)
                                : ($client_names[$target] ?? $target),
                            'live'   => uns_emerg_target_live($row, $now),
                            'until'  => (int)$row['until'],
                            'source' => $row['source'],
                        );
                    }
                }

                # Scope choices for the "add URL" form.
                $scopes = array(array('value' => 'all::', 'text' => 'All clients'));
                foreach($group_names as $gid => $gname){$scopes[] = array('value' => 'group:'.$gid, 'text' => 'Group: '.$gname);}
                foreach($client_names as $cid => $cname){$scopes[] = array('value' => 'client:'.$cid, 'text' => 'Client: '.$cname);}

                $ee = uns_smarty();
                $ee->assign('emerg_on', !empty($settings_row['emerg']));
                $ee->assign('urls', $urls);
                $ee->assign('targets', $targets);
                $ee->assign('scopes', $scopes);
                $ee->assign('refresh', (int)$refresh);
                echo $ee->fetch('screens/edit_emerg.tpl');
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
                    uns_redirect('func=edit_emerg', $page_timeout);
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
                    uns_redirect('func=edit_emerg', $page_timeout);
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
                    uns_redirect('func=edit_emerg', $page_timeout);
                        }else
                        {
                            echo "Failed to updated URL [$id].<br />\r\n".htmlspecialchars(db_error($conn), ENT_QUOTES);
                        }
                    }
                }elseif(@$_POST['delete'] === 'Delete')
                {
                    if(!@$_POST['urls'])
                    {
                    uns_redirect('func=edit_emerg', $page_timeout);
                        break;
                    }
                    foreach($_POST['urls'] as $key=>$id)
                    {
                        $id = (int)$id;
                        $stmt = $conn->prepare("DELETE FROM emerg WHERE id = ?");
                        if($stmt->execute([$id]))
                        {
                            echo "Removed [$id].<br />";
                    uns_redirect('func=edit_emerg', $page_timeout);
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
                    uns_redirect('func=edit_emerg', $page_timeout);
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
                uns_emerg_ensure($conn, $driver);
                $urls = filter_input(INPUT_POST, 'URLS', FILTER_SANITIZE_SPECIAL_CHARS);
                $refresh = (int)filter_input(INPUT_POST, 'refresh', FILTER_SANITIZE_SPECIAL_CHARS);

                # "scope:target" from one select, so the two always travel together.
                list($e_scope, $e_target) = uns_parse_scope(filter_input(INPUT_POST, 'scope', FILTER_SANITIZE_SPECIAL_CHARS));

                $url_exp = explode("&#13;&#10;", $urls);
                $i=0;
                foreach($url_exp as $url_)
                {
                    $url_ = trim($url_);
                    $stmt = $conn->prepare("INSERT INTO emerg (url, enabled, refresh, scope, target) VALUES (?, 1, ?, ?, ?)");
                    if($stmt->execute([$url_, $refresh, $e_scope, $e_target]))
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
                    uns_redirect('func=edit_emerg', $page_timeout);
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('func=view_client&client='.$cl_id, $page_timeout);
            }else
            {
                echo "failed update<br/>";
            }
            break;
        case "view_client":
            if($perms['edit_urls'])
            {
                $client_get = filter_input(INPUT_GET, 'client', FILTER_SANITIZE_SPECIAL_CHARS);
                if(!is_safe_client_id($client_get))
                {
                    # break, not die: the panel is captured with output buffering, so
                    # exiting here would flush raw markup with no page shell at all.
                    echo "Invalid client.";
                    break;
                }

                $stmt = $conn->prepare("SELECT * FROM friendly WHERE client = ?");
                $stmt->execute([$client_get]);
                $friendly = $stmt->fetch(PDO::FETCH_ASSOC);

                $led_selected = 0;
                if($led_blink)
                {
                    $led_stmt = $conn->prepare("SELECT led FROM allowed_clients WHERE client_name = ?");
                    $led_stmt->execute([$client_get]);
                    $led_row = $led_stmt->fetch(PDO::FETCH_ASSOC);
                    $led_selected = (int)($led_row['led'] ?? 0);
                }

                # $client_get is whitelisted by is_safe_client_id() above, so it is safe as
                # a table name here - it cannot be parameterized.
                $links_stmt = $conn->query("SELECT * FROM ".$client_get."_links ORDER BY url ASC");
                $client_links_all = $links_stmt ? $links_stmt->fetchAll(PDO::FETCH_ASSOC) : array();

                $links = array();
                foreach($client_links_all as $row)
                {
                    $label = uns_url_label($conn, $host, $row['url']);
                    $links[] = array(
                        'id'      => (int)$row['id'],
                        'url'     => $row['url'],
                        'label'   => $label,
                        'refresh' => (int)$row['refresh'],
                    );
                }

                # Saved lists and per-client archives have the same shape; entries are stored
                # as "url~refresh" pairs joined by "|".
                $split_entries = function($raw)
                {
                    $out = array();
                    foreach(explode("|", (string)$raw) as $entry)
                    {
                        $entry = trim($entry);
                        if($entry === ''){continue;}
                        $parts = explode('~', $entry, 2);
                        $out[] = array('url' => $parts[0], 'refresh' => isset($parts[1]) ? (int)$parts[1] : '-');
                    }
                    return $out;
                };

                $saved_lists = array();
                $sl = $conn->query("SELECT * FROM saved_lists ORDER by id DESC");
                while($sl && $r = $sl->fetch(PDO::FETCH_ASSOC))
                {
                    $saved_lists[] = array(
                        'id'       => (int)$r['id'],
                        'name'     => $r['name'],
                        'date'     => date('F j, Y, g:i a', $r['date']),
                        'urls_raw' => $r['urls'],
                        'entries'  => $split_entries($r['urls']),
                    );
                }

                $archives = array();
                $ar = $conn->prepare("SELECT * FROM archive_links WHERE client = ? ORDER by date ASC");
                $ar->execute([$client_get]);
                while($r = $ar->fetch(PDO::FETCH_ASSOC))
                {
                    $archives[] = array(
                        'id'       => (int)$r['id'],
                        'name'     => $r['name'],
                        'date'     => date('F j, Y, g:i a', $r['date']),
                        'urls_raw' => $r['urls'],
                        'entries'  => $split_entries($r['urls']),
                    );
                }

                $vc = uns_smarty();
                # Emergency mode for this one client, independent of any group it is in.
                uns_emerg_ensure($conn, $driver);
                $ct_stmt = $conn->prepare("SELECT * FROM emerg_targets WHERE scope = 'client' AND target = ?");
                $ct_row  = ($ct_stmt && $ct_stmt->execute([$client_get])) ? $ct_stmt->fetch(PDO::FETCH_ASSOC) : false;
                $vc->assign('can_emerg',    !empty($perms['edit_emerg']));
                $vc->assign('emerg_live',   $ct_row ? uns_emerg_target_live($ct_row, time()) : false);
                $vc->assign('emerg_until',  $ct_row ? (int)$ct_row['until'] : 0);

                # Groups this client belongs to, so it is obvious from here why a screen may
                # be showing something its own list does not contain.
                $memberships = array();
                foreach(uns_client_groups($conn, $driver, $client_get) as $g)
                {
                    $gt = $conn->prepare("SELECT * FROM emerg_targets WHERE scope = 'group' AND target = ?");
                    $gt_row = ($gt && $gt->execute([(string)$g['id']])) ? $gt->fetch(PDO::FETCH_ASSOC) : false;
                    $memberships[] = array(
                        'id'      => (int)$g['id'],
                        'name'    => $g['name'],
                        'mode'    => $g['mode'],
                        'active'  => !empty($g['active']),
                        'emerg'   => $gt_row ? uns_emerg_target_live($gt_row, time()) : false,
                    );
                }
                $vc->assign('groups', $memberships);
                $vc->assign('client_id',    $client_get);
                $vc->assign('friendly',     $friendly['friendly'] ?? '');
                $vc->assign('friendly_id',  (int)($friendly['id'] ?? 0));
                $vc->assign('client_url',   $reg_url.'index.php?id='.($friendly['client'] ?? ''));
                $vc->assign('led_blink',    !empty($led_blink));
                $vc->assign('led_selected', $led_selected);
                $vc->assign('links',        $links);
                $vc->assign('refresh',      (int)$refresh);
                $vc->assign('saved_lists',  $saved_lists);
                $vc->assign('archives',     $archives);
                echo $vc->fetch('screens/view_client.tpl');
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
                    uns_redirect('func=view_client&client='.$client_get, $page_timeout);
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
                    uns_redirect('', $page_timeout);
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
                if(!isset($admin_user) || $admin_user === ''){$admin_user = 'unsadmin';}

                # The built-in admin is shown as its own row: no editable permissions, and
                # it must not be removable, but hiding it made the account invisible here.
                $bia_stmt = $conn->query("SELECT built_in_admin FROM settings");
                $bia_row  = $bia_stmt ? $bia_stmt->fetch(PDO::FETCH_ASSOC) : false;

                $ad_stmt = $conn->prepare("SELECT id FROM internal_users WHERE username = ?");
                $ad_stmt->execute([$admin_user]);
                $admusr = $ad_stmt->fetch(PDO::FETCH_ASSOC);

                # The six permission buttons per user were six near-identical blocks of
                # form markup. One table drives them all now.
                $perm_buttons = array(
                    array('set' => 'urls',          'field' => 'edit_urls',    'label' => 'Edit Clients',     'col' => 'edit_urls'),
                    array('set' => 'emerg',         'field' => 'edit_emerg',   'label' => 'Edit Emergency',   'col' => 'edit_emerg'),
                    array('set' => 'user',          'field' => 'edit_user',    'label' => 'Edit Users',       'col' => 'edit_users'),
                    array('set' => 'c_messages',    'field' => 'c_messages',   'label' => 'Custom Messages',  'col' => 'c_messages'),
                    array('set' => 'rss_feeds',     'field' => 'rss_feeds',    'label' => 'Rss Feeds',        'col' => 'rss_feeds'),
                    array('set' => 'edit_options',  'field' => 'edit_options', 'label' => 'UNS Options',      'col' => 'edit_options'),
                );

                $au_stmt = $conn->prepare("SELECT * FROM allowed_users WHERE username != ?");
                $au_stmt->execute([$admin_user]);
                $rows = $au_stmt->fetchAll(PDO::FETCH_ASSOC);

                $users = array();
                foreach($rows as $row)
                {
                    $int_stmt = $conn->prepare("SELECT id,password FROM internal_users WHERE username = ?");
                    $int_stmt->execute([$row['username']]);
                    $int_usr = $int_stmt->fetch(PDO::FETCH_ASSOC);

                    $buttons = array();
                    foreach($perm_buttons as $pb)
                    {
                        $buttons[] = array(
                            'set'     => $pb['set'],
                            'field'   => $pb['field'],
                            'label'   => $pb['label'],
                            'allowed' => !empty($row[$pb['col']]),
                        );
                    }

                    $users[] = array(
                        'id'           => (int)$row['id'],
                        'username'     => $row['username'],
                        'domain'       => $row['domain'],
                        'internal_id'  => (int)($int_usr['id'] ?? 0),
                        'has_password' => !empty($int_usr['password']),
                        'perms'        => $buttons,
                    );
                }

                $vu = uns_smarty();
                $vu->assign('ldap', !empty($LDAP));
                $vu->assign('admin_user', $admin_user);
                $vu->assign('builtin_off', !empty($bia_row['built_in_admin']));
                $vu->assign('builtin_id', (int)($admusr['id'] ?? 0));
                $vu->assign('users', $users);
                echo $vu->fetch('screens/view_users.tpl');
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
                    uns_redirect('func=view_users', 4000);
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
                    uns_redirect('func=view_users', $page_timeout);
                        }else
                        {
                            echo "Failed to Remove Internal User.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                            break;
                        }
                    }else
                    {
                        echo "Removed User.";
                    uns_redirect('func=view_users', $page_timeout);
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
                    uns_redirect('func=view_users', 4000);
                        break;
                    }
                }

                $stmt = $conn->prepare("UPDATE settings SET built_in_admin = ? WHERE id = 1");
                if($stmt->execute([$toggle_admin]))
                {
                    if($toggle_admin){echo "Disabled";}else{echo "Enabled";}
                    echo " Built in Admin";
                    uns_redirect('func=view_users', $page_timeout);
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
                    uns_redirect('func=view_users', $page_timeout);
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
                    uns_redirect('func=view_users', $page_timeout);
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
                    uns_redirect('func=view_users', 4000);
                            break;
                        }
                    }

                    $stmt = $conn->prepare("UPDATE allowed_users SET $column = ? WHERE id = ?");
                    if($stmt->execute([$value, $id]))
                    {
                        echo "Updated $label field.";
                    uns_redirect('func=view_users', $page_timeout);
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
                    uns_redirect('func=view_users', $page_timeout);
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
        case "emerg_target_set":
            if($perms['edit_emerg'])
            {
                uns_emerg_ensure($conn, $driver);
                list($t_scope, $t_target) = uns_parse_scope(filter_input(INPUT_POST, 'scope', FILTER_SANITIZE_SPECIAL_CHARS));
                $t_on   = filter_input(INPUT_POST, 'on', FILTER_SANITIZE_ENCODED) ? 1 : 0;
                $t_mins = (int)filter_input(INPUT_POST, 'minutes', FILTER_SANITIZE_ENCODED);
                $back   = filter_input(INPUT_POST, 'back', FILTER_SANITIZE_SPECIAL_CHARS);
                if($back === '' || $back === null){$back = 'func=edit_emerg';}

                if($t_scope === 'all' || $t_target === '')
                {
                    echo "A targeted emergency needs a group or a client. Use the global switch for every screen at once.";
                    uns_redirect($back, 3000);
                    break;
                }

                # 0 minutes means "until somebody turns it off".
                $until = ($t_on && $t_mins > 0) ? time() + ($t_mins * 60) : 0;

                if(uns_emerg_target_set($conn, $driver, $t_scope, $t_target, $t_on, $until, 'manual', ''))
                {
                    echo ($t_on ? "Emergency mode ON for " : "Emergency mode cleared for ").$t_scope." "
                        .htmlspecialchars($t_target, ENT_QUOTES)
                        .($t_on && $until ? " (until ".date('Y-m-d H:i', $until).")" : "");
                    uns_redirect($back, $page_timeout);
                }else
                {
                    echo "Failed to change the targeted emergency.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "emerg_routes":
            if($perms['edit_emerg'])
            {
                uns_emerg_ensure($conn, $driver);

                $group_names = array();
                $gn = $conn->query("SELECT id, name FROM client_groups ORDER BY name");
                if($gn){while($row = $gn->fetch(PDO::FETCH_ASSOC)){$group_names[(string)$row['id']] = $row['name'];}}
                $client_names = array();
                $cn = $conn->query("SELECT client, friendly FROM friendly ORDER BY friendly");
                if($cn){while($row = $cn->fetch(PDO::FETCH_ASSOC)){$client_names[$row['client']] = $row['friendly'];}}

                $routes = array();
                $rs = $conn->query("SELECT * FROM emerg_routes ORDER BY id");
                if($rs)
                {
                    while($row = $rs->fetch(PDO::FETCH_ASSOC))
                    {
                        $target = (string)$row['target'];
                        if($row['scope'] === 'group'){$where = 'Group: '.($group_names[$target] ?? $target);}
                        elseif($row['scope'] === 'client'){$where = 'Client: '.($client_names[$target] ?? $target);}
                        else{$where = 'All clients';}

                        $routes[] = array(
                            'id'      => (int)$row['id'],
                            'name'    => $row['name'],
                            'where'   => $where,
                            'field'   => $row['field'],
                            'op'      => $row['op'],
                            'value'   => $row['value'],
                            'sev'     => $row['min_severity'],
                            'enabled' => !empty($row['enabled']),
                        );
                    }
                }

                $scopes = array(array('value' => 'all::', 'text' => 'All clients'));
                foreach($group_names as $gid => $gname){$scopes[] = array('value' => 'group:'.$gid, 'text' => 'Group: '.$gname);}
                foreach($client_names as $cid => $cname){$scopes[] = array('value' => 'client:'.$cid, 'text' => 'Client: '.$cname);}

                $er = uns_smarty();
                $er->assign('routes',     $routes);
                $er->assign('scopes',     $scopes);
                $er->assign('fields',     uns_route_fields());
                $er->assign('ops',        uns_route_ops());
                $er->assign('severities', array('Unknown', 'Minor', 'Moderate', 'Severe', 'Extreme'));
                echo $er->fetch('screens/emerg_routes.tpl');
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "add_route":
            if($perms['edit_emerg'])
            {
                uns_emerg_ensure($conn, $driver);
                list($r_scope, $r_target) = uns_parse_scope(filter_input(INPUT_POST, 'scope', FILTER_SANITIZE_SPECIAL_CHARS));
                $r_name  = trim((string)filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
                $r_field = (string)filter_input(INPUT_POST, 'field', FILTER_SANITIZE_SPECIAL_CHARS);
                $r_op    = (string)filter_input(INPUT_POST, 'op', FILTER_SANITIZE_SPECIAL_CHARS);
                $r_value = trim((string)filter_input(INPUT_POST, 'value', FILTER_SANITIZE_SPECIAL_CHARS));
                $r_sev   = (string)filter_input(INPUT_POST, 'min_severity', FILTER_SANITIZE_SPECIAL_CHARS);

                if(!array_key_exists($r_field, uns_route_fields())){$r_field = 'event';}
                if(!array_key_exists($r_op, uns_route_ops())){$r_op = 'contains';}
                if(!in_array($r_sev, array('Unknown', 'Minor', 'Moderate', 'Severe', 'Extreme'), true)){$r_sev = 'Unknown';}

                # A regex that does not compile would throw on every monitor run, so it is
                # rejected here rather than at 3am during an actual alert.
                if($r_op === 'regex' && @preg_match('/'.str_replace('/', '\/', $r_value).'/i', '') === false)
                {
                    echo "That is not a valid regular expression.";
                    uns_redirect('func=emerg_routes', 4000);
                    break;
                }
                # An empty value would match everything, which is never what anyone means.
                if($r_value === '' && $r_op !== 'any')
                {
                    echo "A rule needs something to match on.";
                    uns_redirect('func=emerg_routes', 3000);
                    break;
                }

                $stmt = $conn->prepare("INSERT INTO emerg_routes (name, scope, target, field, op, value, min_severity, enabled)"
                    ." VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                if($stmt && $stmt->execute([$r_name, $r_scope, $r_target, $r_field, $r_op, $r_value, $r_sev]))
                {
                    echo "Added the alert rule.";
                    uns_redirect('func=emerg_routes', $page_timeout);
                }else
                {
                    echo "Failed to add the rule.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "update_routes":
            if($perms['edit_emerg'])
            {
                uns_emerg_ensure($conn, $driver);
                if(!empty($_POST['remove']) && !empty($_POST['routes']) && is_array($_POST['routes']))
                {
                    $del = $conn->prepare("DELETE FROM emerg_routes WHERE id = ?");
                    foreach($_POST['routes'] as $rid){if($del){$del->execute([(int)$rid]);}}
                    echo "Removed the selected rules.";
                    uns_redirect('func=emerg_routes', $page_timeout);
                    break;
                }
                # Same toggle-by-value pattern as the group URL list.
                if(!empty($_POST['routes']) && is_array($_POST['routes']))
                {
                    $cur = $conn->prepare("SELECT enabled FROM emerg_routes WHERE id = ?");
                    $upd = $conn->prepare("UPDATE emerg_routes SET enabled = ? WHERE id = ?");
                    foreach($_POST['routes'] as $rid)
                    {
                        $rid = (int)$rid;
                        if(!$cur || !$cur->execute([$rid])){continue;}
                        $row = $cur->fetch(PDO::FETCH_ASSOC);
                        if(!$row){continue;}
                        if($upd){$upd->execute([empty($row['enabled']) ? 1 : 0, $rid]);}
                    }
                    echo "Updated the selected rules.";
                }
                uns_redirect('func=emerg_routes', $page_timeout);
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "client_groups":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);

                $groups = array();
                $g_stmt = $conn->query("SELECT * FROM client_groups ORDER BY priority DESC, name ASC");
                if($g_stmt)
                {
                    while($row = $g_stmt->fetch(PDO::FETCH_ASSOC))
                    {
                        $m_stmt = $conn->prepare("SELECT COUNT(*) FROM client_group_members WHERE group_id = ?");
                        $m_stmt->execute([$row['id']]);
                        $l_stmt = $conn->prepare("SELECT COUNT(*) FROM group_links WHERE group_id = ?");
                        $l_stmt->execute([$row['id']]);

                        $groups[] = array(
                            'id'       => (int)$row['id'],
                            'name'     => $row['name'],
                            'mode'     => $row['mode'],
                            'priority' => (int)$row['priority'],
                            'active'   => !empty($row['active']),
                            'members'  => (int)$m_stmt->fetchColumn(),
                            'urls'     => (int)$l_stmt->fetchColumn(),
                        );
                    }
                }

                $cg = uns_smarty();
                $cg->assign('groups', $groups);
                echo $cg->fetch('screens/client_groups.tpl');
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "add_group":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);
                $g_name = trim((string)filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
                if($g_name === '')
                {
                    echo "A group needs a name.";
                    uns_redirect('func=client_groups', 3000);
                    break;
                }
                $stmt = $conn->prepare("INSERT INTO client_groups (name, description, mode, priority, active) VALUES (?, '', 'add', 0, 1)");
                if($stmt && $stmt->execute([$g_name]))
                {
                    echo "Added group [".htmlspecialchars($g_name, ENT_QUOTES)."]";
                    uns_redirect('func=client_groups', $page_timeout);
                }else
                {
                    echo "Failed to add group.<br />Probably a duplicate name, check the SQL error below<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "remove_group":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);
                if(!@$_POST['remove'])
                {
                    uns_redirect('func=client_groups', $page_timeout);
                    break;
                }
                foreach($_POST['remove'] as $gid)
                {
                    $gid = (int)$gid;
                    # Membership and the group's URLs go with it; nothing else references a group.
                    $conn->prepare("DELETE FROM client_group_members WHERE group_id = ?")->execute([$gid]);
                    $conn->prepare("DELETE FROM group_links WHERE group_id = ?")->execute([$gid]);
                    $stmt = $conn->prepare("DELETE FROM client_groups WHERE id = ?");
                    if($stmt && $stmt->execute([$gid]))
                    {
                        echo "Removed group [".$gid."]<br />\r\n";
                    }else
                    {
                        echo "Failed to remove group [".$gid."]<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                    }
                }
                uns_redirect('func=client_groups', $page_timeout);
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "edit_group":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);
                $gid   = (int)filter_input(INPUT_GET, 'group', FILTER_SANITIZE_SPECIAL_CHARS);
                $stmt  = $conn->prepare("SELECT * FROM client_groups WHERE id = ?");
                $stmt->execute([$gid]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
                if(!$group)
                {
                    echo "No such group.";
                    uns_redirect('func=client_groups', 3000);
                    break;
                }

                # Which clients are in it. Friendly names come from the friendly table, the
                # same join the client list screen uses.
                $members = array();
                $m_stmt  = $conn->prepare("SELECT client FROM client_group_members WHERE group_id = ?");
                $m_stmt->execute([$gid]);
                while($row = $m_stmt->fetch(PDO::FETCH_ASSOC)){$members[$row['client']] = true;}

                $clients = array();
                $c_stmt  = $conn->query("SELECT friendly.friendly, friendly.client FROM allowed_clients, friendly"
                    ." WHERE friendly.client = allowed_clients.client_name ORDER BY friendly.friendly ASC");
                if($c_stmt)
                {
                    while($row = $c_stmt->fetch(PDO::FETCH_ASSOC))
                    {
                        $clients[] = array(
                            'client'   => $row['client'],
                            'friendly' => $row['friendly'],
                            'member'   => isset($members[$row['client']]),
                        );
                    }
                }

                $urls   = array();
                $u_stmt = $conn->prepare("SELECT * FROM group_links WHERE group_id = ? ORDER BY id ASC");
                $u_stmt->execute([$gid]);
                while($row = $u_stmt->fetch(PDO::FETCH_ASSOC))
                {
                    $urls[] = array(
                        'id'      => (int)$row['id'],
                        'url'     => $row['url'],
                        'label'   => uns_url_label($conn, $host, $row['url']),
                        'refresh' => (int)$row['refresh'],
                        'enabled' => empty($row['disabled']),
                    );
                }

                # Emergency mode for the group itself, shown only to users who are allowed
                # to touch emergency settings at all.
                uns_emerg_ensure($conn, $driver);
                $et_stmt = $conn->prepare("SELECT * FROM emerg_targets WHERE scope = 'group' AND target = ?");
                $et_row  = ($et_stmt && $et_stmt->execute([(string)$gid])) ? $et_stmt->fetch(PDO::FETCH_ASSOC) : false;

                $eg = uns_smarty();
                $eg->assign('can_emerg',   !empty($perms['edit_emerg']));
                $eg->assign('emerg_live',  $et_row ? uns_emerg_target_live($et_row, time()) : false);
                $eg->assign('emerg_until', $et_row ? (int)$et_row['until'] : 0);
                $eg->assign('group',   array(
                    'id'          => (int)$group['id'],
                    'name'        => $group['name'],
                    'description' => $group['description'],
                    'mode'        => $group['mode'],
                    'priority'    => (int)$group['priority'],
                    'active'      => !empty($group['active']),
                ));
                $eg->assign('clients', $clients);
                $eg->assign('urls',    $urls);
                $eg->assign('refresh', (int)$refresh);
                echo $eg->fetch('screens/edit_group.tpl');
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "save_group":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);
                $gid      = (int)filter_input(INPUT_GET, 'group', FILTER_SANITIZE_SPECIAL_CHARS);
                $g_name   = trim((string)filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
                $g_desc   = trim((string)filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS));
                $g_mode   = filter_input(INPUT_POST, 'mode', FILTER_SANITIZE_SPECIAL_CHARS);
                $g_prio   = (int)filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_ENCODED);
                $g_active = filter_input(INPUT_POST, 'active', FILTER_SANITIZE_ENCODED) ? 1 : 0;
                if(!in_array($g_mode, array('add', 'replace'), true)){$g_mode = 'add';}
                if($g_name === '')
                {
                    echo "A group needs a name.";
                    uns_redirect('func=edit_group&group='.$gid, 3000);
                    break;
                }
                $stmt = $conn->prepare("UPDATE client_groups SET name = ?, description = ?, mode = ?, priority = ?, active = ? WHERE id = ?");
                if($stmt && $stmt->execute([$g_name, $g_desc, $g_mode, $g_prio, $g_active, $gid]))
                {
                    echo "Saved group settings.";
                    uns_redirect('func=edit_group&group='.$gid, $page_timeout);
                }else
                {
                    echo "Failed to save group.<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "save_group_members":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);
                $gid = (int)filter_input(INPUT_GET, 'group', FILTER_SANITIZE_SPECIAL_CHARS);

                # The posted set is the membership. Each checkbox carries the client name as
                # its value rather than relying on position, so the usual unticked-checkbox
                # packing problem cannot misalign anything - an absent name simply means
                # "not a member".
                $wanted = array();
                if(!empty($_POST['clients']) && is_array($_POST['clients']))
                {
                    foreach($_POST['clients'] as $c)
                    {
                        if(is_safe_client_id($c)){$wanted[$c] = true;}
                    }
                }

                # Only accept clients that actually exist, so a hand-crafted POST can't
                # create membership rows for a client that was never registered.
                $valid = array();
                $c_stmt = $conn->query("SELECT client_name FROM allowed_clients");
                if($c_stmt)
                {
                    while($row = $c_stmt->fetch(PDO::FETCH_ASSOC))
                    {
                        if(isset($wanted[$row['client_name']])){$valid[] = $row['client_name'];}
                    }
                }

                $conn->prepare("DELETE FROM client_group_members WHERE group_id = ?")->execute([$gid]);
                $ins = $conn->prepare("INSERT INTO client_group_members (group_id, client) VALUES (?, ?)");
                $added = 0;
                foreach($valid as $c)
                {
                    if($ins && $ins->execute([$gid, $c])){$added++;}
                }
                echo "Group now has ".$added." client".($added == 1 ? "" : "s").".";
                uns_redirect('func=edit_group&group='.$gid, $page_timeout);
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "add_group_url":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);
                $gid   = (int)filter_input(INPUT_GET, 'group', FILTER_SANITIZE_SPECIAL_CHARS);
                $g_url = trim((string)filter_input(INPUT_POST, 'url', FILTER_SANITIZE_SPECIAL_CHARS));
                $g_ref = (int)filter_input(INPUT_POST, 'refresh', FILTER_SANITIZE_ENCODED);
                if($g_ref < 1){$g_ref = (int)$refresh;}
                if($g_url === '' || $g_url === 'http://')
                {
                    echo "No URL given.";
                    uns_redirect('func=edit_group&group='.$gid, 3000);
                    break;
                }
                $stmt = $conn->prepare("INSERT INTO group_links (group_id, url, disabled, refresh) VALUES (?, ?, 0, ?)");
                if($stmt && $stmt->execute([$gid, $g_url, $g_ref]))
                {
                    echo "Added URL to group.";
                    uns_redirect('func=edit_group&group='.$gid, $page_timeout);
                }else
                {
                    echo "Failed to add URL.<br />Probably already in this group, check the SQL error below<br />".htmlspecialchars(db_error($conn), ENT_QUOTES);
                }
            }else
            {
                echo "Ummm, you shouldn't be here.. I think you should leave before the droids come. O_o";
            }
            break;
        case "update_group_urls":
            if($perms['edit_urls'])
            {
                uns_groups_ensure($conn, $driver);
                $gid = (int)filter_input(INPUT_GET, 'group', FILTER_SANITIZE_SPECIAL_CHARS);

                # Removing takes precedence over the refresh/enable edits in the same form.
                if(@$_POST['remove'] && !empty($_POST['urls']) && is_array($_POST['urls']))
                {
                    $del = $conn->prepare("DELETE FROM group_links WHERE id = ? AND group_id = ?");
                    foreach($_POST['urls'] as $uid)
                    {
                        if($del){$del->execute([(int)$uid, $gid]);}
                    }
                    echo "Removed the selected URLs.";
                    uns_redirect('func=edit_group&group='.$gid, $page_timeout);
                    break;
                }

                # url_id[] and refresh_t[] are hidden/text inputs, always submitted, so they
                # stay index-aligned. The enable toggles are checkboxes and cannot be, so
                # each one carries its own row id as its value instead.
                $toggled = array();
                if(!empty($_POST['urls']) && is_array($_POST['urls']))
                {
                    foreach($_POST['urls'] as $uid){$toggled[(int)$uid] = true;}
                }

                $ids  = isset($_POST['url_id'])    && is_array($_POST['url_id'])    ? $_POST['url_id']    : array();
                $refs = isset($_POST['refresh_t']) && is_array($_POST['refresh_t']) ? $_POST['refresh_t'] : array();

                $upd = $conn->prepare("UPDATE group_links SET refresh = ?, disabled = ? WHERE id = ? AND group_id = ?");
                $cur = $conn->prepare("SELECT disabled FROM group_links WHERE id = ? AND group_id = ?");
                $n   = 0;
                foreach($ids as $i => $uid)
                {
                    $uid = (int)$uid;
                    $ref = isset($refs[$i]) ? (int)$refs[$i] : 0;
                    if($ref < 1){$ref = (int)$refresh;}

                    if(!$cur || !$cur->execute([$uid, $gid])){continue;}
                    $row = $cur->fetch(PDO::FETCH_ASSOC);
                    if(!$row){continue;}

                    # A ticked box flips this row's enabled state; an unticked one leaves it.
                    $disabled = !empty($row['disabled']) ? 1 : 0;
                    if(isset($toggled[$uid])){$disabled = $disabled ? 0 : 1;}

                    if($upd && $upd->execute([$ref, $disabled, $uid, $gid])){$n++;}
                }
                echo "Updated ".$n." URL".($n == 1 ? "" : "s").".";
                uns_redirect('func=edit_group&group='.$gid, $page_timeout);
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
                    uns_redirect('', $page_timeout);
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
                            # Membership is keyed by client name, so leaving these rows behind
                            # would silently re-attach the old groups to any later client
                            # registered under the same name.
                            uns_groups_forget_client($conn, $driver, $id);
                            # $id is whitelisted by is_safe_client_id() above, safe as a table name here.
                            if($conn->query("DROP TABLE ".$id."_links"))
                            {
                                echo "Removed client [$id_esc]<br />\r\n";
                    uns_redirect('', $page_timeout);
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

    $screen = ob_get_clean();

    $panel_smarty = uns_smarty();
    $panel_smarty->assign('side_links', $side_links);
    $panel_smarty->assign('nav_items', $nav_items);
    $panel_smarty->assign('username', $usr);
    $panel_smarty->assign('screen', $screen);
    echo $panel_smarty->fetch('partials/panel.tpl');
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