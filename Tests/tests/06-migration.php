<?php
# Upgrading in place.
#
# UNS installs are upgraded by copying files over an existing install; there is no
# migration command to run. Every table and column added after 1.0 therefore has to
# appear on its own, on a database that predates it, without downtime.
#
# schema-legacy-sqlite.sql is a frozen copy of the schema as it shipped before client
# groups and targeted emergency mode existed.

function uns_legacy_schema()
{
    return dirname(__DIR__).'/fixtures/schema-legacy-sqlite.sql';
}

uns_test('the legacy fixture really does predate the new tables', function($site) {
    # Guards the rest of this file: if someone regenerates the fixture from the
    # current schema, these tests would silently stop testing anything.
    $sql = file_get_contents(uns_legacy_schema());
    assert_not_contains('client_groups',  $sql, 'fixture has no group tables');
    assert_not_contains('emerg_targets',  $sql, 'fixture has no targeting tables');
    assert_not_contains('scope',          $sql, 'fixture emerg table has no scope column');
});

uns_test('a pre-groups database keeps serving clients', function($site) {
    $site->resetDb(uns_legacy_schema());
    $site->addClient('legacy', array('http://legacy.example/'));

    $r = $site->http('/index.php?id=legacy&out=xml');
    assert_same(200, $r['code'], 'no error on an un-migrated database');
    assert_same('http://legacy.example/', $site->clientUrl('legacy'), 'serves its URL as before');
});

uns_test('the group tables appear on their own', function($site) {
    $site->resetDb(uns_legacy_schema());
    $site->addClient('legacy', array('http://legacy.example/'));
    assert_same(0, (int)$site->one("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'client_groups'"),
        'not there to begin with');

    $site->clientUrl('legacy');

    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'client_groups'"),
        'created on first use');
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'client_group_members'"),
        'membership table too');
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'group_links'"),
        'group URL table too');
});

uns_test('a pre-targeting database still serves global emergencies', function($site) {
    # The existing emerg rows have no scope column yet; they must keep meaning
    # "shown to everyone".
    $site->resetDb(uns_legacy_schema());
    $site->addClient('legacy', array('http://legacy.example/'));
    $site->exec("INSERT INTO emerg (url, enabled, refresh) VALUES ('http://old-emerg.example/', 1, 30)");
    $site->setGlobalEmerg(1);

    $r = $site->http('/index.php?id=legacy&out=xml');
    assert_same(200, $r['code'], 'no error');
    assert_same('http://old-emerg.example/', $site->clientUrl('legacy'), 'the pre-existing emergency URL still shows');
});

uns_test('emerg gains scope and target, defaulting to the whole estate', function($site) {
    $site->resetDb(uns_legacy_schema());
    $site->addClient('legacy', array('http://legacy.example/'));
    $site->exec("INSERT INTO emerg (url, enabled, refresh) VALUES ('http://old-emerg.example/', 1, 30)");
    $site->setGlobalEmerg(1);
    $site->clientUrl('legacy');

    $cols = array();
    foreach($site->rows("PRAGMA table_info(emerg)") as $c){$cols[] = $c['name'];}
    assert_true(in_array('scope', $cols, true),  'scope column added');
    assert_true(in_array('target', $cols, true), 'target column added');
    assert_same('all', $site->one("SELECT scope FROM emerg WHERE url = 'http://old-emerg.example/'"),
        'the existing row means what it always meant');
});

uns_test('the admin panel works against a legacy database', function($site) {
    $site->resetDb(uns_legacy_schema());
    $cookie = $site->adminLogin();
    $site->addClient('legacy', array('http://legacy.example/'));

    foreach(array('client_groups', 'edit_emerg', 'emerg_routes') as $screen)
    {
        $r = $site->admin('func='.$screen, null, $cookie);
        assert_same(200, $r['code'], $screen.' responds');
        assert_not_contains('Fatal error', $r['body'], $screen.' does not fatal on an un-migrated database');
    }
});

uns_test('the monitor works against a legacy database', function($site) {
    $site->resetDb(uns_legacy_schema());
    $site->addClient('legacy', array('http://legacy.example/'));
    $xml = file_get_contents(dirname(__DIR__).'/fixtures/cap-tornado.xml');
    $site->setMonitorFeed($site->publishFeed('cap-tornado.xml', $xml));

    $r = $site->monitor();
    assert_same(0, $r['code'], 'monitor runs cleanly: '.$r['out']);
    assert_same(1, (int)$site->one("SELECT emerg FROM settings"), 'and raises the global emergency');
});

uns_test('migrating does not disturb existing data', function($site) {
    $site->resetDb(uns_legacy_schema());
    $site->addClient('legacy', array('http://legacy.example/'));
    $site->exec("INSERT INTO c_messages (name, body, refresh, wrapper) VALUES ('keep me', '<p>x</p>', 30, 1)");
    $site->exec("INSERT INTO rss_feeds (name, url, maxlines) VALUES ('feed', 'http://f.example/', 5)");

    $site->clientUrl('legacy');
    $site->admin('func=client_groups', null, $site->adminLogin());

    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM c_messages WHERE name = 'keep me'"), 'messages intact');
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM rss_feeds"), 'feeds intact');
    assert_same(1, (int)$site->one("SELECT COUNT(*) FROM allowed_clients"), 'clients intact');
});
