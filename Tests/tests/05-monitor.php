<?php
# The emergency monitor: CAP parsing, routing alerts to groups and clients, and
# standing them down again.

function uns_test_feed($site, $fixture)
{
    $xml = file_get_contents(dirname(__DIR__).'/fixtures/'.$fixture);
    $url = $site->publishFeed($fixture, $xml);
    $site->setMonitorFeed($url);
    return $url;
}

function uns_test_estate2($site)
{
    $site->addClient('lobby',  array('http://own-lobby.example/'));
    $site->addClient('dorm',   array('http://own-dorm.example/'));
    $site->addClient('office', array('http://own-office.example/'));
    $gid = $site->addGroup('Riley Campus', 'add');
    $site->addToGroup($gid, 'lobby');
    $site->addToGroup($gid, 'dorm');
    return $gid;
}

# --- backward compatibility --------------------------------------------------

uns_test('with no routes the monitor drives the global switch', function($site) {
    # An install that predates routing must keep behaving exactly as it did.
    uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');

    $r = $site->monitor();
    assert_same(0, $r['code'], 'monitor exits cleanly');
    assert_same(1, (int)$site->one("SELECT emerg FROM settings"), 'global emergency is on');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM emerg_targets"), 'no targets were created');
    assert_same(1, $site->clientEmergFlag('office'), 'every client is in emergency');
});

uns_test('an alert that is not in force leaves emergency off', function($site) {
    uns_test_estate2($site);
    uns_test_feed($site, 'cap-expired.xml');

    $site->monitor();
    assert_same(0, (int)$site->one("SELECT emerg FROM settings"), 'expired alert does not raise anything');
});

uns_test('an unreachable feed leaves the current state alone', function($site) {
    # Clearing a real alert because of a network blip is worse than doing nothing.
    uns_test_estate2($site);
    $site->setMonitorFeed($site->baseUrl().'/feed/does-not-exist.xml');
    $site->setGlobalEmerg(1);

    $r = $site->monitor();
    assert_same(2, $r['code'], 'reports a feed problem');
    assert_same(1, (int)$site->one("SELECT emerg FROM settings"), 'emergency mode is left as it was');
});

# --- routing -----------------------------------------------------------------

uns_test('a geocode route reaches only the named group', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('Riley', 'group', (string)$gid, 'geocode', 'contains', '020161', 'Severe');

    $site->monitor();

    assert_same(0, (int)$site->one("SELECT emerg FROM settings"), 'the global switch stays off');
    assert_same(1, (int)$site->one("SELECT active FROM emerg_targets WHERE scope = 'group' AND target = ?", array($gid)),
        'the group is in emergency');
    assert_same(1, $site->clientEmergFlag('lobby'),  'a group member is in emergency');
    assert_same(0, $site->clientEmergFlag('office'), 'a non-member is not');
});

uns_test('routing matches CAP category, area and event too', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('by category', 'client', 'office', 'category', 'equals',   'Met', 'Unknown');
    $site->addRoute('by area',     'group',  (string)$gid, 'area', 'contains', 'Pottawatomie', 'Unknown');

    $site->monitor();
    assert_same(1, (int)$site->one("SELECT active FROM emerg_targets WHERE scope = 'client' AND target = 'office'"),
        'category matched');
    assert_same(1, (int)$site->one("SELECT active FROM emerg_targets WHERE scope = 'group' AND target = ?", array($gid)),
        'the second area of a multi-area alert matched');
});

uns_test('minimum severity filters an alert out', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-minor.xml');
    $site->addRoute('severe only', 'group', (string)$gid, 'geocode', 'contains', '020161', 'Severe');

    $site->monitor();
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM emerg_targets WHERE active = 1"),
        'a Minor alert does not satisfy a Severe rule');
});

uns_test('one alert can reach a group and a client at once', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('to group',  'group',  (string)$gid, 'geocode',  'contains', '020161');
    $site->addRoute('to client', 'client', 'office',     'category', 'equals',   'Met');

    $site->monitor();

    assert_same(1, $site->clientEmergFlag('lobby'),  'the group is in emergency');
    assert_same(1, $site->clientEmergFlag('office'), 'the client is too');
    # Each destination gets its own message, so two groups can show different alerts.
    $msgs = (int)$site->one("SELECT COUNT(*) FROM c_messages");
    assert_true($msgs >= 2, 'a separate message per destination (got '.$msgs.')');
    assert_same(2, (int)$site->one("SELECT COUNT(DISTINCT scope || target) FROM emerg WHERE enabled = 1"),
        'a separate emergency URL row per destination');
});

uns_test('a rule targeting all clients drives the global switch', function($site) {
    uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('everyone', 'all', '', 'event', 'any', '', 'Extreme');

    $site->monitor();
    assert_same(1, (int)$site->one("SELECT emerg FROM settings"), 'global emergency is raised');
    assert_same(1, $site->clientEmergFlag('office'), 'every client is affected');
});

uns_test('an alert matching no rule changes nothing', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('elsewhere', 'group', (string)$gid, 'geocode', 'contains', '999999');

    $site->monitor();
    assert_same(0, (int)$site->one("SELECT emerg FROM settings"), 'global stays off');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM emerg_targets WHERE active = 1"), 'nothing is raised');
    assert_same(0, $site->clientEmergFlag('lobby'), 'screens carry on as normal');
});

uns_test('a disabled rule is ignored', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('off', 'group', (string)$gid, 'geocode', 'contains', '020161', 'Unknown', 0);

    $site->monitor();
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM emerg_targets WHERE active = 1"), 'no effect');
});

# --- standing down -----------------------------------------------------------

uns_test('the monitor clears an alert it raised once it expires', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('Riley', 'group', (string)$gid, 'geocode', 'contains', '020161');

    $site->monitor();
    assert_same(1, $site->clientEmergFlag('lobby'), 'raised');

    uns_test_feed($site, 'cap-expired.xml');
    $site->monitor();

    assert_same(0, (int)$site->one("SELECT active FROM emerg_targets WHERE scope = 'group' AND target = ?", array($gid)),
        'the target is cleared');
    assert_same('http://own-lobby.example/', $site->clientUrl('lobby'), 'the screen is back to normal');
});

uns_test('standing down also disables the scoped emergency URL', function($site) {
    # Otherwise a later manual emergency for that group would show the stale alert.
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('Riley', 'group', (string)$gid, 'geocode', 'contains', '020161');
    $site->monitor();
    assert_same(1, (int)$site->one("SELECT enabled FROM emerg WHERE scope = 'group' AND target = ?", array($gid)),
        'the scoped URL is live while the alert is');

    # Turning every rule off drops the monitor back to global-only mode; the stand-down
    # still has to publish through.
    $site->exec("UPDATE emerg_routes SET enabled = 0");
    $site->monitor();

    assert_same(0, (int)$site->one("SELECT enabled FROM emerg WHERE scope = 'group' AND target = ?", array($gid)),
        'the scoped URL is disabled on stand-down');
});

uns_test('a manual emergency survives a monitor run', function($site) {
    uns_test_estate2($site);
    uns_test_feed($site, 'cap-expired.xml');
    $site->addEmergUrl('http://manual.example/', 'client', 'office');
    $site->setEmergTarget('client', 'office', 1, 0, 'manual');

    $site->monitor();

    assert_same(1, (int)$site->one("SELECT active FROM emerg_targets WHERE source = 'manual'"),
        'the monitor does not clear what it did not raise');
    assert_same('http://manual.example/', $site->clientUrl('office'), 'the manual takeover is still up');
});

uns_test('the monitor records an expiry from the CAP alert', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('Riley', 'group', (string)$gid, 'geocode', 'contains', '020161');

    $site->monitor();
    $until = (int)$site->one("SELECT until FROM emerg_targets WHERE scope = 'group'");
    assert_true($until > time(), 'an expiry in the future was stored');
    assert_same(strtotime('2099-01-01T00:00:00-00:00'), $until, 'taken from the CAP expires element');
});

# --- safety ------------------------------------------------------------------

uns_test('a dry run writes nothing', function($site) {
    $gid = uns_test_estate2($site);
    uns_test_feed($site, 'cap-tornado.xml');
    $site->addRoute('Riley', 'group', (string)$gid, 'geocode', 'contains', '020161');

    $r = $site->monitor('--dry-run');
    assert_contains('DRY RUN', $r['out'], 'reports what it would do');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM emerg_targets"), 'no targets written');
    assert_same(0, (int)$site->one("SELECT emerg FROM settings"), 'global switch untouched');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM c_messages"), 'no messages published');
});

uns_test('an Exercise alert is ignored unless allowed', function($site) {
    # Drills must not take over displays by default.
    uns_test_estate2($site);
    uns_test_feed($site, 'cap-test-status.xml');

    $site->monitor();
    assert_same(0, (int)$site->one("SELECT emerg FROM settings"), 'an Exercise status is not acted on');

    $site->exec("INSERT INTO uns_config (cfg_key, cfg_value) VALUES ('emerg_allowed_status', 'Actual,Exercise')");
    $site->monitor();
    assert_same(1, (int)$site->one("SELECT emerg FROM settings"), 'until Exercise is explicitly allowed');
});
