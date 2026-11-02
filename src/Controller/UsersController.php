<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth\KitFactory;
use ArrayObject;
use Cake\Event\EventInterface;
use Cake\Log\Log;

/**
 * Users Controller
 *
 * Users are sourced from Active Directory (login form, see App\Identifier\AdIdentifier) or Keycloak (OIDC).
 * There is no internal model or database association.
 */
class UsersController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        // Allow users to login, logout and use SSO to login
        $this->Authentication->allowUnauthenticated(['login', 'logout', 'ssoLogin', 'oidcCallback']);
    }

    /**
     * Login method
     *
     * The credentials were already checked by the Authentication middleware (form authenticator); this action shows
     * the form and redirects on success.
     *
     * @return \Cake\Http\Response|null|void Redirects on successful login, renders view otherwise.
     */
    public function login()
    {
        $username = $this->request->getData('username');
        if (isset($username)) {
            if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $username)) {
                $this->Flash->error(sprintf('Invalid username or password, try again. <br><i>(Be sure to use your DMS username, NOT your email or Talk username)</i>'), ['escape' => false]);

                return;
            }
        }

        if ($this->request->is('post')) {
            $result = $this->Authentication->getResult();

            if ($result && $result->isValid()) {
                // `?redirect=` target if present (local paths only: the Cake 3 build redirected anywhere),
                // otherwise the home page (loginRedirect).
                $target = $this->Authentication->getLoginRedirect() ?? '/';

                return $this->redirect($target);
            }

            $this->Flash->error('Invalid username or password, try again.');
        }
    }

    /**
     * Logout method
     *
     * @return \Cake\Http\Response|null|void Redirects after logout.
     */
    public function logout()
    {
        $target = $this->Authentication->logout();
        // AuthComponent::logout() destroyed the whole session; the plugin only drops the identity.
        $this->request->getSession()->destroy();

        return $this->redirect($target);
    }

    /**
     * SSO Login method
     *
     * @return \Cake\Http\Response|null|void Redirects to keycloak for SSO login.
     */
    public function ssoLogin()
    {
        Log::debug('SSO Login');
        // The kit returns the authorization URL instead of header() + exit.
        return $this->redirect(KitFactory::oidc()->authenticate());
    }

    /**
     * OIDC Callback method, read userInfo returned by OIDC and populate the identity.
     *
     * Deliberate, owner-approved deviation from Cake 3 (handoff doc section 5): SSO users now get their groups
     * (KitFactory::oidc() sets fixGroupsBug), so they see the admin menus their groups unlock.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function oidcCallback()
    {
        Log::debug('OIDC Callback');
        // The Cake 3 action caught `Exception` (which, unqualified, matched nothing); this catches Throwable so a
        // failed callback ends on the login page with a flash instead of a 500.
        try {
            $oidcService = KitFactory::oidc();
            $session = $this->getRequest()->getSession();

            // Ensure the user is authenticated
            if ($oidcService->authenticateCallback(fn (string $token) => $session->write('oidc_token', $token))) {
                $user = $oidcService->getUserData();
                if (!$user) {
                    throw new \RuntimeException('Failed to retrieve user information');
                }

                $this->Authentication->setIdentity(new ArrayObject($user));

                // TODO(mandarl): Add logic to redirect to authDest

                Log::info('OIDC Callback: User authenticated ' . $user['samaccountname']);

                // The Cake 3 code redirected to ['controller' => '/', 'action' => 'index']; that is the home page.
                return $this->redirect('/');
            }
        } catch (\Throwable $e) {
            // Handle exceptions (e.g., user not found)
            Log::error('Authentication failed: ' . $e->getMessage());
            $this->Flash->error('Authentication failed: ' . $e->getMessage());

            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }
    }
}
