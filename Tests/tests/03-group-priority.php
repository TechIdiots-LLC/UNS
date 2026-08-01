<?php
# Which group wins when a client is in more than one of them.

uns_test('the highest priority replace group wins', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $low  = $site->addGroup('Low',  'replace', 5);
    $high = $site->addGroup('High', 'replace', 10);
    $site->addToGroup($low, 'lobby');
    $site->addToGroup($high, 'lobby');
    $site->addGroupUrl($low,  'http://low.example/');
    $site->addGroupUrl($high, 'http://high.example/');

    assert_same(array('http://high.example/'), $site->clientUrlSet('lobby'), 'priority 10 beats priority 5');
});

uns_test('a tie is broken by the older group, and is stable', function($site) {
    # Two takeovers at the same priority must not flip between refreshes.
    $site->addClient('lobby', array('http://own.example/'));
    $first  = $site->addGroup('First',  'replace', 10);
    $second = $site->addGroup('Second', 'replace', 10);
    $site->addToGroup($first, 'lobby');
    $site->addToGroup($second, 'lobby');
    $site->addGroupUrl($first,  'http://first.example/');
    $site->addGroupUrl($second, 'http://second.example/');

    assert_same(array('http://first.example/'), $site->clientUrlSet('lobby'),
        'the lower group id wins, and wins every time');
});

uns_test('an add group never outranks a replace group', function($site) {
    # Priority orders replace groups against each other; it cannot promote an add
    # group over a takeover.
    $site->addClient('lobby', array('http://own.example/'));
    $add = $site->addGroup('Add at 99', 'add', 99);
    $rep = $site->addGroup('Replace at 1', 'replace', 1);
    $site->addToGroup($add, 'lobby');
    $site->addToGroup($rep, 'lobby');
    $site->addGroupUrl($add, 'http://add.example/');
    $site->addGroupUrl($rep, 'http://replace.example/');

    assert_same(array('http://replace.example/'), $site->clientUrlSet('lobby'),
        'the replace group takes over regardless of the add group priority');
});

uns_test('parking the winner promotes the next replace group', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $low  = $site->addGroup('Low',  'replace', 5);
    $high = $site->addGroup('High', 'replace', 10);
    $site->addToGroup($low, 'lobby');
    $site->addToGroup($high, 'lobby');
    $site->addGroupUrl($low,  'http://low.example/');
    $site->addGroupUrl($high, 'http://high.example/');

    $site->exec("UPDATE client_groups SET active = 0 WHERE id = ?", array($high));
    assert_same(array('http://low.example/'), $site->clientUrlSet('lobby'), 'falls through to the lower group');
});

uns_test('an empty winner promotes the next replace group', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $low  = $site->addGroup('Low',  'replace', 5);
    $high = $site->addGroup('High', 'replace', 10);
    $site->addToGroup($low, 'lobby');
    $site->addToGroup($high, 'lobby');
    $site->addGroupUrl($low, 'http://low.example/');
    # $high has no URLs at all

    assert_same(array('http://low.example/'), $site->clientUrlSet('lobby'),
        'an empty takeover does not win');
});

uns_test('with no replace group, every add group contributes', function($site) {
    $site->addClient('lobby', array('http://own.example/'));
    $a = $site->addGroup('A', 'add', 5);
    $b = $site->addGroup('B', 'add', 1);
    $site->addToGroup($a, 'lobby');
    $site->addToGroup($b, 'lobby');
    $site->addGroupUrl($a, 'http://a.example/');
    $site->addGroupUrl($b, 'http://b.example/');

    assert_same(array('http://a.example/', 'http://b.example/', 'http://own.example/'),
        $site->clientUrlSet('lobby'), 'both add groups and the client list');
});
