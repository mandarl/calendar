<?php
/**
 * Application configuration (CakePHP 5). Every value comes from the same environment variables as the Cake 3
 * build (see .docker/environment.conf); the runtime contract is unchanged.
 *
 * Production copies this file to config/app.php at image build. The e2e harness bind-mounts an adapter over
 * config/app.php that `require`s this file and overlays only the mail transport.
 */

use Cake\Cache\Engine\FileEngine;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Error\Renderer\WebExceptionRenderer;
use Cake\Log\Engine\FileLog;
use Cake\Routing\Exception\MissingControllerException;

$debug = filter_var(env('DEBUG', false), FILTER_VALIDATE_BOOLEAN);

return [
    'debug' => $debug,
    'isDevelopment' => $debug,

    'App' => [
        'namespace' => 'App',
        'encoding' => env('APP_ENCODING', 'UTF-8'),
        'defaultLocale' => env('APP_DEFAULT_LOCALE', 'en_US'),
        'defaultTimezone' => env('APP_DEFAULT_TIMEZONE', 'UTC'),
        'base' => false,
        'dir' => 'src',
        'webroot' => 'webroot',
        'wwwRoot' => WWW_ROOT,
        'fullBaseUrl' => false,
        'imageBaseUrl' => 'img/',
        'cssBaseUrl' => 'css/',
        'jsBaseUrl' => 'js/',
        'paths' => [
            'plugins' => [ROOT . DS . 'plugins' . DS],
            'templates' => [ROOT . DS . 'templates' . DS],
            'locales' => [RESOURCES . 'locales' . DS],
        ],
    ],

    /*
     * NOTE (carried over from the Cake 3 build, see handoff doc section 5 / workbreakdown): the Apache SetEnv in
     * .docker/environment.conf exports APP_SECURITY_SALT, but this key has always read SECURITY_SALT, so
     * production runs on the public default salt. Behaviour preserved; fix deliberately, not silently.
     */
    'Security' => [
        'salt' => env('SECURITY_SALT', 'SOME_SECURE_SALT'),
    ],

    'Asset' => [
    ],

    'Cache' => [
        'default' => [
            'className' => FileEngine::class,
            'path' => CACHE,
            'url' => env('CACHE_DEFAULT_URL', null),
        ],
        '_cake_translations_' => [
            'className' => FileEngine::class,
            'prefix' => 'myapp_cake_translations_',
            'path' => CACHE . 'persistent' . DS,
            'serialize' => true,
            'duration' => '+2 minutes',
            'url' => env('CACHE_CAKECORE_URL', null),
        ],
        '_cake_model_' => [
            'className' => FileEngine::class,
            'prefix' => 'myapp_cake_model_',
            'path' => CACHE . 'models' . DS,
            'serialize' => true,
            'duration' => '+2 minutes',
            'url' => env('CACHE_CAKEMODEL_URL', null),
        ],
    ],

    'Error' => [
        'errorLevel' => E_ALL & ~E_DEPRECATED,
        'skipLog' => [MissingControllerException::class],
        'exceptionRenderer' => WebExceptionRenderer::class,
        'log' => true,
        'trace' => true,
        'ignoredDeprecationPaths' => [],
    ],

    /*
     * Mail. Owner decision: mail goes out through the SparkPost API, as in the Cake 3 build (where a Cake-3-only
     * plugin provided the `sparkpost` transport), with click tracking OFF for every transmission: the kit transport
     * sends options.click_tracking = false and refuses an `options` config that enables it. Open tracking is left
     * at the SparkPost default, as the old plugin did.
     *
     * `sparkpost` is the libkit transport (src/Kit/Cake/SparkPostTransport.php, a Cake AbstractTransport using the
     * kit's small PSR-18 client; the official sparkpost/sparkpost package is NOT used). Optional keys: base_url
     * (SPARKPOST_BASE_URL, e.g. the EU endpoint or a test double), timeout, options, client. The mail code is written
     * against Cake\Mailer\Mailer and only names the transport through Email.default.transport. Keep a
     * `catch (Throwable)` around every send: the transport throws MailException on any failure.
     *
     * `default` is a plain SMTP transport from EMAIL_HOST / EMAIL_PORT / EMAIL_TIMEOUT / EMAIL_TLS (optional
     * EMAIL_USERNAME / EMAIL_PASSWORD) for local development; select it with EMAIL_TRANSPORT=default.
     */
    'EmailTransport' => [
        'default' => [
            'className' => 'Smtp',
            'host' => env('EMAIL_HOST', 'localhost'),
            'port' => (int)env('EMAIL_PORT', 25),
            'timeout' => (int)env('EMAIL_TIMEOUT', 30),
            'username' => env('EMAIL_USERNAME', null),
            'password' => env('EMAIL_PASSWORD', null),
            'client' => null,
            'tls' => filter_var(env('EMAIL_TLS', false), FILTER_VALIDATE_BOOLEAN),
            'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
        ],
        'sparkpost' => [
            'className' => \Dms\Kit\Cake\SparkPostTransport::class,
            'apiKey' => env('SPARKPOST_APIKEY', null),
            'base_url' => env('SPARKPOST_BASE_URL', 'https://api.sparkpost.com'),
            'timeout' => (int)env('EMAIL_TIMEOUT', 30),
        ],
    ],

    'Email' => [
        'default' => [
            'transport' => env('EMAIL_TRANSPORT', 'sparkpost'),
            'from' => env('EMAIL_FROM', 'admin@dallasmakerspace.org'),
        ],
    ],

    'Datasources' => [
        'default' => [
            'className' => Connection::class,
            'driver' => Mysql::class,
            'persistent' => false,
            'host' => env('DB_HOST', 'localhost'),
            'username' => env('DB_USERNAME', 'mysql'),
            'password' => env('DB_PASSWORD', 'mysql'),
            'database' => env('DB_DATABASE', 'dms-calendar'),
            // The schema is utf8 (utf8mb3). Do not move to utf8mb4 without a schema change decided by the owner.
            'encoding' => 'utf8',
            'timezone' => 'UTC',
            'flags' => [],
            'cacheMetadata' => true,
            'log' => false,
            'quoteIdentifiers' => false,
            'url' => env('DATABASE_URL', null),
        ],
    ],

    'Log' => [
        'debug' => [
            'className' => FileLog::class,
            'path' => LOGS,
            'file' => 'debug',
            'rotate' => 10,
            'size' => 10485760,
            'levels' => $debug ? ['notice', 'info', 'debug'] : [],
        ],
        'error' => [
            'className' => FileLog::class,
            'path' => LOGS,
            'file' => 'error',
            'rotate' => 10,
            'size' => 10485760,
            'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ],
        'queries' => [
            'className' => FileLog::class,
            'path' => LOGS,
            'file' => 'queries',
            'rotate' => 10,
            'size' => 10485760,
            'scopes' => $debug ? ['queriesLog'] : [],
        ],
    ],

    'Session' => [
        'defaults' => 'php',
        'timeout' => 86400, // minutes (60 days), as in the Cake 3 build
    ],

    'DebugKit' => [
        'ignoreAuthorization' => true,
    ],

    'Braintree' => [
        'environment' => env('BRAINTREE_ENV', null),
        'merchantId' => env('BRAINTREE_MERCHID', null),
        'publicKey' => env('BRAINTREE_PUBKEY', null),
        'privateKey' => env('BRAINTREE_PRIVKEY', null),
    ],

    'Twilio' => [
        'accountSid' => env('TWILIO_ACCTSID', null),
        'authToken' => env('TWILIO_AUTHTOKEN', null),
        'phone' => env('TWILIO_PHONENUM', null),
    ],

    'Webhooks' => [
        'targets' => json_decode(env('WEBHOOK_TARGETS', '[]'), true) ?: [],
        'timeout' => (int)env('WEBHOOK_TIMEOUT', 3), // seconds, per target
    ],

    'ActiveDirectory' => [
        'account_suffix' => env('AD_SUFFIX', '@dms.local'),
        'admin_username' => env('AD_BIND_UN', ''),
        'admin_password' => env('AD_BIND_PW', ''),
        'username_field' => 'sAMAccountName',
        'use_tls' => filter_var(env('AD_USE_TLS', true), FILTER_VALIDATE_BOOLEAN),
        'base_dn' => env('AD_BASE', 'DC=dev,DC=com'),
        'domain_controllers' => [env('AD_SERVER', 'dms.local')],
    ],

    'OIDC' => [
        'client_id' => env('OIDC_CLIENT_ID', 'calendar'),
        'client_secret' => env('OIDC_CLIENT_SECRET', 'dummy-secret-for-dev-mode'),
        'redirect_uri' => env('BASE_URL', 'http://localhost:8000') . '/users/oidc-callback',
        'url_authorize' => env('OIDC_URL_AUTHORIZE', 'http://keycloak:8080/realms/DMS/'),
        'url_access_token' => env('OIDC_URL_ACCESS_TOKEN', 'http://keycloak:8080/token'),
        'url_resource_owner_details' => env('OIDC_URL_RESOURCE_OWNER', 'http://keycloak:8080/realms/DMS/protocol/openid-connect/userinfo'),
    ],
];
