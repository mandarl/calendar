<?php

declare(strict_types=1);

namespace Dms\Kit\Oidc;

use Jumbojett\OpenIDConnectClient;

/**
 * OpenIDConnectClient whose redirect() RECORDS the authorization URL instead of sending `Location:` and calling exit().
 *
 * Why: the stock client ends the PHP process inside authenticate() (header() + exit), which in Cake 5 bypasses the PSR-7
 * response/middleware pipeline (session/cookie middleware, response emitter) and cannot be tested. With this subclass the
 * controller does `return $this->redirect($service->authenticate());`. The library still stores state+nonce in $_SESSION and
 * closes the session (session_write_close) right before redirecting, exactly as before.
 */
final class CapturingOidcClient extends OpenIDConnectClient
{
    public ?string $redirectUrl = null;

    /** @param string $url */
    public function redirect(string $url)
    {
        $this->redirectUrl = $url;
    }
}
