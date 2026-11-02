<?php
/**
 * Test-only config adapter for the CakePHP 3 build. Bind-mounted over the image's config/app.php.
 * Routes the hard-coded 'sparkpost' mail transport (src/Controller/Component/EmailComponent.php) to
 * the local MailHog SMTP sink so mail can be captured without changing application code.
 */
$config = require __DIR__ . '/app.default.php';

$config['EmailTransport']['sparkpost'] = [
    'className' => 'Smtp',
    'host' => env('EMAIL_HOST', 'mail'),
    'port' => (int)env('EMAIL_PORT', 1025),
    'timeout' => 5,
    'tls' => false,
    'username' => null,
    'password' => null,
    'client' => null,
];

return $config;
