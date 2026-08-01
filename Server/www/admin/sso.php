<?php
# Single sign-on entry point.
#
# This is the only file the web server's authentication module needs to protect. It
# runs after the module has already established who the user is, reads that identity
# from the server variable the module set, and turns it into a UNS session.
#
# Protecting only this file - rather than the whole admin directory - is deliberate.
# admin/index.php stays reachable without SSO so the built-in administrator can still
# log in with a password when the identity provider is unreachable. Losing access to
# your own signage during an outage is exactly the wrong time to discover you had no
# way in.
#
# Apache with mod_auth_openidc:
#
#     <Files "sso.php">
#         AuthType openid-connect
#         Require valid-user
#     </Files>
#
# Apache with a Shibboleth SP:
#
#     <Files "sso.php">
#         AuthType shibboleth
#         ShibRequestSetting requireSession 1
#         Require valid-user
#     </Files>
#
# IIS: enable Windows Authentication on sso.php only, and leave Anonymous
# Authentication on for the rest of the folder.

include "../shared.php";
include "../configs/vars.php";
include "../configs/conn.php";

if(!isset($driver)){$driver = 'mysql';}
if(!isset($root)){$root = '';}
if(!isset($SSL)){$SSL = 0;}
if(!isset($timeout)){$timeout = 3600;}

# Matches the URL building in admin/index.php, which UNS uses for its own redirects.
$scheme    = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https://' : 'http://';
$admin_url = $scheme.(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost').'/'.$root;
$login_url = $admin_url.'admin/index.php';

function uns_sso_stop($title, $detail, $login_url)
{
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!doctype html><html><head><title>UNS single sign-on</title></head><body>";
    echo "<h2>".htmlspecialchars($title, ENT_QUOTES)."</h2>";
    echo "<p>".htmlspecialchars($detail, ENT_QUOTES)."</p>";
    echo "<p><a href=\"".htmlspecialchars($login_url, ENT_QUOTES)."\">Back to the login page</a></p>";
    echo "</body></html>";
    exit;
}

$conn = db_connect($server, $username, $password, $db, $driver);
if(!$conn)
{
    uns_sso_stop('Single sign-on is not available',
        'UNS could not reach its database.', $login_url);
}

$mode = uns_auth_mode($conn, $driver, isset($LDAP) ? $LDAP : 0);
if($mode !== 'sso')
{
    # Refuse to mint sessions from a server variable until an administrator has turned
    # this on. Otherwise dropping this file onto a server that has not been configured
    # for SSO would hand a session to anyone who could set the variable.
    uns_sso_stop('Single sign-on is not enabled',
        'Turn it on under UNS Options before using this page.', $login_url);
}

$cfg = uns_sso_config($conn, $driver);

if(uns_sso_var_is_header($cfg['user_var']) && empty($cfg['allow_headers']))
{
    uns_sso_stop('Single sign-on is misconfigured',
        'UNS is set to read the identity from '.$cfg['user_var'].', which comes from a request'
        .' header and can be set by anyone. Point it at a variable your authentication module'
        .' sets directly (REMOTE_USER), or explicitly allow header variables if a trusted proxy'
        .' overwrites them.', $login_url);
}

$raw = uns_sso_identity($cfg);
if($raw === '')
{
    # Either the module is not protecting this file, or it let the request through
    # unauthenticated. Both mean there is no identity to trust.
    uns_sso_stop('No single sign-on identity',
        'The web server did not provide '.$cfg['user_var'].' for this request. Check that the'
        .' authentication module is protecting admin/sso.php.', $login_url);
}

$user = uns_sso_normalize($raw, $cfg);
if($user === '')
{
    uns_sso_stop('Unusable single sign-on identity',
        'The identity provider returned a name UNS cannot use as an account name.', $login_url);
}

# Is this user allowed in? allowed_users remains the authorisation list; the identity
# provider only settles who the user is.
$stmt = $conn->prepare("SELECT * FROM allowed_users WHERE username = ?");
$stmt->execute(array($user));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$groups   = uns_sso_groups($cfg);
$is_admin = uns_sso_in_group($groups, $cfg['admin_group']);

if(!$row)
{
    if(empty($cfg['autocreate']))
    {
        uns_sso_stop('Not authorised',
            'You signed in as "'.$user.'", but that account has not been given access to UNS.'
            .' An administrator can add it under User Permissions.', $login_url);
    }

    # First login. New accounts get the same permissions the schema gives any new user;
    # the two administrative ones are granted only through the configured group.
    $ins = $conn->prepare("INSERT INTO allowed_users"
        ." (username, domain, tz, edit_urls, edit_emerg, edit_users, edit_options, c_messages, rss_feeds)"
        ." VALUES (?, '', 'ewt:0', 1, 1, ?, ?, 1, 1)");
    if(!$ins || !$ins->execute(array($user, $is_admin ? 1 : 0, $is_admin ? 1 : 0)))
    {
        uns_sso_stop('Could not create the account',
            'UNS could not add "'.$user.'" to its user list.', $login_url);
    }
}
elseif($cfg['admin_group'] !== '')
{
    # Group membership is re-applied on every login, so revoking it at the identity
    # provider takes effect here rather than leaving a stale administrator behind.
    $upd = $conn->prepare("UPDATE allowed_users SET edit_users = ?, edit_options = ? WHERE username = ?");
    if($upd){$upd->execute(array($is_admin ? 1 : 0, $is_admin ? 1 : 0, $user));}
}

if(!uns_create_session($conn, $user, $timeout, $root, $SSL))
{
    uns_sso_stop('Could not start a session',
        'UNS authenticated you but could not set its session cookie.', $login_url);
}

header('Location: '.$login_url);
echo '<a href="'.htmlspecialchars($login_url, ENT_QUOTES).'">Signed in - continue to UNS</a>';
exit;
