<?php
/**
 * Test-only config adapter for the CakePHP 5 build. Bind-mounted over the image's config/app.php.
 *
 * Production mail goes through the SparkPost API under the transport name `sparkpost` (config/app.default.php).
 * Until a fake SparkPost endpoint exists in the harness, route that name to the local MailHog SMTP sink so mail can
 * be captured without changing application code, exactly as adapters/cake3 does. Test double only.
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

/*
 * libfaketime workaround (test double only). The faketime sidecar is built on Debian 11 (glibc 2.31) but the Cake 5
 * image is Debian 12 (glibc 2.36): there libfaketime fakes time() but NOT file mtimes (verified: PHP sees
 * time() = 2031 and filemtime() = the real clock). PHP's session garbage collector (1 request in 1000) then sees every
 * session file as years old and deletes ALL sessions, logging every saved login out in the middle of a run. Turn the
 * collector off for the test stack. Proper fix, for the harness owner: build faketime/Dockerfile on bookworm.
 */
$config['Session']['ini']['session.gc_probability'] = 0;

return $config;
