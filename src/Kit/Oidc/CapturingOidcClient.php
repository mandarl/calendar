<?php

declare(strict_types=1);

namespace Dms\Kit\Oidc;

use Jumbojett\OpenIDConnectClient;

/**
 * OpenIDConnectClient whose redirect() RECORDS the authorization URL instead of sending `Location:` and calling exit().
 *
 * Why: the stock client ends the PHP process inside authenticate() (header() + exit), which in Cake 5 bypasses the PSR-7
 * response/middleware pipeline (session/cookie middleware, response emitter) and cannot be tested. With this subclass the
 * controller does `return $this->redirect($service->authenticate());`. The library still stores state+nonce in $_SESSION.
 */
final class CapturingOidcClient extends OpenIDConnectClient
{
    public ?string $redirectUrl = null;

    /** @param string $url */
    public function redirect(string $url)
    {
        $this->redirectUrl = $url;
    }

    /**
     * Leave the session open. The stock client calls session_write_close() here, just before redirecting (it expected to
     * exit right after). Cake 5 owns the PHP session: it writes and closes it once, at the end of the request, and throws
     * "Could not close the session" when something closed it earlier, which made SSO login fail with a 500 whenever the
     * browser already had a session. The state and nonce stored in $_SESSION are saved when Cake closes the session.
     */
    protected function commitSession()
    {
    }
}
