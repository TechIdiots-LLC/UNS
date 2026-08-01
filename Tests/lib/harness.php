<?php
# Test harness for UNS.
#
# Stands up a throwaway UNS install - a copy of Server/www, a SQLite database built
# from setup_sqlite.sql, and PHP's built-in web server - so tests exercise the real
# application over real HTTP rather than calling functions in isolation. Most of the
# behaviour worth protecting here (emergency precedence, group resolution, the
# monitor's routing) only shows up end to end.
#
# Deliberately dependency-free: UNS ships without Composer, so pulling in PHPUnit just
# for this would add a build step the project does not otherwise have.
#
# Everything here must parse on PHP 7.4, which is the oldest version UNS supports.

class UnsTestSite
{
    public $root;        # temp dir holding the copied install
    public $docroot;     # $root/site
    public $dbPath;
    public $port;
    public $repoRoot;

    private $proc = null;
    private $pipes = array();
    private $logPath = '';

    public function __construct($repoRoot)
    {
        $this->repoRoot = rtrim(str_replace('\\', '/', $repoRoot), '/');
        $this->root     = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/uns-tests-'.getmypid().'-'.mt_rand(1000, 9999);
        $this->docroot  = $this->root.'/site';
        $this->dbPath   = $this->docroot.'/configs/uns.sqlite';

        $this->copyTree($this->repoRoot.'/Server/www', $this->docroot);
        @mkdir($this->docroot.'/templates_c', 0777, true);
        @mkdir($this->docroot.'/templates_cache', 0777, true);
        @mkdir($this->docroot.'/feed', 0777, true);

        $this->port = $this->freePort();
        $this->writeConfigs();
        $this->resetDb();
        $this->startServer();
    }

    # --- setup ---------------------------------------------------------------

    private function copyTree($src, $dst)
    {
        @mkdir($dst, 0777, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $item)
        {
            $target = $dst.'/'.str_replace('\\', '/', $it->getSubPathName());
            if($item->isDir()){@mkdir($target, 0777, true);}
            else{copy($item->getPathname(), $target);}
        }
    }

    private function freePort()
    {
        # Ask the OS for an unused port rather than guessing, so parallel runs and
        # whatever else is on the machine cannot collide with us.
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if(!$sock){return 8400 + mt_rand(0, 400);}
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $parts = explode(':', $name);
        return (int)end($parts);
    }

    private function writeConfigs()
    {
        file_put_contents($this->docroot.'/configs/conn.php',
            "<?php\n"
            ."\$driver = 'sqlite';\n"
            ."\$server = ''; \$username = ''; \$password = '';\n"
            ."\$db = ".var_export($this->dbPath, true).";\n");

        # Mirrors configs/vars.sample.php, with SSL and LDAP off so the test client can
        # reach the admin panel over plain http with an internal account.
        file_put_contents($this->docroot.'/configs/vars.php',
            "<?php\n"
            ."\$name_title = 'Test';\n"
            ."\$host = '127.0.0.1:".$this->port."/';\n"
            ."\$root = '';\n"
            ."\$timeout = 3600;\n"
            ."\$SSL = 0;\n"
            ."\$domain = 'example.local';\n"
            ."\$port = 3268;\n"
            ."\$TZ = 'UTC';\n"
            ."\$page_timeout = 0;\n"
            ."\$refresh = 30;\n"
            ."\$seed = 'test-seed';\n"
            ."\$LDAP = 0;\n"
            ."\$max_archives = 10;\n"
            ."\$max_conn_hist = 10;\n"
            ."\$lpt_set_app = ''; \$lpt_read_app = ''; \$led_blink = 0;\n"
            ."\$mysql_dump_bin = 'mysqldump';\n"
            ."\$admin_user = 'unsadmin';\n");
    }

    # Rebuilds the database from a schema. Defaults to the shipped SQLite schema;
    # pass a legacy fixture to test what an install predating a feature does.
    public function resetDb($schemaFile = null)
    {
        if($schemaFile === null){$schemaFile = $this->docroot.'/setup_sqlite.sql';}
        @unlink($this->dbPath);

        $pdo = new PDO('sqlite:'.$this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach(explode(';', file_get_contents($schemaFile)) as $stmt)
        {
            if(trim($stmt) === ''){continue;}
            $pdo->exec($stmt);
        }
        # Smarty caches compiled templates against the previous database's data in some
        # screens; clearing keeps one test from seeing another's render.
        $this->clearDir($this->docroot.'/templates_cache');
    }

    private function clearDir($dir)
    {
        foreach((array)glob($dir.'/*') as $f)
        {
            if(is_file($f)){@unlink($f);}
        }
    }

    private function startServer()
    {
        $cmd = escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$this->port.' -t '.escapeshellarg($this->docroot);

        # Log to files rather than pipes. The built-in server writes a line per request
        # plus every PHP notice to stderr; with a pipe nobody drains, that buffer fills
        # and the server blocks forever partway through the suite.
        $this->logPath = $this->root.'/server.log';
        $desc = array(
            0 => array('pipe', 'r'),
            1 => array('file', $this->logPath, 'a'),
            2 => array('file', $this->logPath, 'a'),
        );
        $this->proc = proc_open($cmd, $desc, $this->pipes, $this->docroot);
        if(!is_resource($this->proc)){throw new RuntimeException('Could not start the PHP built-in server');}

        for($i = 0; $i < 100; $i++)
        {
            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if($sock){fclose($sock); return;}
            usleep(100000);
        }
        throw new RuntimeException('The test server never came up on port '.$this->port);
    }

    public function serverLog()
    {
        return is_readable($this->logPath) ? (string)file_get_contents($this->logPath) : '';
    }

    public function stop()
    {
        if(is_resource($this->proc))
        {
            # proc_terminate() signals the process proc_open started, which on Windows is
            # a cmd wrapper rather than php.exe itself. Killing only the wrapper leaves
            # the server running, holding its inherited handles open - which hangs
            # anything piping this script's output, and leaks a listener per run. Kill
            # the whole tree by pid instead.
            $status = proc_get_status($this->proc);
            $pid    = isset($status['pid']) ? (int)$status['pid'] : 0;

            foreach($this->pipes as $p){if(is_resource($p)){fclose($p);}}

            if($pid > 0)
            {
                if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                {
                    exec('taskkill /F /T /PID '.$pid.' 2>NUL', $out, $rc);
                }
                else
                {
                    proc_terminate($this->proc, 15);
                }
            }
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        $this->rmTree($this->root);
    }

    private function rmTree($dir)
    {
        if(!is_dir($dir)){return;}
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item)
        {
            if($item->isDir()){@rmdir($item->getPathname());}else{@unlink($item->getPathname());}
        }
        @rmdir($dir);
    }

    # --- database ------------------------------------------------------------

    public function db()
    {
        $pdo = new PDO('sqlite:'.$this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function exec($sql, $params = array())
    {
        $st = $this->db()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function one($sql, $params = array())
    {
        $st = $this->db()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public function rows($sql, $params = array())
    {
        $st = $this->db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    # --- seeding -------------------------------------------------------------

    # A client plus its own links table, the way admin/index.php creates them.
    public function addClient($name, $urls = array(), $friendly = null)
    {
        if($friendly === null){$friendly = ucfirst($name);}
        $db = $this->db();
        $db->prepare("INSERT INTO allowed_clients (client_name, led) VALUES (?, 1)")->execute(array($name));
        $db->prepare("INSERT INTO friendly (friendly, client) VALUES (?, ?)")->execute(array($friendly, $name));
        $db->exec("CREATE TABLE IF NOT EXISTS ".$name."_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url VARCHAR(255) NOT NULL UNIQUE,
            disabled TINYINT NOT NULL DEFAULT 0,
            refresh INTEGER NOT NULL DEFAULT 60)");
        foreach($urls as $u)
        {
            $db->prepare("INSERT INTO ".$name."_links (url) VALUES (?)")->execute(array($u));
        }
        return $name;
    }

    public function addGroup($name, $mode = 'add', $priority = 0, $active = 1)
    {
        $db = $this->db();
        $db->prepare("INSERT INTO client_groups (name, description, mode, priority, active) VALUES (?, '', ?, ?, ?)")
           ->execute(array($name, $mode, $priority, $active));
        return (int)$db->lastInsertId();
    }

    public function addToGroup($groupId, $client)
    {
        $this->exec("INSERT INTO client_group_members (group_id, client) VALUES (?, ?)", array($groupId, $client));
    }

    public function addGroupUrl($groupId, $url, $refresh = 60)
    {
        $this->exec("INSERT INTO group_links (group_id, url, disabled, refresh) VALUES (?, ?, 0, ?)",
            array($groupId, $url, $refresh));
    }

    public function addEmergUrl($url, $scope = 'all', $target = '', $refresh = 30, $enabled = 1)
    {
        $this->exec("INSERT INTO emerg (url, enabled, refresh, scope, target) VALUES (?, ?, ?, ?, ?)",
            array($url, $enabled, $refresh, $scope, $target));
    }

    public function setEmergTarget($scope, $target, $active = 1, $until = 0, $source = 'manual')
    {
        $existing = $this->one("SELECT id FROM emerg_targets WHERE scope = ? AND target = ?", array($scope, $target));
        if($existing)
        {
            $this->exec("UPDATE emerg_targets SET active = ?, until = ?, source = ? WHERE id = ?",
                array($active, $until, $source, $existing));
            return;
        }
        $this->exec("INSERT INTO emerg_targets (scope, target, active, until, source, note, updated) VALUES (?, ?, ?, ?, ?, '', ?)",
            array($scope, $target, $active, $until, $source, time()));
    }

    public function addRoute($name, $scope, $target, $field, $op, $value, $minSeverity = 'Unknown', $enabled = 1)
    {
        $this->exec("INSERT INTO emerg_routes (name, scope, target, field, op, value, min_severity, enabled)"
            ." VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            array($name, $scope, $target, $field, $op, $value, $minSeverity, $enabled));
    }

    public function setGlobalEmerg($on)
    {
        $this->exec("UPDATE settings SET emerg = ?", array($on ? 1 : 0));
    }

    # Serves a fixture from the test docroot so the monitor can fetch it over http,
    # exactly as it would a real feed.
    public function publishFeed($name, $contents)
    {
        file_put_contents($this->docroot.'/feed/'.$name, $contents);
        return $this->baseUrl().'/feed/'.$name;
    }

    # --- http ----------------------------------------------------------------

    public function baseUrl()
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    public function http($path, $post = null, $cookie = null, $extraHeaders = array())
    {
        $url = (strpos($path, 'http') === 0) ? $path : $this->baseUrl().$path;

        $headers = $extraHeaders;
        if($cookie !== null){$headers[] = 'Cookie: '.$cookie;}

        if(function_exists('curl_init'))
        {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            if($headers){curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);}
            if($post !== null)
            {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
            }
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return array('code' => $code, 'body' => (string)$body);
        }

        $opts = array('http' => array('timeout' => 20, 'ignore_errors' => true));
        if($post !== null)
        {
            $opts['http']['method']  = 'POST';
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['http']['content'] = is_array($post) ? http_build_query($post) : $post;
        }
        $opts['http']['header'] = implode("\r\n", $headers);
        $body = @file_get_contents($url, false, stream_context_create($opts));
        $code = 0;
        if(isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)){$code = (int)$m[1];}
        return array('code' => $code, 'body' => (string)$body);
    }

    # A GET carrying arbitrary request headers. Used by the SSO tests, where the
    # identity has to arrive as a header because there is no way to set a server
    # variable through the built-in web server.
    public function httpHeaders($path, $headers)
    {
        return $this->http($path, null, null, $headers);
    }

    # The URL a display would actually be sent to right now.
    public function clientUrl($client)
    {
        $r = $this->http('/index.php?id='.$client.'&out=xml');
        if(!preg_match('/<url><!\[CDATA\[(.*?)\]\]><\/url>/s', $r['body'], $m)){return null;}
        return $m[1];
    }

    public function clientEmergFlag($client)
    {
        $r = $this->http('/index.php?id='.$client.'&out=xml');
        if(!preg_match('/<emerg>(\d)<\/emerg>/', $r['body'], $m)){return null;}
        return (int)$m[1];
    }

    public function clientRefresh($client)
    {
        $r = $this->http('/index.php?id='.$client.'&out=xml');
        if(!preg_match('/<refresh>(\d+)<\/refresh>/', $r['body'], $m)){return null;}
        return (int)$m[1];
    }

    # The distinct set of URLs a client rotates through. The page picks at random and
    # avoids repeating the previous one, so a single request cannot show the whole pool.
    public function clientUrlSet($client, $tries = 30)
    {
        $seen = array();
        for($i = 0; $i < $tries; $i++)
        {
            $u = $this->clientUrl($client);
            if($u !== null){$seen[$u] = true;}
        }
        $out = array_keys($seen);
        sort($out);
        return $out;
    }

    # --- admin ---------------------------------------------------------------

    # Mints a real session row and returns the cookie, rather than stubbing out the
    # auth check - the admin handlers are then exercised through their genuine
    # permission gates.
    public function adminLogin($username = 'tester', $perms = array())
    {
        $defaults = array('edit_urls' => 1, 'edit_emerg' => 1, 'edit_users' => 1,
                          'edit_options' => 1, 'c_messages' => 1, 'rss_feeds' => 1);
        $perms = array_merge($defaults, $perms);

        $db = $this->db();
        $db->prepare("DELETE FROM allowed_users WHERE username = ?")->execute(array($username));
        $db->prepare("DELETE FROM internal_users WHERE username = ?")->execute(array($username));
        $db->prepare("INSERT INTO internal_users (username, password, disabled, failed) VALUES (?, ?, 0, 0)")
           ->execute(array($username, password_hash('testpw', PASSWORD_DEFAULT)));
        $db->prepare("INSERT INTO allowed_users (username, domain, tz, edit_urls, edit_emerg, edit_users, edit_options, c_messages, rss_feeds)"
            ." VALUES (?, '', 'ewt:0', ?, ?, ?, ?, ?, ?)")
           ->execute(array($username, $perms['edit_urls'], $perms['edit_emerg'], $perms['edit_users'],
                           $perms['edit_options'], $perms['c_messages'], $perms['rss_feeds']));

        $hash = bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO hash_links (hash, time, username) VALUES (?, ?, ?)")
           ->execute(array($hash, time() + 3600, $username));
        return 'login_yes='.$hash.':'.$username;
    }

    public function admin($query, $post = null, $cookie = null)
    {
        return $this->http('/admin/index.php?'.ltrim($query, '?'), $post, $cookie);
    }

    # --- monitor -------------------------------------------------------------

    # Runs the emergency monitor against this install and returns its output.
    public function monitor($args = '')
    {
        $confPath = $this->root.'/monitor.conf.php';
        file_put_contents($confPath, "<?php\n\$uns_root = ".var_export($this->docroot, true).";\n");

        $script = $this->repoRoot.'/Scripts/EmergencyMonitor/uns-emergency-monitor.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
             .' --config='.escapeshellarg($confPath).' '.$args.' 2>&1';
        $out = array();
        $code = 0;
        exec($cmd, $out, $code);
        return array('code' => $code, 'out' => implode("\n", $out));
    }

    public function setMonitorFeed($url)
    {
        $existing = $this->one("SELECT cfg_key FROM uns_config WHERE cfg_key = 'emerg_feed_url'");
        if($existing){$this->exec("UPDATE uns_config SET cfg_value = ? WHERE cfg_key = 'emerg_feed_url'", array($url));}
        else{$this->exec("INSERT INTO uns_config (cfg_key, cfg_value) VALUES ('emerg_feed_url', ?)", array($url));}
    }
}
