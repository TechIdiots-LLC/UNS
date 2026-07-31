<?php
#    shared.php, Some shared functions for both the Client page and the admin page
#    Copyright (C) 2010  Phillip Ferland
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

# Since PHP 8.1, mysqli throws mysqli_sql_exception on errors by default (MYSQLI_REPORT_ERROR |
# MYSQLI_REPORT_STRICT) instead of returning false. This whole codebase was written against the
# old "check the return value" behavior (eg. `if(!$conn = mysqli_connect(...)){return -1;}`), so
# restore that here rather than rewriting every call site to a try/catch.
mysqli_report(MYSQLI_REPORT_OFF);

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

    $conn = @new mysqli($server, $username, $password, $db);
    if(!$conn || $conn->connect_error){echo "Failed to connect: ".($conn ? $conn->connect_error : "unknown error"); return 0;}

    $sql = "SELECT `uns_ver` FROM `settings` ORDER BY `id` ASC LIMIT 1";
    if($result = @$conn->query($sql, MYSQLI_STORE_RESULT))
    {$uns_ver = $result->fetch_array(MYSQLI_ASSOC);}
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

    $conn = new mysqli($server, $username, $password, $db);
    $sql = "SELECT emerg FROM settings LIMIT 1";
    $result = $conn->query($sql, MYSQLI_STORE_RESULT);
    $emerg = $result->fetch_array(MYSQLI_ASSOC);

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
