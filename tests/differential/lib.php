<?php
/**
 * Version shim for the differential tests (brief rule 9). Runs UNCHANGED under the Cake 3 image
 * (PHP 7.4, app in /var/www) and under the Cake 5 build (PHP 8.4, app in /app), against the same
 * synthetic database, and prints JSON. tests/differential/run.sh diffs the two outputs.
 *
 * Keep this file and every cases_*.php PHP 7.4 compatible (no enums, no match, no named args,
 * no union types, no readonly, no str_contains).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
date_default_timezone_set('UTC');
$GLOBALS['DIFF_CURRENT'] = '(setup)';
$GLOBALS['DIFF_WARN'] = [];
/** Warnings/notices raised while a case runs go to the stderr side channel (not compared). */
function diff_install_handler()
{
    set_error_handler(function ($no, $str, $file, $line) {
        $GLOBALS['DIFF_WARN'][] = $GLOBALS['DIFF_CURRENT'] . "	[" . $no . '] ' . $str . ' @' . basename($file) . ':' . $line;
        return true;
    });
}
diff_install_handler();

$root = is_dir('/var/www/vendor/cakephp/cakephp') ? '/var/www' : '/app';
define('DIFF_ROOT', $root);
require $root . '/vendor/autoload.php';
define('DIFF_CAKE5', class_exists('Cake\I18n\DateTime'));

if (DIFF_CAKE5) {
    if (!defined('CONFIG')) {
        require $root . '/config/paths.php';
    }
    $diffApp = new \App\Application(CONFIG);
    $diffApp->bootstrap();
    $diffApp->pluginBootstrap();
} else {
    require $root . '/config/bootstrap.php';
}
\Cake\Core\Configure::write('debug', false);

diff_install_handler();

/** Side channel for exception details (not compared; the compared JSON only says "exception"). */
$GLOBALS['DIFF_EXC'] = [];

/** Freeze "now" for isWithinNext() and every other Chronos clock read, in every Chronos flavour. */
function diff_set_now($utc)
{
    $classes = DIFF_CAKE5
        ? ['Cake\Chronos\Chronos', 'Cake\I18n\DateTime']
        : ['Cake\Chronos\Chronos', 'Cake\I18n\Time', 'Cake\I18n\FrozenTime', 'Cake\Chronos\MutableDateTime'];
    foreach ($classes as $c) {
        if (class_exists($c)) {
            $c::setTestNow(new $c($utc, 'UTC'));
        }
    }
}

function diff_clear_now()
{
    diff_set_now('now');
    $classes = DIFF_CAKE5
        ? ['Cake\Chronos\Chronos', 'Cake\I18n\DateTime']
        : ['Cake\Chronos\Chronos', 'Cake\I18n\Time', 'Cake\I18n\FrozenTime', 'Cake\Chronos\MutableDateTime'];
    foreach ($classes as $c) {
        if (class_exists($c)) {
            $c::setTestNow(null);
        }
    }
}

function diff_table($alias)
{
    if (DIFF_CAKE5) {
        return \Cake\Datasource\FactoryLocator::get('Table')->get($alias);
    }
    return \Cake\ORM\TableRegistry::get($alias);
}

function diff_errors($entity)
{
    return DIFF_CAKE5 ? $entity->getErrors() : $entity->errors();
}

function diff_conn()
{
    return \Cake\Datasource\ConnectionManager::get('default');
}

/** Plain, version-neutral rendering of a value so both versions compare as JSON. Dates are shown in UTC. */
function diff_scalar($v)
{
    if ($v instanceof \DateTimeInterface) {
        $c = clone $v;
        $c = $c->setTimezone(new \DateTimeZone('UTC'));
        return 'DT:' . $c->format('Y-m-d H:i:s');
    }
    if (is_float($v)) {
        return 'F:' . $v;
    }
    if (is_bool($v)) {
        return 'B:' . ($v ? '1' : '0');
    }
    if (is_int($v)) {
        return 'I:' . $v;
    }
    if (is_array($v)) {
        $o = [];
        foreach ($v as $k => $x) {
            $o[$k] = diff_scalar($x);
        }
        return $o;
    }
    if ($v instanceof \Cake\Datasource\EntityInterface) {
        return diff_scalar($v->toArray());
    }
    if (is_object($v)) {
        return 'OBJ:' . get_class($v);
    }
    return $v;
}

/** Run one case. Exceptions are recorded as {"exception": true}; the detail goes to DIFF_EXC / stderr. */
function diff_run($name, $fn)
{
    $GLOBALS['DIFF_CURRENT'] = $name;
    try {
        return ['ok' => diff_scalar($fn())];
    } catch (\Throwable $e) {
        $GLOBALS['DIFF_EXC'][$name] = get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
        return ['exception' => true];
    }
}

/** Run $fn inside a transaction that is always rolled back (the DB stays pristine). */
function diff_tx($fn)
{
    $conn = diff_conn();
    $conn->begin();
    try {
        return $fn();
    } finally {
        $conn->rollback();
    }
}

function diff_main(array $cases)
{
    $out = [];
    foreach ($cases as $name => $fn) {
        $out[$name] = diff_run($name, $fn);
    }
    ksort($out);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    foreach ($GLOBALS['DIFF_EXC'] as $n => $m) {
        fwrite(STDERR, "EXC\t" . $n . "\t" . $m . "\n");
    }
    foreach ($GLOBALS['DIFF_WARN'] as $w) {
        fwrite(STDERR, "WARN\t" . $w . "\n");
    }
}

/** Entity -> compared structure: errors plus every field of the entity. */
function diff_entity_result($entity, $only = null)
{
    $data = $entity->toArray();
    if ($only !== null) {
        $data = array_intersect_key($data, array_flip($only));
    }
    ksort($data);
    return ['errors' => diff_errors($entity), 'data' => $data];
}
