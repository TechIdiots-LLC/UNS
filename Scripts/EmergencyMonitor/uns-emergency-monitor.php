#!/usr/bin/env php
<?php
#    uns-emergency-monitor.php, watches an alert feed and drives UNS emergency mode.
#
#    Cross-platform replacement for Scripts/RaveRssEmergencyTriggerTask (AutoIt, Windows
#    only, MySQL only). Run it every minute from cron or Task Scheduler - see README.txt.
#
#    It reads the UNS database settings from the install itself, so it works with MySQL,
#    SQL Server and SQLite without repeating any credentials, and understands CAP as well
#    as RSS/Atom.
#
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

# This drives emergency mode for every display, so it must never be reachable over HTTP.
if(PHP_SAPI !== 'cli')
{
    header('HTTP/1.0 403 Forbidden');
    die("uns-emergency-monitor.php is a command line script.\n");
}

date_default_timezone_set('UTC');

# ---------------------------------------------------------------------------
# Exit codes, so a scheduler or monitoring system can tell these apart.
# ---------------------------------------------------------------------------
define('UNS_OK', 0);          # ran fine (whether or not an alert was active)
define('UNS_ERR_CONFIG', 1);  # bad config / could not reach the UNS install or database
define('UNS_ERR_FEED', 2);    # could not fetch or parse the feed

$GLOBALS['uns_log_file'] = '';
$GLOBALS['uns_verbose']  = false;

function uns_log($msg, $always = true)
{
    if(!$always && !$GLOBALS['uns_verbose']){return;}
    $line = gmdate('Y-m-d H:i:s')."Z  ".$msg."\n";
    echo $line;
    if($GLOBALS['uns_log_file'] !== '')
    {
        @file_put_contents($GLOBALS['uns_log_file'], $line, FILE_APPEND);
    }
}

function uns_fail($code, $msg)
{
    uns_log("ERROR: ".$msg);
    exit($code);
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
$config_path = __DIR__.'/monitor.conf.php';
$dry_run = false;
$force_verbose = false;

foreach(array_slice($argv, 1) as $arg)
{
    if(strpos($arg, '--config=') === 0){$config_path = substr($arg, 9);}
    elseif($arg === '--dry-run'){$dry_run = true;}
    elseif($arg === '--verbose' || $arg === '-v'){$force_verbose = true;}
    elseif($arg === '--help' || $arg === '-h')
    {
        echo "Usage: php uns-emergency-monitor.php [--config=PATH] [--dry-run] [--verbose]\n\n"
            ."  --config=PATH  configuration file (default: monitor.conf.php beside this script)\n"
            ."  --dry-run      report what would change without writing to the database\n"
            ."  --verbose      show the parsed alert and each decision\n\n"
            ."Exit codes: 0 ok, 1 config/database problem, 2 feed problem.\n";
        exit(UNS_OK);
    }
    else{uns_fail(UNS_ERR_CONFIG, "Unknown argument: ".$arg);}
}

# The feed URL and its options are set from UNS Options in the admin panel and stored in
# the database, so this file is optional: it only says where UNS is, and can override the
# few things that are not worth a database round trip (timeouts, logging).
if(is_readable($config_path)){require $config_path;}

# Where UNS lives. Default assumes this script is in Scripts/EmergencyMonitor/ inside a
# checkout; a deployed copy elsewhere sets $uns_root in monitor.conf.php.
if(!isset($uns_root) || $uns_root === ''){$uns_root = dirname(__DIR__, 2).'/Server/www';}
if(!isset($message_name)){$message_name = 'Emergency Alert (automatic)';}
if(!isset($message_refresh)){$message_refresh = 30;}
if(!isset($http_timeout)){$http_timeout = 15;}
if(!isset($log_file)){$log_file = '';}
if(!isset($verbose)){$verbose = false;}

$GLOBALS['uns_log_file'] = $log_file;
$GLOBALS['uns_verbose']  = $verbose || $force_verbose;

# ---------------------------------------------------------------------------
# UNS install - reuse its database layer rather than repeating credentials
# ---------------------------------------------------------------------------
$uns_root = rtrim(str_replace('\\', '/', $uns_root), '/');
if(!is_readable($uns_root.'/shared.php'))
{
    uns_fail(UNS_ERR_CONFIG, "No UNS install at ".$uns_root." (expected shared.php there). Check \$uns_root.");
}
require $uns_root.'/shared.php';

if(!is_readable($uns_root.'/configs/conn.php'))
{
    uns_fail(UNS_ERR_CONFIG, "No configs/conn.php under ".$uns_root." - has UNS been installed yet?");
}
include $uns_root.'/configs/conn.php';   # $driver, $server, $username, $password, $db
include $uns_root.'/configs/vars.php';   # $host, $root, $SSL - used to build the message URL

if(!isset($driver)){$driver = 'mysql';}

# ---------------------------------------------------------------------------
# Settings, from the uns_config table (UNS Options -> Emergency Alert Monitor)
# ---------------------------------------------------------------------------
$conn = db_connect($server, $username, $password, $db, $driver);
if(!$conn)
{
    uns_fail(UNS_ERR_CONFIG, "Could not connect to the UNS database: ".db_error($conn));
}

$cfg = uns_config_all($conn, $driver);

# A config file may still override any of these, which is handy for testing a different
# feed without touching the live settings.
if(!isset($feed_url)){$feed_url = isset($cfg['emerg_feed_url']) ? $cfg['emerg_feed_url'] : '';}
if(!isset($display_minutes)){$display_minutes = isset($cfg['emerg_display_minutes']) ? (int)$cfg['emerg_display_minutes'] : 30;}
if(!isset($publish_message)){$publish_message = isset($cfg['emerg_publish_message']) ? (bool)(int)$cfg['emerg_publish_message'] : true;}
if(!isset($min_severity)){$min_severity = isset($cfg['emerg_min_severity']) ? $cfg['emerg_min_severity'] : 'Unknown';}
if(!isset($max_items)){$max_items = isset($cfg['emerg_max_items']) ? (int)$cfg['emerg_max_items'] : 5;}
if(!isset($follow_cap_links)){$follow_cap_links = isset($cfg['emerg_follow_cap_links']) ? (bool)(int)$cfg['emerg_follow_cap_links'] : true;}
if(!isset($allowed_status))
{
    $raw_status = isset($cfg['emerg_allowed_status']) ? $cfg['emerg_allowed_status'] : 'Actual';
    $allowed_status = array_values(array_filter(array_map('trim', explode(',', $raw_status)), 'strlen'));
    if(!$allowed_status){$allowed_status = array('Actual');}
}
if($max_items < 1){$max_items = 1;}
if($display_minutes < 1){$display_minutes = 30;}

if($feed_url === '')
{
    # Not an error: this is how an administrator switches the monitor off.
    uns_log("No alert feed is configured (UNS Options -> Emergency Alert Monitor). Nothing to do.", false);
    exit(UNS_OK);
}

# ---------------------------------------------------------------------------
# Feed fetching
# ---------------------------------------------------------------------------
function uns_fetch($url, $timeout)
{
    if(function_exists('curl_init'))
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'UNS-EmergencyMonitor/1.0');
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($body === false){return array(false, $err !== '' ? $err : 'curl failed');}
        if($code >= 400){return array(false, 'HTTP '.$code);}
        return array($body, '');
    }

    $ctx = stream_context_create(array('http' => array(
        'timeout' => $timeout,
        'user_agent' => 'UNS-EmergencyMonitor/1.0',
        'follow_location' => 1,
    )));
    $body = @file_get_contents($url, false, $ctx);
    if($body === false){return array(false, 'file_get_contents failed (allow_url_fopen may be off, and curl is unavailable)');}
    return array($body, '');
}

function uns_parse_xml($raw)
{
    if(trim((string)$raw) === ''){return false;}
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $xml === false ? false : $xml;
}

# ---------------------------------------------------------------------------
# CAP - https://docs.oasis-open.org/emergency/cap/v1.2/CAP-v1.2-os.html
# ---------------------------------------------------------------------------
function uns_cap_namespace($xml)
{
    foreach($xml->getDocNamespaces(true) as $uri)
    {
        if(strpos($uri, 'urn:oasis:names:tc:emergency:cap') === 0){return $uri;}
    }
    return null;
}

function uns_is_cap($xml)
{
    return $xml !== false && $xml->getName() === 'alert' && uns_cap_namespace($xml) !== null;
}

# Severity is ranked so a minimum can be configured; anything unrecognised sorts lowest.
function uns_severity_rank($severity)
{
    $order = array('Unknown' => 0, 'Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4);
    return isset($order[$severity]) ? $order[$severity] : 0;
}

# Returns a normalised alert array, or null when the document is not usable.
function uns_parse_cap($xml)
{
    $ns = uns_cap_namespace($xml);
    $a  = $ns !== null ? $xml->children($ns) : $xml;

    $alert = array(
        'source'      => 'cap',
        'identifier'  => trim((string)$a->identifier),
        'sender'      => trim((string)$a->sender),
        'sent'        => trim((string)$a->sent),
        'status'      => trim((string)$a->status),
        'msg_type'    => trim((string)$a->msgType),
        'event'       => '',
        'headline'    => '',
        'description' => '',
        'instruction' => '',
        'severity'    => '',
        'urgency'     => '',
        'expires'     => '',
        'web'         => '',
    );

    # A CAP alert may carry several <info> blocks (typically one per language).
    # Take the first, which is the primary one in every feed we care about.
    $info = null;
    foreach($a->info as $i){$info = $i; break;}
    if($info !== null)
    {
        $alert['event']       = trim((string)$info->event);
        $alert['headline']    = trim((string)$info->headline);
        $alert['description'] = trim((string)$info->description);
        $alert['instruction'] = trim((string)$info->instruction);
        $alert['severity']    = trim((string)$info->severity);
        $alert['urgency']     = trim((string)$info->urgency);
        $alert['expires']     = trim((string)$info->expires);
        $alert['web']         = trim((string)$info->web);
    }
    return $alert;
}

# ---------------------------------------------------------------------------
# RSS / Atom - only the newest item matters
# ---------------------------------------------------------------------------
# Reads up to $max entries from an RSS or Atom feed, newest first.
#
# Every entry is examined, not just the newest: several alerts can be in force at once,
# and a feed whose newest entry has expired (or was a short test) may still carry a live
# one below it. Only looking at entry one would report all-clear in that case.
function uns_parse_feed_items($xml, $max)
{
    $list = null;
    $kind = '';

    if(isset($xml->channel) && isset($xml->channel->item)){$list = $xml->channel->item; $kind = 'rss';}
    elseif(isset($xml->item)){$list = $xml->item; $kind = 'rss';}             # RSS 1.0 / RDF
    elseif(isset($xml->entry)){$list = $xml->entry; $kind = 'atom';}          # Atom

    if($list === null){return array();}

    $items = array();
    foreach($list as $item)
    {
        if(count($items) >= $max){break;}
        $items[] = uns_item_to_alert($item, $kind);
    }
    return $items;
}

function uns_item_to_alert($item, $kind)
{
    # The old AutoIt script picked the timestamp by position - //item/*[6] - with a
    # comment admitting it was a guess. Read the actual elements instead, covering
    # RSS 2.0 (pubDate), RSS 1.0 (dc:date) and Atom (updated/published).
    $when = '';
    foreach(array('pubDate', 'date', 'updated', 'published') as $field)
    {
        if(isset($item->$field) && trim((string)$item->$field) !== ''){$when = trim((string)$item->$field); break;}
    }
    if($when === '')
    {
        $dc = $item->children('http://purl.org/dc/elements/1.1/');
        if(isset($dc->date)){$when = trim((string)$dc->date);}
    }

    $link = '';
    if(isset($item->link))
    {
        # Atom puts the target in an href attribute; RSS uses the element text.
        $href = (string)$item->link['href'];
        $link = $href !== '' ? $href : trim((string)$item->link);
    }

    return array(
        'source'      => $kind,
        'identifier'  => isset($item->guid) ? trim((string)$item->guid) : $link,
        'sender'      => '',
        'sent'        => $when,
        'status'      => 'Actual',   # plain feeds carry no status; assume real
        'msg_type'    => 'Alert',
        'event'       => '',
        'headline'    => isset($item->title) ? trim((string)$item->title) : '',
        'description' => isset($item->description) ? trim((string)$item->description)
                          : (isset($item->summary) ? trim((string)$item->summary) : ''),
        'instruction' => '',
        'severity'    => '',
        'urgency'     => '',
        'expires'     => '',
        'web'         => $link,
        'link'        => $link,
    );
}

# When several alerts are in force at once, this decides which one's text gets shown.
# Severity first, so an Extreme alert is never hidden behind a newer Minor one; newest
# wins on a tie. RSS entries carry no severity, so they all rank equal and the newest
# simply wins - the same behaviour as before multi-entry scanning.
function uns_alert_beats($candidate, $current)
{
    $cs = uns_severity_rank($candidate['severity']);
    $ns = uns_severity_rank($current['severity']);
    if($cs !== $ns){return $cs > $ns;}

    $ct = $candidate['sent'] !== '' ? strtotime($candidate['sent']) : 0;
    $nt = $current['sent'] !== '' ? strtotime($current['sent']) : 0;
    return $ct > $nt;
}

# ---------------------------------------------------------------------------
# Decide whether the alert should have emergency mode on right now
# ---------------------------------------------------------------------------
function uns_alert_is_active($alert, $now, $display_minutes, $allowed_status, $min_severity, &$why)
{
    # CAP can explicitly retract an alert. Nothing in plain RSS can.
    if(strcasecmp($alert['msg_type'], 'Cancel') === 0)
    {
        $why = 'CAP msgType is Cancel';
        return false;
    }

    if($alert['status'] !== '' && !in_array($alert['status'], $allowed_status, true))
    {
        $why = 'status "'.$alert['status'].'" is not in the allowed list ('.implode(', ', $allowed_status).')';
        return false;
    }

    if($alert['severity'] !== '' && uns_severity_rank($alert['severity']) < uns_severity_rank($min_severity))
    {
        $why = 'severity "'.$alert['severity'].'" is below the minimum "'.$min_severity.'"';
        return false;
    }

    # Prefer the alert's own expiry. This is the main advantage of CAP over the old
    # timestamp-window guess: no clock-skew fudging, the publisher states the end.
    if($alert['expires'] !== '')
    {
        $exp = strtotime($alert['expires']);
        if($exp === false)
        {
            $why = 'could not parse expires "'.$alert['expires'].'"';
            return false;
        }
        if($now >= $exp)
        {
            $why = 'expired at '.gmdate('Y-m-d H:i:s', $exp).'Z';
            return false;
        }
        $why = 'active until '.gmdate('Y-m-d H:i:s', $exp).'Z (from CAP expires)';
        return true;
    }

    if($alert['sent'] === '')
    {
        $why = 'no timestamp and no expiry in the feed';
        return false;
    }
    $sent = strtotime($alert['sent']);
    if($sent === false)
    {
        $why = 'could not parse timestamp "'.$alert['sent'].'"';
        return false;
    }
    $ends = $sent + ($display_minutes * 60);
    if($now >= $ends)
    {
        $why = 'sent '.round(($now - $sent) / 60).' minutes ago, past the '.$display_minutes.' minute window';
        return false;
    }
    $why = 'sent '.round(($now - $sent) / 60).' minutes ago, within the '.$display_minutes.' minute window';
    return true;
}

# ---------------------------------------------------------------------------
# Fetch and parse
# ---------------------------------------------------------------------------
uns_log("Checking ".$feed_url, false);
list($raw, $err) = uns_fetch($feed_url, $http_timeout);
if($raw === false)
{
    # Leaving emergency mode stuck on because a feed is unreachable would be worse than
    # clearing it, but so would clearing a real alert because of a blip. Do neither:
    # report and leave the current state alone.
    uns_fail(UNS_ERR_FEED, "Could not fetch the feed (".$err."). Emergency mode left unchanged.");
}

$xml = uns_parse_xml($raw);
if($xml === false)
{
    uns_fail(UNS_ERR_FEED, "Feed did not parse as XML. Emergency mode left unchanged.");
}

$alerts = array();
if(uns_is_cap($xml))
{
    $alerts[] = uns_parse_cap($xml);
    uns_log("Detected a CAP document.", false);
}
else
{
    $alerts = uns_parse_feed_items($xml, $max_items);
    if(!$alerts)
    {
        uns_fail(UNS_ERR_FEED, "Feed is neither CAP nor an RSS/Atom feed with items.");
    }
    uns_log("Detected an ".strtoupper($alerts[0]['source'])." feed with ".count($alerts)." entr".(count($alerts) === 1 ? "y" : "ies")." to check.", false);

    # CAP-over-RSS: an entry is only a pointer, the real alert is the linked document.
    # This costs one request per linked entry, which is why $max_items is bounded.
    if($follow_cap_links)
    {
        foreach($alerts as $k => $candidate)
        {
            if(empty($candidate['link']) || !preg_match('/\.(xml|cap)(\?|$)/i', $candidate['link'])){continue;}

            uns_log("Entry ".($k + 1)." links to what looks like CAP: ".$candidate['link'], false);
            list($cap_raw, $cap_err) = uns_fetch($candidate['link'], $http_timeout);
            if($cap_raw === false)
            {
                uns_log("Could not fetch it (".$cap_err."); using the feed entry itself.", false);
                continue;
            }
            $cap_xml = uns_parse_xml($cap_raw);
            if(uns_is_cap($cap_xml))
            {
                $alerts[$k] = uns_parse_cap($cap_xml);
                uns_log("Followed the link and parsed entry ".($k + 1)." as CAP.", false);
            }
            else{uns_log("Linked document is not CAP; using the feed entry itself.", false);}
        }
    }
}

# Emergency mode goes on when ANY scanned alert is still in force. The one that wins
# uns_alert_beats() supplies the text that gets published.
$now = time();
$active = false;
$alert = $alerts[0];
$why = '';
$first_why = '';

foreach($alerts as $idx => $candidate)
{
    $this_why = '';
    $is_on = uns_alert_is_active($candidate, $now, $display_minutes, $allowed_status, $min_severity, $this_why);
    if($idx === 0){$first_why = $this_why;}

    uns_log("  entry ".($idx + 1).": ".($is_on ? "IN FORCE" : "not active")." - ".$this_why
        .($candidate['headline'] !== '' ? "  [".$candidate['headline']."]" : ""), false);

    if(!$is_on){continue;}
    if(!$active || uns_alert_beats($candidate, $alert))
    {
        $alert = $candidate;
        $why = $this_why;
    }
    $active = true;
}

if(!$active)
{
    # Nothing in force; report the newest entry's reason, which is the useful one.
    $alert = $alerts[0];
    $why = $first_why;
}
elseif(count($alerts) > 1)
{
    $why .= ' (chosen from '.count($alerts).' entries)';
}

if($GLOBALS['uns_verbose'])
{
    uns_log("  identifier : ".$alert['identifier']);
    uns_log("  headline   : ".$alert['headline']);
    uns_log("  status     : ".$alert['status']." / msgType ".$alert['msg_type']);
    uns_log("  severity   : ".($alert['severity'] !== '' ? $alert['severity'] : '(none)'));
    uns_log("  sent       : ".($alert['sent'] !== '' ? $alert['sent'] : '(none)'));
    uns_log("  expires    : ".($alert['expires'] !== '' ? $alert['expires'] : '(none)'));
}

# ---------------------------------------------------------------------------
# Apply to UNS
# ---------------------------------------------------------------------------
$cur_stmt = $conn->query("SELECT emerg FROM settings");
$cur_row  = $cur_stmt ? $cur_stmt->fetch(PDO::FETCH_ASSOC) : false;
$current  = $cur_row ? (int)$cur_row['emerg'] : 0;
$target   = $active ? 1 : 0;

if($dry_run)
{
    uns_log("DRY RUN: emergency mode would be ".($target ? "ON" : "OFF")." (currently ".($current ? "ON" : "OFF")."); ".$why);
    exit(UNS_OK);
}

if($current !== $target)
{
    $st = $conn->prepare("UPDATE settings SET emerg = ?");
    if(!$st || !$st->execute(array($target)))
    {
        uns_fail(UNS_ERR_CONFIG, "Failed to set the emergency flag: ".db_error($st ? $st : $conn));
    }
    uns_log("Emergency mode turned ".($target ? "ON" : "OFF").": ".$why);
}
else
{
    uns_log("Emergency mode already ".($target ? "ON" : "OFF").": ".$why, false);
}

# ---------------------------------------------------------------------------
# Publish the alert text as a custom message, and point an emergency URL at it
# ---------------------------------------------------------------------------
if($publish_message)
{
    # Build the client-facing URL the same way the admin panel does.
    $proto   = (!empty($SSL) ? 'https://' : 'http://');
    $reg_url = 'http://'.$host.$root;   # client pages stay on http, matching gen_base_urls()

    $body  = '<h1>'.htmlspecialchars($alert['headline'] !== '' ? $alert['headline'] : 'Emergency Alert', ENT_QUOTES).'</h1>';
    if($alert['event'] !== ''){$body .= '<h3>'.htmlspecialchars($alert['event'], ENT_QUOTES).'</h3>';}
    if($alert['description'] !== ''){$body .= '<p>'.nl2br(htmlspecialchars($alert['description'], ENT_QUOTES)).'</p>';}
    if($alert['instruction'] !== ''){$body .= '<p><b>'.nl2br(htmlspecialchars($alert['instruction'], ENT_QUOTES)).'</b></p>';}
    if($alert['severity'] !== '' || $alert['urgency'] !== '')
    {
        $body .= '<p><i>'.htmlspecialchars(trim($alert['severity'].' '.$alert['urgency']), ENT_QUOTES).'</i></p>';
    }
    if($alert['sender'] !== ''){$body .= '<p><small>'.htmlspecialchars($alert['sender'], ENT_QUOTES).'</small></p>';}

    # Reuse one row rather than accumulating a message per alert.
    $find = $conn->prepare("SELECT id FROM c_messages WHERE name = ?");
    $msg_id = 0;
    if($find && $find->execute(array($message_name)))
    {
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if($row){$msg_id = (int)$row['id'];}
    }

    if($msg_id > 0)
    {
        $up = $conn->prepare("UPDATE c_messages SET body = ?, refresh = ?, wrapper = 1 WHERE id = ?");
        if(!$up || !$up->execute(array($body, (int)$message_refresh, $msg_id)))
        {
            uns_log("WARNING: could not update the custom message: ".db_error($up ? $up : $conn));
        }
    }
    else
    {
        $ins = $conn->prepare("INSERT INTO c_messages (name, body, refresh, wrapper) VALUES (?, ?, ?, 1)");
        if($ins && $ins->execute(array($message_name, $body, (int)$message_refresh)))
        {
            $msg_id = (int)$conn->lastInsertId();
        }
        else
        {
            uns_log("WARNING: could not create the custom message: ".db_error($ins ? $ins : $conn));
        }
    }

    if($msg_id > 0)
    {
        $msg_url = $reg_url.'html/template.php?type=c_message&id='.$msg_id;

        # Only ever touch our own row, so emergency URLs added by hand are left alone.
        $find_u = $conn->prepare("SELECT id FROM emerg WHERE url = ?");
        $emerg_id = 0;
        if($find_u && $find_u->execute(array($msg_url)))
        {
            $row = $find_u->fetch(PDO::FETCH_ASSOC);
            if($row){$emerg_id = (int)$row['id'];}
        }

        if($emerg_id > 0)
        {
            $up_u = $conn->prepare("UPDATE emerg SET enabled = ?, refresh = ? WHERE id = ?");
            if($up_u){$up_u->execute(array($target, (int)$message_refresh, $emerg_id));}
        }
        elseif($target)
        {
            # Only create the emergency URL when there is actually something to show.
            $ins_u = $conn->prepare("INSERT INTO emerg (url, enabled, refresh) VALUES (?, 1, ?)");
            if($ins_u){$ins_u->execute(array($msg_url, (int)$message_refresh));}
        }

        uns_log("Alert message ".($target ? "published at " : "left in place, disabled: ").$msg_url, false);
    }
}

exit(UNS_OK);
