<?php
# Compiles every Smarty template, so a syntax error in a screen the test suite never
# happens to visit still fails the build.
#
# Compiling only checks the template parses and its tags are valid - it does not run
# it, so nothing here needs a database or the variables a screen would normally be
# given.

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__).'/Server/www';

# uns_smarty() resolves a writable compile directory relative to a real install; point
# it somewhere temporary so this can run against a bare checkout.
$tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/uns-tpl-'.getmypid();
@mkdir($tmp.'/templates_c', 0777, true);
@mkdir($tmp.'/templates_cache', 0777, true);

require $root.'/lib/smarty/libs/Smarty.class.php';

$smarty = new \Smarty\Smarty();
$smarty->setTemplateDir($root.'/templates');
$smarty->setCompileDir($tmp.'/templates_c');
$smarty->setCacheDir($tmp.'/templates_cache');
$smarty->setEscapeHtml(true);

$templates = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/templates',
    RecursiveDirectoryIterator::SKIP_DOTS));
foreach($it as $file)
{
    if(strtolower($file->getExtension()) !== 'tpl'){continue;}
    $templates[] = str_replace('\\', '/', $file->getPathname());
}
sort($templates);

if(!$templates)
{
    echo "No templates found under ".$root."/templates\n";
    exit(1);
}

$base   = str_replace('\\', '/', $root.'/templates').'/';
$failed = array();

foreach($templates as $path)
{
    $name = str_replace($base, '', $path);
    try
    {
        # Layout partials use {block} and are only meaningful through {extends}; asking
        # Smarty to compile them standalone is still a valid parse check.
        $smarty->createTemplate($name)->compileTemplateSource();
        printf("  ok    %s\n", $name);
    }
    catch(Throwable $e)
    {
        $failed[$name] = $e->getMessage();
        printf("  FAIL  %s\n          %s\n", $name, $e->getMessage());
    }
}

# Leave nothing behind.
foreach(array($tmp.'/templates_c', $tmp.'/templates_cache') as $dir)
{
    foreach((array)glob($dir.'/*') as $f){if(is_file($f)){@unlink($f);}}
    @rmdir($dir);
}
@rmdir($tmp);

echo "\n".count($templates)." templates, ".count($failed)." failed\n";
exit($failed ? 1 : 0);
