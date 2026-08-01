<?php
# UNS test runner.
#
#   php Tests/run-tests.php              run everything
#   php Tests/run-tests.php groups       run only files matching "groups"
#   php Tests/run-tests.php --verbose    print each passing assertion too
#
# One throwaway install and one web server are built for the whole run; each test
# gets a freshly rebuilt database, so tests are isolated without paying to copy the
# tree and restart a server every time.
#
# Exit code is 0 when everything passes, 1 otherwise, so CI can gate on it.

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__.'/lib/harness.php';

$GLOBALS['uns_tests']    = array();
$GLOBALS['uns_verbose']  = false;
$GLOBALS['uns_current']  = null;
$GLOBALS['uns_failures'] = array();
$GLOBALS['uns_checks']   = 0;

# --- registration and assertions --------------------------------------------

function uns_test($name, $fn)
{
    $GLOBALS['uns_tests'][] = array('name' => $name, 'fn' => $fn, 'file' => $GLOBALS['uns_file']);
}

function uns_fail_check($message, $detail = '')
{
    $GLOBALS['uns_failures'][] = array(
        'test'    => $GLOBALS['uns_current'],
        'message' => $message,
        'detail'  => $detail,
    );
    echo "\n    FAIL: ".$message.($detail !== '' ? "\n          ".$detail : '')."\n";
}

function uns_pass_check($message)
{
    $GLOBALS['uns_checks']++;
    if($GLOBALS['uns_verbose']){echo "\n    ok: ".$message;}
}

function uns_render($v)
{
    if(is_array($v)){return '['.implode(', ', array_map('uns_render', $v)).']';}
    if($v === null){return 'null';}
    if(is_bool($v)){return $v ? 'true' : 'false';}
    return (string)$v;
}

function assert_same($expected, $actual, $message)
{
    if($expected === $actual){uns_pass_check($message); return;}
    uns_fail_check($message, 'expected '.uns_render($expected).', got '.uns_render($actual));
}

function assert_equals($expected, $actual, $message)
{
    if($expected == $actual){uns_pass_check($message); return;}
    uns_fail_check($message, 'expected '.uns_render($expected).', got '.uns_render($actual));
}

function assert_true($cond, $message)
{
    if($cond){uns_pass_check($message); return;}
    uns_fail_check($message, 'expected true');
}

function assert_false($cond, $message)
{
    if(!$cond){uns_pass_check($message); return;}
    uns_fail_check($message, 'expected false');
}

function assert_contains($needle, $haystack, $message)
{
    if(strpos((string)$haystack, (string)$needle) !== false){uns_pass_check($message); return;}
    uns_fail_check($message, 'expected to find '.uns_render($needle)
        .' in: '.substr(preg_replace('/\s+/', ' ', (string)$haystack), 0, 300));
}

function assert_not_contains($needle, $haystack, $message)
{
    if(strpos((string)$haystack, (string)$needle) === false){uns_pass_check($message); return;}
    uns_fail_check($message, 'did not expect to find '.uns_render($needle));
}

# --- argument handling ------------------------------------------------------

$filter = null;
for($i = 1; $i < $argc; $i++)
{
    $arg = $argv[$i];
    if($arg === '--verbose' || $arg === '-v'){$GLOBALS['uns_verbose'] = true;}
    elseif($arg === '--help' || $arg === '-h')
    {
        echo "Usage: php Tests/run-tests.php [FILTER] [--verbose]\n";
        exit(0);
    }
    else{$filter = $arg;}
}

# --- collect ----------------------------------------------------------------

$repoRoot = dirname(__DIR__);
$files = glob(__DIR__.'/tests/*.php');
sort($files);

foreach($files as $file)
{
    if($filter !== null && stripos(basename($file), $filter) === false){continue;}
    $GLOBALS['uns_file'] = basename($file);
    require $file;
}

if(!$GLOBALS['uns_tests'])
{
    echo "No tests matched.\n";
    exit(1);
}

# --- run --------------------------------------------------------------------

echo "UNS test suite - PHP ".PHP_VERSION."\n";
echo str_repeat('-', 62)."\n";

$site = null;
$started = microtime(true);
$ran = 0;

try
{
    $site = new UnsTestSite($repoRoot);

    $lastFile = null;
    foreach($GLOBALS['uns_tests'] as $test)
    {
        if($test['file'] !== $lastFile)
        {
            echo "\n".$test['file']."\n";
            $lastFile = $test['file'];
        }

        $GLOBALS['uns_current'] = $test['name'];
        $before = count($GLOBALS['uns_failures']);

        echo '  '.$test['name'];
        $site->resetDb();

        try
        {
            call_user_func($test['fn'], $site);
        }
        catch(Throwable $e)
        {
            uns_fail_check('threw '.get_class($e).': '.$e->getMessage(),
                basename($e->getFile()).':'.$e->getLine());
        }

        $ran++;
        echo (count($GLOBALS['uns_failures']) === $before) ? "  ok\n" : "\n";
    }
}
catch(Throwable $e)
{
    echo "\nHarness error: ".$e->getMessage()."\n".$e->getTraceAsString()."\n";
    if($site !== null){$site->stop();}
    exit(1);
}

$log = $site->serverLog();
$site->stop();

# --- report -----------------------------------------------------------------

$failures = $GLOBALS['uns_failures'];
echo "\n".str_repeat('-', 62)."\n";
printf("%d tests, %d checks, %d failures in %.1fs\n",
    $ran, $GLOBALS['uns_checks'], count($failures), microtime(true) - $started);

# PHP notices and warnings from the application land in the server's stderr. They are
# not failures on their own, but they are how a latent bug usually announces itself.
if(preg_match_all('/PHP (Warning|Notice|Fatal error|Deprecated)[^\n]*/', $log, $m))
{
    $seen = array_unique($m[0]);
    echo "\nServer log diagnostics (".count($seen)." unique):\n";
    foreach(array_slice($seen, 0, 25) as $line){echo '  '.trim($line)."\n";}
}

if($failures)
{
    echo "\nFailures:\n";
    foreach($failures as $f)
    {
        echo '  - ['.$f['test'].'] '.$f['message']."\n";
        if($f['detail'] !== ''){echo '      '.$f['detail']."\n";}
    }
    exit(1);
}

echo "\nAll good.\n";
exit(0);
