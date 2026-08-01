<?php
# Emergency mode aimed at one group or one client, and how it ranks against the
# global switch.

function uns_test_estate($site)
{
    # lobby and dorm are in group 1; office is in no group.
    $site->addClient('lobby',  array('http://own-lobby.example/'));
    $site->addClient('dorm',   array('http://own-dorm.example/'));
    $site->addClient('office', array('http://own-office.example/'));
    $gid = $site->addGroup('Building A', 'add');
    $site->addToGroup($gid, 'lobby');
    $site->addToGroup($gid, 'dorm');
    return $gid;
}

uns_test('a group emergency reaches only that group', function($site) {
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://group-emerg.example/', 'group', (string)$gid);
    $site->setEmergTarget('group', (string)$gid, 1);

    assert_same('http://group-emerg.example/', $site->clientUrl('lobby'), 'lobby is in emergency');
    assert_same('http://group-emerg.example/', $site->clientUrl('dorm'),  'dorm is in emergency');
    assert_same('http://own-office.example/',  $site->clientUrl('office'), 'office carries on as normal');
    assert_same(1, $site->clientEmergFlag('lobby'),  'lobby reports emerg');
    assert_same(0, $site->clientEmergFlag('office'), 'office does not report emerg');
});

uns_test('a client emergency reaches only that client', function($site) {
    uns_test_estate($site);
    $site->addEmergUrl('http://client-emerg.example/', 'client', 'office');
    $site->setEmergTarget('client', 'office', 1);

    assert_same('http://client-emerg.example/', $site->clientUrl('office'), 'office is in emergency');
    assert_same('http://own-lobby.example/',    $site->clientUrl('lobby'),  'lobby is unaffected');
});

uns_test('a client target outranks a group target', function($site) {
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://group-emerg.example/',  'group',  (string)$gid);
    $site->addEmergUrl('http://client-emerg.example/', 'client', 'lobby');
    $site->setEmergTarget('group', (string)$gid, 1);
    $site->setEmergTarget('client', 'lobby', 1);

    assert_same('http://client-emerg.example/', $site->clientUrl('lobby'), 'lobby follows its own target');
    assert_same('http://group-emerg.example/',  $site->clientUrl('dorm'),  'dorm still follows the group');
});

uns_test('global emergency overrides every target', function($site) {
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://global-emerg.example/', 'all');
    $site->addEmergUrl('http://group-emerg.example/',  'group', (string)$gid);
    $site->setEmergTarget('group', (string)$gid, 1);
    $site->setGlobalEmerg(1);

    foreach(array('lobby', 'dorm', 'office') as $c)
    {
        assert_same('http://global-emerg.example/', $site->clientUrl($c), $c.' is on the global emergency');
    }
});

uns_test('a target with no URLs of its own falls back to the shared list', function($site) {
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://shared-emerg.example/', 'all');
    $site->setEmergTarget('group', (string)$gid, 1);

    assert_same('http://shared-emerg.example/', $site->clientUrl('lobby'),
        'the group emergency still shows something');
    assert_same('http://own-office.example/', $site->clientUrl('office'),
        'and it is still only the group that is affected');
});

# --- expiry -----------------------------------------------------------------

uns_test('an expired target is treated as off', function($site) {
    # If the monitor dies mid-alert, screens must stand themselves down rather than
    # being stranded on it.
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://group-emerg.example/', 'group', (string)$gid);
    $site->setEmergTarget('group', (string)$gid, 1, time() - 60);

    assert_same('http://own-lobby.example/', $site->clientUrl('lobby'), 'lapsed emergency is ignored');
});

uns_test('a target with time left is still on', function($site) {
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://group-emerg.example/', 'group', (string)$gid);
    $site->setEmergTarget('group', (string)$gid, 1, time() + 600);

    assert_same('http://group-emerg.example/', $site->clientUrl('lobby'), 'still in force');
});

uns_test('until = 0 means no expiry', function($site) {
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://group-emerg.example/', 'group', (string)$gid);
    $site->setEmergTarget('group', (string)$gid, 1, 0);

    assert_same('http://group-emerg.example/', $site->clientUrl('lobby'), 'runs until cleared');
});

uns_test('an inactive target is off even before its expiry', function($site) {
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://group-emerg.example/', 'group', (string)$gid);
    $site->setEmergTarget('group', (string)$gid, 0, time() + 600);

    assert_same('http://own-lobby.example/', $site->clientUrl('lobby'), 'active = 0 wins over the expiry');
});

uns_test('a lapsed global emergency falls through to normal', function($site) {
    uns_test_estate($site);
    $site->addEmergUrl('http://global-emerg.example/', 'all');
    $site->setGlobalEmerg(1);
    $site->exec("INSERT INTO uns_config (cfg_key, cfg_value) VALUES ('emerg_global_until', ?)",
        array(time() - 60));

    assert_same('http://own-lobby.example/', $site->clientUrl('lobby'), 'the global alert has lapsed');
    assert_same(0, $site->clientEmergFlag('lobby'), 'and the flag reads off');
});

# --- admin round trips -------------------------------------------------------

uns_test('the admin can raise and clear a group emergency', function($site) {
    $cookie = $site->adminLogin();
    $gid = uns_test_estate($site);
    $site->addEmergUrl('http://group-emerg.example/', 'group', (string)$gid);

    $site->admin('func=emerg_target_set',
        array('scope' => 'group:'.$gid, 'on' => 1, 'minutes' => 0), $cookie);
    assert_same('http://group-emerg.example/', $site->clientUrl('lobby'), 'raised from the admin panel');
    assert_same('manual', $site->one("SELECT source FROM emerg_targets WHERE scope = 'group'"),
        'recorded as a manual emergency');

    $site->admin('func=emerg_target_set',
        array('scope' => 'group:'.$gid, 'on' => 0), $cookie);
    assert_same('http://own-lobby.example/', $site->clientUrl('lobby'), 'cleared again');
});

uns_test('a timed emergency records an expiry', function($site) {
    $cookie = $site->adminLogin();
    $gid = uns_test_estate($site);
    $before = time();
    $site->admin('func=emerg_target_set',
        array('scope' => 'group:'.$gid, 'on' => 1, 'minutes' => 30), $cookie);

    $until = (int)$site->one("SELECT until FROM emerg_targets WHERE scope = 'group'");
    assert_true($until >= $before + 1800 && $until <= $before + 1830, 'expiry is about 30 minutes out');
});

uns_test('a targeted emergency refuses the global scope', function($site) {
    # "all" belongs to the global switch; letting it through here would give two
    # sources of truth for the same state.
    $cookie = $site->adminLogin();
    uns_test_estate($site);
    $r = $site->admin('func=emerg_target_set', array('scope' => 'all::', 'on' => 1), $cookie);
    assert_contains('needs a group or a client', $r['body'], 'the request is refused');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM emerg_targets"), 'nothing was written');
});

uns_test('a user without edit_emerg cannot raise an emergency', function($site) {
    $cookie = $site->adminLogin('limited', array('edit_emerg' => 0));
    $gid = uns_test_estate($site);
    $site->admin('func=emerg_target_set', array('scope' => 'group:'.$gid, 'on' => 1), $cookie);
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM emerg_targets WHERE active = 1"),
        'the permission gate holds');
});
