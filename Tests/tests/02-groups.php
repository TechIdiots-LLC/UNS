<?php
# Client groups: how a group's URL list combines with each member's own, and what
# happens to membership when clients and groups are removed.

uns_test('an add-mode group joins its URLs to the members list', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $site->addClient('other', array('http://other.example/'));
    $gid = $site->addGroup('Building A', 'add');
    $site->addToGroup($gid, 'lobby');
    $site->addGroupUrl($gid, 'http://group.example/');

    assert_same(array('http://group.example/', 'http://own.example/'),
        $site->clientUrlSet('lobby'), 'member sees both its own and the group URL');
    assert_same(array('http://other.example/'),
        $site->clientUrlSet('other'), 'a non-member is unaffected');
});

uns_test('a replace-mode group takes the screen over', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $gid = $site->addGroup('Takeover', 'replace');
    $site->addToGroup($gid, 'lobby');
    $site->addGroupUrl($gid, 'http://takeover.example/');

    assert_same(array('http://takeover.example/'),
        $site->clientUrlSet('lobby'), 'only the group URL, the client list is ignored');
});

uns_test('a parked group has no effect', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $gid = $site->addGroup('Takeover', 'replace', 0, 0);   # active = 0
    $site->addToGroup($gid, 'lobby');
    $site->addGroupUrl($gid, 'http://takeover.example/');

    assert_same(array('http://own.example/'), $site->clientUrlSet('lobby'), 'parked group is ignored');
});

uns_test('an empty replace group does not blank the screen', function($site) {
    # A takeover group with nothing in it must fall through rather than leaving the
    # display with no URL at all.
    $site->addClient('lobby', array('http://own.example/'));
    $gid = $site->addGroup('Empty takeover', 'replace');
    $site->addToGroup($gid, 'lobby');

    assert_same(array('http://own.example/'), $site->clientUrlSet('lobby'), 'falls back to the client list');
});

uns_test('a replace group whose URLs are all disabled falls through', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $gid = $site->addGroup('Takeover', 'replace');
    $site->addToGroup($gid, 'lobby');
    $site->exec("INSERT INTO group_links (group_id, url, disabled, refresh) VALUES (?, 'http://off.example/', 1, 60)",
        array($gid));

    assert_same(array('http://own.example/'), $site->clientUrlSet('lobby'), 'disabled group URLs do not count');
});

uns_test('a client can belong to several groups at once', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $g1 = $site->addGroup('Building A', 'add');
    $g2 = $site->addGroup('Lobby Screens', 'add');
    $site->addToGroup($g1, 'lobby');
    $site->addToGroup($g2, 'lobby');
    $site->addGroupUrl($g1, 'http://building.example/');
    $site->addGroupUrl($g2, 'http://lobbies.example/');

    assert_same(array('http://building.example/', 'http://lobbies.example/', 'http://own.example/'),
        $site->clientUrlSet('lobby'), 'URLs from both groups plus its own');
});

uns_test('a URL in two groups is not weighted twice', function($site) {
    $site->addClient('lobby', array());
    $g1 = $site->addGroup('One', 'add');
    $g2 = $site->addGroup('Two', 'add');
    $site->addToGroup($g1, 'lobby');
    $site->addToGroup($g2, 'lobby');
    $site->addGroupUrl($g1, 'http://shared.example/');
    $site->addGroupUrl($g2, 'http://shared.example/');
    $site->addGroupUrl($g2, 'http://other.example/');

    assert_same(array('http://other.example/', 'http://shared.example/'),
        $site->clientUrlSet('lobby'), 'the duplicate is collapsed');
});

uns_test('a group URL keeps its own refresh interval', function($site) {
    $site->addClient('lobby', array());
    $gid = $site->addGroup('Building A', 'add');
    $site->addToGroup($gid, 'lobby');
    $site->addGroupUrl($gid, 'http://group.example/', 45);

    assert_same(45, $site->clientRefresh('lobby'), 'the group URL refresh is used');
});

# --- removal ----------------------------------------------------------------

uns_test('removing a client clears its group membership', function($site) {
    $cookie = $site->adminLogin();
    $site->addClient('lobby', array('http://own.example/'));
    $site->addClient('keep', array('http://keep.example/'));
    $gid = $site->addGroup('Building A', 'add');
    $site->addToGroup($gid, 'lobby');
    $site->addToGroup($gid, 'keep');

    $site->admin('func=remove_cl', array('remove' => array('lobby')), $cookie);

    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM client_group_members WHERE client = 'lobby'"),
        'the removed client has no membership rows left');
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM client_group_members WHERE client = 'keep'"),
        'other members are untouched');
});

uns_test('a client re-registered under an old name does not inherit its groups', function($site) {
    # Membership is keyed by client name, so stale rows would silently re-attach.
    $cookie = $site->adminLogin();
    $site->addClient('lobby', array('http://own.example/'));
    $gid = $site->addGroup('Building A', 'add');
    $site->addToGroup($gid, 'lobby');
    $site->addGroupUrl($gid, 'http://group.example/');

    $site->admin('func=remove_cl', array('remove' => array('lobby')), $cookie);
    $site->exec("DROP TABLE IF EXISTS lobby_links");
    $site->addClient('lobby', array('http://brandnew.example/'));

    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM client_group_members WHERE client = 'lobby'"),
        'the new client starts with no groups');
    assert_same(array('http://brandnew.example/'), $site->clientUrlSet('lobby'),
        'and does not see the old group URL');
});

uns_test('removing a group takes its members and URLs with it', function($site) {
    $cookie = $site->adminLogin();
    $site->addClient('lobby', array('http://own.example/'));
    $gid = $site->addGroup('Building A', 'add');
    $site->addToGroup($gid, 'lobby');
    $site->addGroupUrl($gid, 'http://group.example/');

    $site->admin('func=remove_group', array('remove' => array($gid)), $cookie);

    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM client_groups WHERE id = ?", array($gid)), 'group gone');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM client_group_members WHERE group_id = ?", array($gid)),
        'no orphaned membership rows');
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM group_links WHERE group_id = ?", array($gid)),
        'no orphaned URL rows');
});

# --- admin round trips -------------------------------------------------------

uns_test('the admin can create a group and set its membership', function($site) {
    $cookie = $site->adminLogin();
    $site->addClient('lobby', array('http://own.example/'));
    $site->addClient('cafe', array('http://cafe.example/'));

    $site->admin('func=add_group', array('name' => 'Building A'), $cookie);
    $gid = (int)$site->one("SELECT id FROM client_groups WHERE name = 'Building A'");
    assert_true($gid > 0, 'the group was created');

    $site->admin('func=save_group_members&group='.$gid,
        array('clients' => array('lobby', 'cafe')), $cookie);
    assert_same(2, (int)$site->one("SELECT COUNT(*) FROM client_group_members WHERE group_id = ?", array($gid)),
        'both clients are members');

    # Unticking one must remove it: the posted set is the membership.
    $site->admin('func=save_group_members&group='.$gid, array('clients' => array('lobby')), $cookie);
    assert_same(array('lobby'),
        array_column($site->rows("SELECT client FROM client_group_members WHERE group_id = ?", array($gid)), 'client'),
        'membership follows the posted set exactly');
});

uns_test('membership cannot be set for a client that does not exist', function($site) {
    $cookie = $site->adminLogin();
    $site->addClient('lobby', array());
    $gid = $site->addGroup('Building A');

    $site->admin('func=save_group_members&group='.$gid,
        array('clients' => array('lobby', 'ghost')), $cookie);

    assert_same(array('lobby'),
        array_column($site->rows("SELECT client FROM client_group_members WHERE group_id = ?", array($gid)), 'client'),
        'the unknown client is dropped');
});

uns_test('a duplicate URL in one group is rejected', function($site) {
    $cookie = $site->adminLogin();
    $gid = $site->addGroup('Building A');
    $site->admin('func=add_group_url&group='.$gid, array('url' => 'http://x.example/', 'refresh' => 60), $cookie);
    $site->admin('func=add_group_url&group='.$gid, array('url' => 'http://x.example/', 'refresh' => 60), $cookie);
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM group_links WHERE group_id = ?", array($gid)),
        'the second add is refused');
});
