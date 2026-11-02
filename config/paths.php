<?php
/**
 * Path constants used by the framework and the app.
 */

/*
 * Use the DS to separate the directories in other defines
 */
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

/*
 * These defines should only be edited if you have CakePHP installed in
 * a directory layout different from the one distributed with the skeleton.
 */

// Root directory of the project (the directory that contains src/, config/, webroot/).
define('ROOT', dirname(__DIR__));

// Name of the application directory.
define('APP_DIR', 'src');

// Path to the application's directory.
define('APP', ROOT . DS . APP_DIR . DS);

// Path to the config directory.
define('CONFIG', ROOT . DS . 'config' . DS);

// File path to the webroot directory.
define('WWW_ROOT', ROOT . DS . 'webroot' . DS);

// Path to the tests directory.
define('TESTS', ROOT . DS . 'tests' . DS);

// Path to the temporary files directory.
define('TMP', ROOT . DS . 'tmp' . DS);

// Path to the logs directory.
define('LOGS', ROOT . DS . 'logs' . DS);

// Path to the resources directory (locales).
define('RESOURCES', ROOT . DS . 'resources' . DS);

// Path to the cache files directory. It can be shared between hosts in a multi-server setup.
define('CACHE', TMP . 'cache' . DS);

/*
 * The absolute path to the "cake" directory, WITHOUT a trailing DS.
 */
define('CAKE_CORE_INCLUDE_PATH', ROOT . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp');

/*
 * Path to the cake directory.
 */
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);
