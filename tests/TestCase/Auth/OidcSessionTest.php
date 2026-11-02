<?php
declare(strict_types=1);

namespace App\Test\TestCase\Auth;

use Cake\TestSuite\TestCase;
use Dms\Kit\Oidc\CapturingOidcClient;
use Dms\Kit\Oidc\OidcService;

/**
 * SSO login must leave the PHP session open for Cake to close.
 *
 * The OIDC library closed the session itself (session_write_close) right before redirecting to the identity provider.
 * Cake 5 then failed to close it again and threw "Could not close the session", so /users/sso-login answered 500 whenever
 * the browser already had a session (i.e. always after visiting the calendar). No network is used: the authorization
 * endpoint is set directly, so the client never fetches the provider's discovery document.
 */
class OidcSessionTest extends TestCase
{
    private string $savePath;

    protected function setUp(): void
    {
        parent::setUp();
        OidcService::loadClientQuietly();
        $this->savePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oidc-session-test-' . getmypid();
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath);
        }
        ini_set('session.save_path', $this->savePath);
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        parent::tearDown();
    }

    public function testStartingSsoLeavesTheSessionOpenWithStateAndNonce(): void
    {
        $client = new CapturingOidcClient('https://idp.example.test/realms/test', 'calendar', 'not-a-secret');
        $client->providerConfigParam(['authorization_endpoint' => 'https://idp.example.test/realms/test/auth']);
        $client->setRedirectURL('http://app.example.test/users/oidc-callback');

        $client->authenticate();

        $this->assertStringStartsWith('https://idp.example.test/realms/test/auth?', (string)$client->redirectUrl);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'the client must not close the session');
        $this->assertNotEmpty($_SESSION['openid_connect_state'] ?? null, 'state must be stored for the callback');
        $this->assertNotEmpty($_SESSION['openid_connect_nonce'] ?? null, 'nonce must be stored for the callback');
        $this->assertTrue(session_write_close(), 'the framework can still close the session itself');
    }
}
