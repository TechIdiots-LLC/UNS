<?php
# The client page: what a display is told to show, in ordinary operation and in
# global emergency mode.

uns_test('a client with one URL is served it', function($site) {
    $site->addClient('lobby', array('http://a.example/'));
    assert_same('http://a.example/', $site->clientUrl('lobby'), 'serves the only URL');
    assert_same(0, $site->clientEmergFlag('lobby'), 'emerg flag is off');
});

uns_test('a client rotates through its whole list', function($site) {
    $site->addClient('lobby', array('http://a.example/', 'http://b.example/', 'http://c.example/'));
    assert_same(array('http://a.example/', 'http://b.example/', 'http://c.example/'),
        $site->clientUrlSet('lobby'), 'all three URLs appear');
});

uns_test('the same URL is not served twice running', function($site) {
    $site->addClient('lobby', array('http://a.example/', 'http://b.example/'));
    $previous = null;
    $repeats  = 0;
    for($i = 0; $i < 20; $i++)
    {
        $u = $site->clientUrl('lobby');
        if($previous !== null && $u === $previous){$repeats++;}
        $previous = $u;
    }
    assert_same(0, $repeats, 'never repeats the previous URL when another is available');
});

uns_test('a single-URL client still gets it every time', function($site) {
    # The no-repeat filter must not empty the list for a client with nothing to
    # alternate with.
    $site->addClient('lobby', array('http://only.example/'));
    for($i = 0; $i < 5; $i++)
    {
        assert_same('http://only.example/', $site->clientUrl('lobby'), 'single URL keeps being served');
    }
});

uns_test('disabled URLs are never served', function($site) {
    $site->addClient('lobby', array('http://live.example/'));
    $site->exec("INSERT INTO lobby_links (url, disabled) VALUES ('http://off.example/', 1)");
    assert_same(array('http://live.example/'), $site->clientUrlSet('lobby'), 'only the enabled URL');
});

uns_test('an unknown client is rejected', function($site) {
    $r = $site->http('/index.php?id=nosuchclient&out=xml');
    assert_same(200, $r['code'], 'responds rather than erroring');
    assert_contains('bad_client', $r['body'], 'reports a bad client');
});

uns_test('a client id that is not a safe identifier is rejected', function($site) {
    # The client name becomes a table name, so anything outside [A-Za-z0-9_] must be
    # refused before it reaches SQL.
    $r = $site->http('/index.php?id='.urlencode("x'; DROP TABLE settings;--").'&out=xml');
    assert_contains('bad_client', $r['body'], 'refuses an unsafe client id');
    assert_true((int)$site->one("SELECT COUNT(*) FROM settings") > 0, 'settings table survives');
});

# --- global emergency -------------------------------------------------------

uns_test('global emergency serves the emergency URL', function($site) {
    # Regression: this selected on an emerg.cl_id column that never existed in any
    # schema, so a fresh install fatalled on SQLite and served nothing on MySQL.
    $site->addClient('lobby', array('http://normal.example/'));
    $site->addEmergUrl('http://emerg.example/', 'all', '', 20);
    $site->setGlobalEmerg(1);

    $r = $site->http('/index.php?id=lobby&out=xml');
    assert_same(200, $r['code'], 'the client page does not error in emergency mode');
    assert_same('http://emerg.example/', $site->clientUrl('lobby'), 'serves the emergency URL');
    assert_same(1, $site->clientEmergFlag('lobby'), 'emerg flag is set');
    assert_same(20, $site->clientRefresh('lobby'), 'uses the emergency refresh interval');
});

uns_test('disabled emergency URLs are never served', function($site) {
    $site->addClient('lobby', array('http://normal.example/'));
    $site->addEmergUrl('http://live.example/', 'all', '', 30, 1);
    $site->addEmergUrl('http://off.example/', 'all', '', 30, 0);
    $site->setGlobalEmerg(1);
    assert_same(array('http://live.example/'), $site->clientUrlSet('lobby'), 'only the enabled emergency URL');
});

uns_test('clearing global emergency returns clients to normal', function($site) {
    $site->addClient('lobby', array('http://normal.example/'));
    $site->addEmergUrl('http://emerg.example/');
    $site->setGlobalEmerg(1);
    assert_same('http://emerg.example/', $site->clientUrl('lobby'), 'emergency while set');
    $site->setGlobalEmerg(0);
    assert_same('http://normal.example/', $site->clientUrl('lobby'), 'back to normal once cleared');
});

# --- the custom message wrapper ---------------------------------------------

uns_test('the wrapper page renders a message', function($site) {
    $site->exec("INSERT INTO c_messages (name, body, refresh, wrapper) VALUES ('note', '<p>Hello</p>', 30, 1)");
    $id = $site->one("SELECT id FROM c_messages WHERE name = 'note'");

    $r = $site->http('/html/template.php?type=c_message&id='.$id);
    assert_same(200, $r['code'], 'the page responds');
    assert_contains('Hello', $r['body'], 'the message body is shown');
    assert_contains('logo.png', $r['body'], 'and the wrapper furniture is there');
});

uns_test('the wrapper does not force the logo out of shape', function($site) {
    # It used to be marked up as 462x70 while the shipped logo is 125x74, stretching it
    # nearly four times too wide. Nothing sets a size now - the stylesheet only caps it -
    # so replacing logo.png with an image of any shape still renders correctly.
    $site->exec("INSERT INTO c_messages (name, body, refresh, wrapper) VALUES ('note', '<p>Hello</p>', 30, 1)");
    $id = $site->one("SELECT id FROM c_messages WHERE name = 'note'");
    $r  = $site->http('/html/template.php?type=c_message&id='.$id);

    assert_not_contains('width="462"', $r['body'], 'no hardcoded width');
    assert_not_contains('height="70"', $r['body'], 'no hardcoded height');
    assert_contains('class="cmsglogo"', $r['body'], 'sized by the stylesheet instead');

    # And the dimensions the stylesheet works from must match the file that ships.
    $size = getimagesize(dirname(dirname(__DIR__)).'/Server/www/html/logo.png');
    assert_true($size !== false, 'logo.png is a readable image');
    assert_true($size[0] > 0 && $size[1] > 0, 'with real dimensions ('.$size[0].'x'.$size[1].')');
});

uns_test('a message without the wrapper is returned bare', function($site) {
    $site->exec("INSERT INTO c_messages (name, body, refresh, wrapper) VALUES ('bare', '<p>Raw</p>', 30, 0)");
    $id = $site->one("SELECT id FROM c_messages WHERE name = 'bare'");

    $r = $site->http('/html/template.php?type=c_message&id='.$id);
    assert_contains('Raw', $r['body'], 'the body is returned');
    assert_not_contains('logo.png', $r['body'], 'with no wrapper furniture');
});
