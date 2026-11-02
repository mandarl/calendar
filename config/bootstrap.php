<?php
declare(strict_types=1);

/**
 * Configure paths required to find CakePHP + general filepath constants
 */
require __DIR__ . '/paths.php';

/*
 * Bootstrap CakePHP.
 */
require CORE_PATH . 'config' . DS . 'bootstrap.php';

/*
 * Global helper functions (env(), h(), __(), pr(), ...). CakePHP 5 only autoloads the namespaced versions; the
 * global aliases that config/app.default.php, the templates and the adapters use come from this file.
 */
require_once CAKE . 'functions.php';

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Database\TypeFactory;
use Cake\Datasource\ConnectionManager;
use Cake\Error\ErrorTrap;
use Cake\Error\ExceptionTrap;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\Utility\Security;

/*
 * Read configuration file and inject configuration into various CakePHP classes.
 *
 * config/app.php is a copy of (or an overlay over) config/app.default.php: see the Dockerfile and the e2e adapter.
 */
try {
    Configure::config('default', new PhpConfig());
    if (is_file(CONFIG . 'app.php')) {
        Configure::load('app', 'default', false);
    } else {
        // A fresh checkout (unit tests, composer scripts) has no config/app.php yet: use the shipped defaults.
        Configure::write(require CONFIG . 'app.default.php');
    }
} catch (Exception $e) {
    exit($e->getMessage() . "\n");
}

/*
 * Set the shorter cache durations in debug mode.
 */
if (Configure::read('debug')) {
    Configure::write('Cache._cake_model_.duration', '+2 minutes');
    Configure::write('Cache._cake_translations_.duration', '+2 minutes');
}

/*
 * Set the default server timezone. Using UTC makes time calculations / conversions easier.
 */
date_default_timezone_set(Configure::read('App.defaultTimezone'));

/*
 * Configure the mbstring extension to use the correct encoding.
 */
mb_internal_encoding(Configure::read('App.encoding'));

/*
 * Set the default locale. This controls how dates, number and currency is formatted.
 */
ini_set('intl.default_locale', Configure::read('App.defaultLocale'));

/*
 * Register application error and exception handlers.
 */
(new ErrorTrap(Configure::read('Error')))->register();
(new ExceptionTrap(Configure::read('Error')))->register();

if (PHP_SAPI === 'cli') {
    // Set logs to different files so they don't have permission conflicts.
    Configure::write('Log.debug.file', 'cli-debug');
    Configure::write('Log.error.file', 'cli-error');
}

/*
 * Include the CLI bootstrap overrides: not needed (the only override is the log file names above).
 */

/*
 * Set the full base URL. This URL is used as the base of all absolute links.
 */
if (!Configure::read('App.fullBaseUrl')) {
    $s = null;
    if (env('HTTPS')) {
        $s = 's';
    }

    $httpHost = env('HTTP_HOST');
    if (isset($httpHost)) {
        Configure::write('App.fullBaseUrl', 'http' . $s . '://' . $httpHost);
    }
    unset($httpHost, $s);
}

/*
 * Apply the configuration to the framework classes.
 */
Cache::setConfig(Configure::consume('Cache'));
ConnectionManager::setConfig(Configure::consume('Datasources'));
TransportFactory::setConfig(Configure::consume('EmailTransport'));
Mailer::setConfig(Configure::consume('Email'));
Log::setConfig(Configure::consume('Log'));
Security::setSalt((string)Configure::consume('Security.salt'));

/*
 * Request detectors, carried over from the Cake 3 build (not used by any template today).
 */
ServerRequest::addDetector('mobile', function ($request) {
    $detector = new \Detection\MobileDetect();

    return $detector->isMobile();
});
ServerRequest::addDetector('tablet', function ($request) {
    $detector = new \Detection\MobileDetect();

    return $detector->isTablet();
});

/*
 * Date/time types are immutable by default in CakePHP 5 (Cake\I18n\DateTime / Date), which is what the Cake 3 build
 * opted into with Type::build('datetime')->useImmutable(); no setup needed.
 *
 * Cake 3 always converted DateTime values to UTC when writing them to the database or comparing in a query;
 * Cake 5 only does so when the type has a database timezone. The app stores UTC, so say so explicitly: a
 * Chicago-zoned DateTime in a `where()` or a save must still be written as UTC.
 */
foreach (['datetime', 'datetimefractional', 'timestamp', 'timestampfractional'] as $typeName) {
    TypeFactory::build($typeName)->setDatabaseTimezone('UTC');
}
