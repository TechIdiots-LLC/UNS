<?php
#    index.php, Client page, grabs the URL for the client ID supplied
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

include "configs/vars.php";
include "shared.php";

gen_base_urls(".");
$proto = $GLOBALS['proto'];
$admin_url = $GLOBALS['admin_url'];
$reg_url = $GLOBALS['reg_url'];

if(!check_install('.'))
{
    echo "You need to Install or Upgrade first.<br /><a href='install.php'>Install Page</a>";
    die();
}
date_default_timezone_set($TZ);
$scroll_code = '';
$out = @strtolower(filter_input(INPUT_GET, 'out', FILTER_SANITIZE_SPECIAL_CHARS));
$client = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_SPECIAL_CHARS);
if($led_blink){blinky(get_client_led_id($client));}
if($client != "")
{
    $chosen_one = get_client_url($client);
    $emerg = $chosen_one[2];
}else
{
    $emerg = 0;
}

if($out != "")
{
    switch($out)
    {
        case "xml":
            header ("Content-Type:text/xml");
            if(is_string($client))
            {
                switch($chosen_one[0])
                {
                    case "bad_client":
                        $data = "<error>1</error>\r\n<url>".$reg_url."html/bad_client.html</url>
<refresh>60</refresh>\r\n";
                        if($emerg)
                        {
                            $data .= "<emerg>1</emerg>\r\n";
                        }else
                        {
                            $data .= "<emerg>0</emerg>\r\n";
                        }
                        break;
                    case "no_urls":
                        $data = "<error>1</error>\r\n<url>".$reg_url."html/no_urls.html</url>
<refresh>60</refresh>\r\n";
                        if($emerg)
                        {
                            $data .= "<emerg>1</emerg>\r\n";
                        }else
                        {
                            $data .= "<emerg>0</emerg>\r\n";
                        }
                        break;
                    default:
                        $data = "<error>0</error>\r\n<url><![CDATA[".$chosen_one[0]."]]></url>
<refresh>".$chosen_one[1]."</refresh>\r\n";
                        if($emerg)
                        {
                            $data .= "<emerg>1</emerg>\r\n";
                        }else
                        {
                            $data .= "<emerg>0</emerg>\r\n";
                        }
                        break;
                }
                echo '<?xml version="1.0" encoding="utf-8"?>
<uns>
'.$data."</uns>";
            }else
            {
                echo '<?xml version="1.0" encoding="utf-8"?>
';
                include "configs/conn.php";
                if(!isset($driver)){$driver = 'mysql';}
                $conn = db_connect($server, $username, $password, $db, $driver);
                $stmt = $conn ? $conn->query("SELECT friendly.friendly,friendly.client FROM allowed_clients,friendly WHERE friendly.client = allowed_clients.client_name ORDER by friendly.friendly ASC") : false;
                $NN=0;
                $data = "<clients>\r\n";
                while($stmt && ($clients = $stmt->fetch(PDO::FETCH_ASSOC)))
                {
                    $client_esc = htmlspecialchars($clients['client'], ENT_QUOTES);
                    $friendly_esc = htmlspecialchars($clients['friendly'], ENT_QUOTES);
                    $data .= '   <client ref="'.$reg_url.'index.php?id='.$client_esc.'" id="'.$client_esc.'">'.$friendly_esc.'</client>
';
                    $NN++;
                }
                $data .= "</clients>";
                if(!$NN)
                {
                    echo '<error>There are no clients. How do you expect to let people know whats going on with no way to display it. Go add some.</error>';
                    die();
                }else
                {
                    echo $data;
                }
            }
            break;
        default:
            echo '<?xml version="1.0" encoding="utf-8"?>
<error>Only XML is supported as an alternate output.</error>';
            break;
    }
}else
{
    if(is_string($client))
    {
        switch($chosen_one[0])
        {
            case "bad_client":
                $head = $scroll_code.'<meta http-equiv="refresh" content="60;/'.$root.'index.php?id='.$client.'">';
                $body = '<iframe src="'.$reg_url.'html/bad_client.html" border="0" width="100%" scrolling="no" height="100%">
        </iframe>';
                break;
            case "no_urls":
                $head = $scroll_code.'<meta http-equiv="refresh" content="60;/'.$root.'index.php?id='.$client.'">';
                $body = '<iframe src="'.$reg_url.'html/no_urls.html" border="0" scrolling="no" width="100%" height="100%">
        </iframe>';
                break;
            default:
                $head = $scroll_code.'<meta http-equiv="refresh" content="'.$chosen_one[1].';/'.$root.'index.php?id='.$client.'">';
                $body = '<iframe src="'.$chosen_one[0].'" width="100%" border="0" scrolling="no" height="100%">
        </iframe>';
                break;
        }

    }else
    {
        include "configs/conn.php";
        if(!isset($driver)){$driver = 'mysql';}
        $head = $scroll_code;
        $body = '<div align="center"><table width="75%"><tr><th>Clients</th></tr>';
        $conn = db_connect($server, $username, $password, $db, $driver);
        $stmt = $conn ? $conn->query("SELECT friendly.friendly,friendly.client FROM allowed_clients,friendly WHERE friendly.client = allowed_clients.client_name ORDER by friendly.friendly ASC") : false;
        $NN=0;
        while($stmt && ($clients = $stmt->fetch(PDO::FETCH_ASSOC)))
        {
            $client_esc = htmlspecialchars($clients['client'], ENT_QUOTES);
            $friendly_esc = htmlspecialchars($clients['friendly'], ENT_QUOTES);
            $body .= '
            <tr>
                <td>
                    <a href="'.$reg_url.'index.php?id='.$client_esc.'" target="_blank">'.$friendly_esc.'</a>
                </td>
            </tr>';
            $NN++;
        }
        if(!$NN)
        {
            $body .= '
            <tr>
                <td>
                    There are no clients.<br /><font size="2">How do you expect to let people know whats going on with no way to display it. <a href="'.$admin_url.'admin/index.php">Go add some.</a></font>
                </td>
            </tr>';
        }
        $body .= "</table></div>";
    }
    ?>
<html>
    <head>
        <title>UNS</title>
        <?php echo $head; ?>
    </head>
    <body style="margin: 0px 0px 0px 0px;" >
        <?php echo $body; ?>
    </body>
</html>
<?php
}

#####
## Functions
#####
# Client IDs become part of a dynamically-named table ("<id>_links"), which can't be
# parameterized in a prepared statement - so it's validated against a strict whitelist
# instead, both here and in get_client_url()/check_conn_tbl().
function is_safe_client_id($client)
{
    return is_string($client) && $client !== "" && preg_match('/^[A-Za-z0-9_]+$/', $client) === 1;
}

function get_client_led_id($client)
{
    if(!is_safe_client_id($client)){return 0;}
    include "configs/conn.php";
    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    if(!$conn)
    {
        printf("Connect failed: %s\n", db_error($conn));
        exit();
    }
    $stmt = $conn->prepare("SELECT led FROM allowed_clients where client_name = ?");
    $stmt->execute([$client]);
    $array = $stmt->fetch(PDO::FETCH_ASSOC);
    if(empty($array['led']))
    {
        return 0;
    }else
    {
        return $array['led'];
    }

}
function get_client_url($client)
{
    $ret = array();
    include "configs/conn.php";
    if(!isset($driver)){$driver = 'mysql';}
    $conn = db_connect($server, $username, $password, $db, $driver);
    if(!$conn)
    {
        return array(db_error($conn), 0);
    }
    if(!is_safe_client_id($client))
    {
        return array("bad_client", 0);
    }
    $stmt = $conn->prepare("SELECT * FROM allowed_clients where client_name = ?");
    $stmt->execute([$client]);
    $array = $stmt->fetch(PDO::FETCH_ASSOC);

    if(empty($array['client_name']))
    {
        return array("bad_client", 0);
    }
    $stmt = $conn->query("SELECT * FROM settings");
    $array = $stmt->fetch(PDO::FETCH_ASSOC);

    # Emergency mode is either global (settings.emerg, every client) or targeted at a
    # group or single client. uns_emerg_for_client() applies the precedence and hands
    # back the URLs that go with whichever one is in force.
    list($emerg_fl, $emerg_urls) = uns_emerg_for_client($conn, $driver, $client, !empty($array['emerg']));

    if(!$emerg_fl)
    {
        # The client's own list. $client is whitelisted to [A-Za-z0-9_]+ above, so it's
        # safe to use as a table name here.
        $own = array();
        $stmt = $conn->query("SELECT url, refresh FROM ".$client."_links where disabled != '1'");
        if($stmt)
        {
            while($array = $stmt->fetch(PDO::FETCH_ASSOC))
            {
                $own[] = array($array['url'], $array['refresh']);
            }
        }

        # Any groups this client belongs to can add to that list, or replace it.
        $ret = uns_resolve_client_urls($conn, $driver, $client, $own);

        # Don't serve the same URL twice running, so a rotation actually rotates.
        #
        # This used to be an "AND url != ?" on the query above, which now would only
        # cover the client's own table - a group URL could repeat forever. Filtering
        # the resolved list here covers group URLs too. If filtering empties the list
        # (a client with exactly one URL) the unfiltered list stands, which is what
        # the old fall-back query did.
        $stmt = $conn->prepare("SELECT last_url FROM connections where client = ? ORDER by last_conn DESC");
        if($stmt && $stmt->execute([$client]))
        {
            $prev = $stmt->fetch(PDO::FETCH_ASSOC);
            if($prev)
            {
                $filtered = array();
                foreach($ret as $link)
                {
                    if($link[0] !== $prev['last_url']){$filtered[] = $link;}
                }
                if(!empty($filtered)){$ret = $filtered;}
            }
        }

    }else
    {
        # Already resolved above: the URLs for whichever emergency is in force, global
        # or targeted at this client's group or the client itself.
        foreach($emerg_urls as $link)
        {
            $ret[] = array($link[0], $link[1], 1);
        }
    }
    if(empty($ret[0]))
    {
        $ret1 = array("no_urls", 0);
    }else
    {
        $pick = array_rand($ret);
        $ret1 = array($ret[$pick][0], $ret[$pick][1]);
    }
    $time = time();
    $last_url = $ret1[0];
    check_conn_tbl($client);
    $stmt = $conn->prepare("INSERT INTO connections (client, last_conn, last_url) VALUES (?, ?, ?)");
    if(!$stmt->execute([$client, $time, $last_url]))
    {
        return array(db_error($stmt), 0);
    }

    if($emerg_fl)
    {
        $r = array(html_entity_decode($ret1[0], ENT_QUOTES), $ret1[1], 1);
    }else
    {
        $r = array(html_entity_decode($ret1[0], ENT_QUOTES), $ret1[1], 0);
    }
    return $r;
}



function check_conn_tbl($client)
{
    if(!is_safe_client_id($client)){return -1;}
    include "configs/vars.php";
    include "configs/conn.php";
    if(!isset($driver)){$driver = 'mysql';}
    if(!$conn = db_connect($server, $username, $password, $db, $driver))
    {return -1;}
    $stmt = $conn->prepare("SELECT * FROM connections WHERE client = ?");
    if(!$stmt->execute([$client])){return -1;}
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if($max_conn_hist == count($rows))
    {
        $stmt = $conn->prepare("SELECT id FROM connections WHERE client = ? ORDER BY last_conn ASC");
        $stmt->execute([$client]);
        $oldest = $stmt->fetch(PDO::FETCH_ASSOC);
        if($oldest)
        {
            $del_stmt = $conn->prepare("DELETE FROM connections WHERE id = ?");
            if(!$del_stmt->execute([$oldest['id']])){return -1;}
        }
        return 1;
    }else
    {
        return 0;
    }
}
?>
