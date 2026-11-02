<?php
declare(strict_types=1);

namespace App;

use App\Identifier\AdIdentifier;
use App\Middleware\RequestEntityTooLargeMiddleware;
use App\Middleware\UnauthorizedHandler;
use App\Policy\RequestPolicy;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Authorization\AuthorizationService;
use Authorization\AuthorizationServiceInterface;
use Authorization\AuthorizationServiceProviderInterface;
use Authorization\Identity as AuthorizationIdentity;
use Authorization\Middleware\AuthorizationMiddleware;
use Authorization\Policy\MapResolver;
use Cake\Core\Configure;
use Cake\Core\Exception\MissingPluginException;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ServerRequest;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application setup class: plugins, middleware, authentication and authorization services.
 */
class Application extends BaseApplication implements
    AuthenticationServiceProviderInterface,
    AuthorizationServiceProviderInterface
{
    /**
     * Load all the application configuration and bootstrap logic.
     *
     * Plugins are loaded explicitly (config/plugins.php, which composer's plugin installer may generate, is
     * git-ignored and not relied on).
     */
    public function bootstrap(): void
    {
        parent::bootstrap();

        if (PHP_SAPI === 'cli') {
            // Dev-only tool: absent from the --no-dev production image, so do not halt when missing.
            try {
                $this->addPlugin('Bake');
            } catch (MissingPluginException) {
            }
            $this->addPlugin('Migrations');
        }

        // DebugKit is a dev dependency; only load it in debug mode and when it is installed.
        if (Configure::read('debug') && class_exists(\DebugKit\Plugin::class)) {
            $this->addPlugin('DebugKit');
        }

        $this->addPlugin('Authentication');
        $this->addPlugin('Authorization');
        $this->addPlugin('Crud');
        $this->addPlugin('Josegonzalez/Upload');
        $this->addPlugin('CsvView');
    }

    /**
     * Setup the middleware queue the application will use.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            // Catch any exceptions in the lower layers, and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(new AssetMiddleware(['cacheTime' => Configure::read('Asset.cacheTime')]))

            ->add(new RoutingMiddleware($this))

            // Reject over-sized requests with a clear 413 before the CSRF middleware mistakes the truncated body
            // for a token mismatch.
            ->add(new RequestEntityTooLargeMiddleware())

            // The Cake 3 build loaded RequestHandler, which parsed JSON request bodies (it also parsed XML; add
            // 'xml' => true here if any client posts XML).
            ->add(new BodyParserMiddleware())

            // Cookie-based CSRF protection (replaces the Cake 3 token kept in the session).
            ->add(new CsrfProtectionMiddleware(['httponly' => true]))

            ->add(new AuthenticationMiddleware($this))

            ->add(new AuthorizationMiddleware($this, [
                'identityDecorator' => AuthorizationIdentity::class,
                // AppController runs the request policy for logged-in users; anonymous visitors of public actions
                // never trigger an authorization check, so do not insist on one.
                'requireAuthorizationCheck' => false,
                'unauthorizedHandler' => [
                    'className' => UnauthorizedHandler::class,
                ],
            ]));

        return $middlewareQueue;
    }

    /**
     * Authentication: a session authenticator plus the LDAP login form (the old AdAuthenticate).
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $loginUrl = Router::url(['controller' => 'Users', 'action' => 'login']);

        $service = new AuthenticationService([
            'unauthenticatedRedirect' => $loginUrl,
            'queryParam' => 'redirect',
        ]);

        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator('Authentication.Form', [
            'identifier' => [
                'className' => AdIdentifier::class,
                'username_field' => Configure::read('ActiveDirectory.username_field'),
                'base_dn' => Configure::read('ActiveDirectory.base_dn'),
                'domain_controllers' => Configure::read('ActiveDirectory.domain_controllers'),
                'admin_username' => Configure::read('ActiveDirectory.admin_username'),
                'admin_password' => Configure::read('ActiveDirectory.admin_password'),
                'account_suffix' => Configure::read('ActiveDirectory.account_suffix'),
                'use_tls' => Configure::read('ActiveDirectory.use_tls'),
            ],
            'fields' => ['username' => 'username', 'password' => 'password'],
            'loginUrl' => $loginUrl,
        ]);

        return $service;
    }

    /**
     * Authorization: one request policy that holds the group rules of the old per-controller isAuthorized().
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return \Authorization\AuthorizationServiceInterface
     */
    public function getAuthorizationService(ServerRequestInterface $request): AuthorizationServiceInterface
    {
        $resolver = new MapResolver();
        $resolver->map(ServerRequest::class, RequestPolicy::class);

        return new AuthorizationService($resolver);
    }
}
