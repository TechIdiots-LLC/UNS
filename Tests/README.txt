UNS test suite
==============

    php Tests/run-tests.php                 run everything
    php Tests/run-tests.php groups          run only files whose name matches
    php Tests/run-tests.php --verbose       list every assertion, not just failures
    php Tests/compile-templates.php         parse every Smarty template

Exit status is 0 when everything passes, 1 otherwise. Nothing to install: the suite
uses only PHP with pdo_sqlite, matching the fact that UNS itself ships without
Composer.


What it actually does
---------------------
Each run copies Server/www to a temporary directory, writes a configs/conn.php
pointing at a throwaway SQLite database, builds that database from
setup_sqlite.sql, and starts PHP's built-in web server on a free port. Tests then
drive the real application over real HTTP.

That matters because almost everything worth protecting here only appears end to
end. Whether a display gets the right URL depends on the client page, the group
resolution in shared.php, the emergency precedence and the database schema all
agreeing; a unit test of any one of them in isolation would have missed every bug
this suite has actually caught.

Admin screens are exercised through a genuine session - the harness inserts a row
in hash_links and sends the cookie - rather than by stubbing out the login check,
so the permission gates on each handler are covered too.

One install and one server are built per run; each test gets a freshly rebuilt
database. Tests are therefore isolated from each other without paying to restart a
server sixty-odd times.


Layout
------
    run-tests.php           the runner and its assertions
    compile-templates.php   parses every .tpl, including screens no test visits
    lib/harness.php         builds the install, seeds data, talks HTTP, runs the monitor
    tests/*.php             the tests, run in filename order
    fixtures/               CAP documents and a frozen legacy schema

Coverage, roughly:

    01-client-urls          what a display is served, and global emergency mode
    02-groups               add and replace modes, membership, removal cascades
    03-group-priority       which group wins when a client is in several
    04-targeted-emergency   per-group and per-client emergencies, precedence, expiry
    05-monitor              CAP parsing, alert routing, standing alerts down
    06-migration            upgrading a database that predates a feature


Writing a test
--------------
    uns_test('what it should do', function($site) {
        $site->addClient('lobby', array('http://a.example/'));
        assert_same('http://a.example/', $site->clientUrl('lobby'), 'serves its URL');
    });

$site is a UnsTestSite. The useful parts:

    addClient($name, $urls)            client plus its links table
    addGroup($name, $mode, $priority, $active)
    addToGroup($gid, $client)          membership
    addGroupUrl($gid, $url, $refresh)
    addEmergUrl($url, $scope, $target) emergency URL, scoped
    setEmergTarget($scope, $target, $active, $until, $source)
    addRoute(...)                      an alert routing rule
    setGlobalEmerg($on)

    clientUrl($client)                 the URL a display would be sent to now
    clientUrlSet($client)              the distinct URLs it rotates through
    clientEmergFlag($client)           the <emerg> flag the client is told
    clientRefresh($client)

    adminLogin($user, $perms)          returns a cookie for admin requests
    admin($query, $post, $cookie)      an admin panel request
    monitor($args)                     runs the emergency monitor, returns its output
    publishFeed($name, $xml)           serves a feed fixture over http
    exec/one/rows($sql, $params)       direct database access for setup and assertions

Assertions: assert_same, assert_equals, assert_true, assert_false,
assert_contains, assert_not_contains. Each takes a message describing the
behaviour, which is what gets printed on failure.

Prefer asserting on what a display is actually served over what a table contains.
A test that checks clientUrl() keeps holding when the storage changes underneath
it; one that checks a row does not.


The legacy schema fixture
-------------------------
fixtures/schema-legacy-sqlite.sql is a frozen copy of the schema as it shipped
before client groups and targeted emergency mode existed. UNS is upgraded by
copying files over an install, with no migration step to run, so every table and
column added later has to appear by itself on a database that predates it. That
fixture is how 06-migration.php proves it still does.

Do not regenerate it from the current schema. The first test in that file asserts
the fixture really is missing the newer tables, so if anyone does, the migration
tests fail loudly instead of quietly testing nothing.


Continuous integration
----------------------
.github/workflows/tests.yml runs the suite on PHP 7.4, 8.1, 8.3 and 8.4 - 7.4
being the oldest version UNS supports - plus a lint of every PHP file and the
template compile. The matrix does not stop at the first failure, since knowing a
change breaks one version and not another is the point of running several.
