<?php
declare(strict_types=1);

namespace App\Middleware;

use Authorization\Exception\Exception as AuthorizationException;
use Authorization\Exception\ForbiddenException;
use Authorization\Exception\MissingIdentityException;
use Authorization\Middleware\UnauthorizedHandler\HandlerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What the Cake 3 AuthComponent did when a logged-in user was not authorized: queue the
 * "You are not authorized to access that location." error flash and redirect to the local referer (or `/`).
 *
 * An anonymous visitor is never sent here for ordinary pages: AuthenticationComponent redirects them to the login
 * form first (with the same flash, see AppController).
 */
class UnauthorizedHandler implements HandlerInterface
{
    public const MESSAGE = 'You are not authorized to access that location.';

    /**
     * @inheritDoc
     */
    public function handle(
        AuthorizationException $exception,
        ServerRequestInterface $request,
        array $options = [],
    ): ResponseInterface {
        if (!$exception instanceof ForbiddenException && !$exception instanceof MissingIdentityException) {
            throw $exception;
        }

        self::flash($request);

        if ($exception instanceof MissingIdentityException) {
            $target = Router::url(['controller' => 'Users', 'action' => 'login']);
        } else {
            $referer = $request instanceof ServerRequest ? $request->referer(true) : null;
            $target = Router::url($referer ?: '/');
        }

        return (new Response())->withStatus(302)->withHeader('Location', $target);
    }

    /**
     * Queue the flash (element `flash/auth`, key `flash`; the Cake 3 AuthComponent rendered it as a bare `<div role="alert" class="error">`).
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return void
     */
    public static function flash(ServerRequestInterface $request): void
    {
        if ($request instanceof ServerRequest) {
            $request->getFlash()->set(self::MESSAGE, ['element' => 'auth']);
        }
    }
}
