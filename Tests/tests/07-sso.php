<?php
# Single sign-on: UNS trusting an identity the web server already established.
#
# The pure helpers are exercised directly. The sso.php flow is driven over HTTP with
# the identity carried in a request header, which is the only way to feed a server
# variable through PHP's built-in server - and it is also the configuration that has to
# be opted into, so the tests that matter most are the ones proving it is refused by
# default.

require_once dirname(dirname(__DIR__)).'/Server/www/shared.php';

# --- name normalisation ------------------------------------------------------

uns_test('an identity is reduced to an account name', function($site) {
    $cfg = array('strip_domain' => 1, 'lowercase' => 1);
    assert_same('jsmith', uns_sso_normalize('CAMPUS\\JSmith', $cfg),   'DOMAIN\\user');
    assert_same('jsmith', uns_sso_normalize('jsmith@wsu.edu', $cfg),   'UPN form');
    assert_same('jsmith', uns_sso_normalize('  JSmith  ', $cfg),       'trimmed and lowercased');
    assert_same('jsmith', uns_sso_normalize('CAMPUS\\jsmith@wsu.edu', $cfg), 'both at once');
});

uns_test('normalisation can be turned off', function($site) {
    $cfg = array('strip_domain' => 0, 'lowercase' => 0);
    assert_same('', uns_sso_normalize('CAMPUS\\JSmith', $cfg),
        'a backslash is not a usable account name when stripping is off');
    assert_same('JSmith', uns_sso_normalize('JSmith', $cfg), 'case is preserved');
});

uns_test('an unusable identity is refused rather than stored', function($site) {
    $cfg = array('strip_domain' => 1, 'lowercase' => 1);
    foreach(array('', '   ', "bad name", "quote'; DROP TABLE allowed_users;--", '<script>') as $bad)
    {
        assert_same('', uns_sso_normalize($bad, $cfg), 'refuses '.var_export($bad, true));
    }
});

# --- where the identity is read from ----------------------------------------

uns_test('a header-sourced variable is ignored unless allowed', function($site) {
    # HTTP_* comes from the request, so anyone could set it. This is the single most
    # important check in the whole feature.
    $cfg = array('user_var' => 'HTTP_X_FORWARDED_USER', 'allow_headers' => 0);
    assert_same('', uns_sso_identity($cfg, array('HTTP_X_FORWARDED_USER' => 'attacker')),
        'refused by default');

    $cfg['allow_headers'] = 1;
    assert_same('attacker', uns_sso_identity($cfg, array('HTTP_X_FORWARDED_USER' => 'attacker')),
        'honoured only once explicitly allowed');
});

uns_test('REMOTE_USER is read without any opt-in', function($site) {
    $cfg = array('user_var' => 'REMOTE_USER', 'allow_headers' => 0);
    assert_false(uns_sso_var_is_header('REMOTE_USER'), 'REMOTE_USER is not header-sourced');
    assert_same('jsmith', uns_sso_identity($cfg, array('REMOTE_USER' => 'jsmith')), 'read directly');
    assert_same('', uns_sso_identity($cfg, array()), 'empty when the server did not set it');
});

# --- groups ------------------------------------------------------------------

uns_test('group claims are split on whatever the provider used', function($site) {
    $cfg = array('group_var' => 'OIDC_CLAIM_groups', 'allow_headers' => 0);
    assert_same(array('staff', 'UNS-Admins'),
        uns_sso_groups($cfg, array('OIDC_CLAIM_groups' => 'staff,UNS-Admins')), 'commas');
    assert_same(array('staff', 'UNS-Admins'),
        uns_sso_groups($cfg, array('OIDC_CLAIM_groups' => 'staff; UNS-Admins')), 'semicolons');
    assert_same(array(), uns_sso_groups(array('group_var' => '', 'allow_headers' => 0), array()),
        'no group variable configured');
});

uns_test('group matching is case-insensitive and never matches empty', function($site) {
    assert_true(uns_sso_in_group(array('Staff', 'UNS-Admins'), 'uns-admins'), 'case-insensitive');
    assert_false(uns_sso_in_group(array('Staff'), 'UNS-Admins'), 'not a member');
    assert_false(uns_sso_in_group(array('Staff'), ''), 'an unconfigured group matches nobody');
});

# --- auth mode ---------------------------------------------------------------

uns_test('the auth mode falls back to the old LDAP flag', function($site) {
    # Installs predating this setting only have $LDAP in configs/vars.php.
    $db = $site->db();
    assert_same('internal', uns_auth_mode($db, 'sqlite', 0), 'no flag, no setting');
    assert_same('ldap',     uns_auth_mode($db, 'sqlite', 1), 'LDAP flag honoured');

    $site->exec("INSERT INTO uns_config (cfg_key, cfg_value) VALUES ('auth_mode', 'sso')");
    assert_same('sso', uns_auth_mode($site->db(), 'sqlite', 1), 'the stored mode wins');
});

# --- the sso.php flow --------------------------------------------------------

function uns_sso_enable($site, $extra = array())
{
    # Header-sourced on purpose: it is the only variable a test client can set through
    # the built-in server. Production should use REMOTE_USER.
    $cfg = array_merge(array(
        'auth_mode'         => 'sso',
        'sso_user_var'      => 'HTTP_X_UNS_USER',
        'sso_allow_headers' => '1',
        'sso_strip_domain'  => '1',
        'sso_lowercase'     => '1',
        'sso_autocreate'    => '0',
    ), $extra);
    foreach($cfg as $k => $v)
    {
        $site->exec("INSERT INTO uns_config (cfg_key, cfg_value) VALUES (?, ?)", array($k, $v));
    }
}

uns_test('sso.php refuses to run when SSO is not enabled', function($site) {
    $r = $site->httpHeaders('/admin/sso.php', array('X-UNS-User: jsmith'));
    assert_contains('not enabled', $r['body'], 'refused outright');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM hash_links"), 'no session was minted');
});

uns_test('sso.php signs in a user who is already allowed', function($site) {
    uns_sso_enable($site);
    $site->exec("INSERT INTO allowed_users (username, domain, tz, edit_urls, edit_emerg, edit_users, edit_options, c_messages, rss_feeds)"
        ." VALUES ('jsmith', '', 'ewt:0', 1, 1, 0, 0, 1, 1)");

    $r = $site->httpHeaders('/admin/sso.php', array('X-UNS-User: CAMPUS\\JSmith'));
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM hash_links"), 'a session was created');
    assert_same('jsmith', $site->one("SELECT username FROM hash_links"), 'for the normalised name');
});

uns_test('an unknown user is refused when auto-create is off', function($site) {
    uns_sso_enable($site);
    $r = $site->httpHeaders('/admin/sso.php', array('X-UNS-User: stranger'));
    assert_contains('not been given access', $r['body'], 'told they are not authorised');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM hash_links"), 'no session');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM allowed_users WHERE username = 'stranger'"),
        'and no account was created');
});

uns_test('auto-create adds the account on first sign-in', function($site) {
    uns_sso_enable($site, array('sso_autocreate' => '1'));
    $site->httpHeaders('/admin/sso.php', array('X-UNS-User: newbie'));

    $row = $site->rows("SELECT * FROM allowed_users WHERE username = 'newbie'");
    assert_same(1, count($row), 'the account exists');
    assert_same(1, (int)$row[0]['edit_urls'],    'gets the ordinary permissions');
    assert_same(0, (int)$row[0]['edit_users'],   'but not user administration');
    assert_same(0, (int)$row[0]['edit_options'], 'and not options');
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM hash_links"), 'and is signed in');
});

uns_test('the administrator group grants and revokes the admin permissions', function($site) {
    uns_sso_enable($site, array(
        'sso_autocreate'  => '1',
        'sso_group_var'   => 'HTTP_X_UNS_GROUPS',
        'sso_admin_group' => 'UNS-Admins',
    ));

    $site->httpHeaders('/admin/sso.php',
        array('X-UNS-User: boss', 'X-UNS-Groups: staff,UNS-Admins'));
    assert_same(1, (int)$site->one("SELECT edit_users FROM allowed_users WHERE username = 'boss'"),
        'a member gets Edit Users');

    # Removing them at the provider must take effect at the next sign-in, not leave a
    # stale administrator behind.
    $site->httpHeaders('/admin/sso.php', array('X-UNS-User: boss', 'X-UNS-Groups: staff'));
    assert_same(0, (int)$site->one("SELECT edit_users FROM allowed_users WHERE username = 'boss'"),
        'losing the group revokes it');
});

uns_test('sso.php refuses when the web server supplied no identity', function($site) {
    uns_sso_enable($site);
    $r = $site->httpHeaders('/admin/sso.php', array());
    assert_contains('did not provide', $r['body'], 'says the module is not protecting it');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM hash_links"), 'no session');
});

uns_test('a header variable is refused unless explicitly allowed', function($site) {
    uns_sso_enable($site, array('sso_allow_headers' => '0'));
    $r = $site->httpHeaders('/admin/sso.php', array('X-UNS-User: attacker'));
    assert_contains('misconfigured', $r['body'], 'refuses and explains why');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM hash_links"), 'no session');
});

# --- break-glass -------------------------------------------------------------

uns_test('local sign-in still works while SSO is on', function($site) {
    # An identity provider outage must not lock an administrator out of their own signage.
    uns_sso_enable($site);
    $cookie = $site->adminLogin('localadmin');
    $r = $site->admin('func=client_groups', null, $cookie);
    assert_same(200, $r['code'], 'a local session is still accepted');
    assert_not_contains('shouldn', $r['body'], 'and still carries its permissions');
});

uns_test('the login page offers SSO but keeps the password form', function($site) {
    uns_sso_enable($site, array('sso_button_label' => 'Sign in with Campus ID'));
    $r = $site->http('/admin/index.php');
    assert_contains('Sign in with Campus ID', $r['body'], 'the SSO button is shown');
    assert_contains('sso.php', $r['body'], 'and points at the entry point');
    assert_contains('name="pass"', $r['body'], 'the local password form is still there');
});

uns_test('the login page shows no SSO button when it is off', function($site) {
    $r = $site->http('/admin/index.php');
    assert_not_contains('sso.php', $r['body'], 'no SSO link');
    assert_contains('name="pass"', $r['body'], 'just the password form');
});
