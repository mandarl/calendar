<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth\KitFactory;
use App\Middleware\UnauthorizedHandler;
use App\Policy\RequestPolicy;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Log\Log;

/**
 * Application Controller
 *
 * Authentication (LDAP form login, session identity) is done by the Authentication plugin; the per-controller group
 * rules of the old isAuthorized() live in App\Policy\RequestPolicy and are enforced for logged-in users on every
 * non-public action (see authorizeRequest()).
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 * @property \Crud\Controller\Component\CrudComponent $Crud
 */
class AppController extends Controller
{
    use \Crud\Controller\ControllerTrait;

    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Authentication.Authentication', [
            'logoutRedirect' => ['controller' => 'Events', 'action' => 'index'],
        ]);
        $this->loadComponent('Authorization.Authorization');
        $this->loadComponent('Crud.Crud', [
            'actions' => [
                'Crud.Index',
                'Crud.Add',
                'Crud.Edit',
                'Crud.View',
                'Crud.Delete',
            ],
        ]);
        $this->loadComponent('Flash');
        // Replaces SecurityComponent (form tampering protection). CSRF is the CsrfProtectionMiddleware.
        $this->loadComponent('FormProtection');
        // RequestHandler is gone in Cake 5 (JSON bodies: BodyParserMiddleware; isAjax: $request->is('ajax')).

        // Disables CRUD's default setFlash helper
        $this->getEventManager()->on('Crud.setFlash', function (EventInterface $event) {
            $event->stopPropagation();
        });

        $this->Crud->addListener('relatedModels', 'Crud.RelatedModels');

        $this->loadComponent('Email');

        // Around the Authentication component's own Controller.startup identity check (priority 10):
        // before it, queue the "not authorized" flash an anonymous visitor got in Cake 3; after it, authorize.
        $this->getEventManager()->on('Controller.startup', ['priority' => 5], function (EventInterface $event) {
            $this->flashIfAnonymous();
        });
        $this->getEventManager()->on('Controller.startup', ['priority' => 20], function (EventInterface $event) {
            return $this->authorizeRequest();
        });
    }

    /**
     * Anonymous visitor on a non-public action: Cake 3 queued the authError flash before redirecting to the login.
     *
     * @return void
     */
    protected function flashIfAnonymous(): void
    {
        $request = $this->getRequest();
        if (
            !$request->getAttribute('identity')
            && !in_array($request->getParam('action'), $this->Authentication->getUnauthenticatedActions(), true)
            && !$request->is('ajax')
        ) {
            UnauthorizedHandler::flash($request);
        }
    }

    /**
     * Run the request policy for a logged-in user on a non-public action. A denial throws ForbiddenException, which
     * AuthorizationMiddleware turns into the flash + redirect of UnauthorizedHandler.
     *
     * @return null
     */
    protected function authorizeRequest()
    {
        $request = $this->getRequest();
        if (
            $request->getAttribute('identity')
            && !in_array($request->getParam('action'), $this->Authentication->getUnauthenticatedActions(), true)
        ) {
            $this->Authorization->authorize($request, 'access');
        }

        return null;
    }

    /**
     * The logged-in user's data (what AuthComponent::user() used to return), or null for an anonymous visitor.
     *
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        return RequestPolicy::userFromIdentity($this->getRequest()->getAttribute('identity'));
    }

    /**
     * Shortcut for AuthComponent::user('samaccountname').
     *
     * @return string|null
     */
    public function currentUsername(): ?string
    {
        return $this->currentUser()['samaccountname'] ?? null;
    }

    public function customLog($description)
    {
        $user = $this->currentUsername();
        $action = $this->request->getParam('action');

        // don't log some stuff
        if (
            is_null($user) ||
            $action === 'index' ||
            $action === 'view'
        ) {
            return;
        }

        // log this
        $logs = $this->fetchTable('Logs');
        $log = $logs->newEmptyEntity();
        $log->description = $description;
        $log->user = $user;
        $log->date_time = date('Y-m-d H:i:s');
        $log->ip_address = $_SERVER['REMOTE_ADDR'];
        $log->url = $_SERVER['REQUEST_URI'];
        $log->controller = $this->request->getParam('controller');
        $log->action = $action;

        $logs->save($log);
    }

    /**
     * Before render callback.
     *
     * @param \Cake\Event\EventInterface $event The beforeRender event.
     * @return void
     */
    public function beforeRender(EventInterface $event)
    {
        $this->customLog('Viewed ' . $_SERVER['REQUEST_URI']);

        $isAuthorized = [
            'canManageCategories' => 0,
            'canManageCommittees' => 0,
            'canManageConfigs' => 0,
            'canManageEvents' => 0,
            'canManagePreqequisites' => 0,
            'canManageRooms' => 0,
            'canManageTools' => 0,
            'canManageHonoraria' => 0,
            'canManageW9s' => 0,
            'canDisableHonoraria' => 0,
            'canManageFinanceReports' => 0,
        ];

        $hasMenu = ['hasAdminMenu' => 0, 'hasFinancialMenu' => 0, 'hasCalendarAdminMenu' => 0];

        $user = $this->currentUser();

        if ($user) {
            $authorizations = [
                'Calendar Admins' => [
                    'canManageCategories',
                    'canManageCommittees',
                    'canManageConfigs',
                    'canManageContacts',
                    'canManageEvents',
                    'canManagePreqequisites',
                    'canManageRooms',
                    'canManageTools',
                ],
                'Honorarium Admins' => [
                    'canManageHonoraria',
                ],
                'Financial Reporting' => [
                    'canManageW9s',
                    'canManageFinanceReports',
                    'canExportHonoraria',
                ],
                'Calendar Super Admins' => [
                    'canDisableHonoraria',
                ],
            ];

            foreach ($authorizations as $group => $authorizedActions) {
                if ($this->inAdminstrativeGroup($user, $group)) {
                    $allow = true;
                } else {
                    $allow = false;
                }

                foreach ($authorizedActions as $authorizedAction) {
                    $isAuthorized[$authorizedAction] = $allow;
                }
            }
        }

        if ($this->inAdminstrativeGroup($user, 'Calendar Admins')) {
            $hasMenu['hasAdminMenu'] = 1;
        }

        if ($this->inAdminstrativeGroup($user, 'Calendar Super Admins')) {
            $hasMenu['hasCalendarAdminMenu'] = 1;
        }

        if ($this->inAdminstrativeGroup($user, 'Financial Reporting')) {
            $hasMenu['hasFinancialMenu'] = 1;
        }

        $this->set($isAuthorized);
        $this->set($hasMenu);
        $this->set('isDevelopment', Configure::read('isDevelopment'));
        // The templates read the logged-in user from here (was Session Auth.User).
        $this->set('authUser', $user);

        // Cake 3 set `_serialize` => true for json/xml responses; Cake 5 uses the `serialize` view option.
        if (in_array($this->response->getType(), ['application/json', 'application/xml'])) {
            $this->viewBuilder()->setOption('serialize', true);
        }
    }

    /**
     * General purpose authorization for calendar admins (AD: Calendar Admins). Same meaning as the Cake 3
     * AppController::isAuthorized(); the controllers call it as `parent::isAuthorized()` for "is a Calendar Admin".
     * The per-controller request rules that overrode it now live in App\Policy\RequestPolicy.
     *
     * @param array|null $user The user to check the authorization of.
     * @return bool True if $user is authorized, otherwise false
     */
    public function isAuthorized($user = null)
    {
        return $this->inAdminstrativeGroup($user, 'Calendar Admins');
    }

    /**
     * Check if the current user is a member of a specified group.
     *
     * @param string $group The AD group to check user membership against.
     * @param bool $forceRefreshGroups Fetch user groups from auth source even if they are already set.
     * @return bool True if $user is authorized, otherwise false
     */
    public function currentUserInGroup($group, $forceRefreshGroups = false)
    {
        $user = $this->currentUser();
        if ($this->inAdminstrativeGroup($user, $group)) {
            return true;
        }
        if (!$forceRefreshGroups) {
            return false;
        }
        if (!empty($user['ssologin'])) {
            $groups = KitFactory::oidc()->updateGroups($this->getRequest()->getSession()->read('oidc_token'));
        } else {
            $groups = $this->_refreshAdGroups($user);
        }
        if ($groups === null) {
            return false;
        }
        $user['groups'] = $groups;

        return $this->inAdminstrativeGroup($user, $group);
    }

    /**
     * Re-query Active Directory for a user's current group memberships using the service-account bind (the user's
     * password is not available post-login). Returns null on failure so callers can fall back to the cached check.
     *
     * @param array $user The auth session user.
     * @return array|null Group cn values, or null if the lookup failed.
     */
    private function _refreshAdGroups($user)
    {
        if (empty($user['samaccountname'])) {
            return null;
        }

        return KitFactory::directory()->groupsFor($user['samaccountname']);
    }

    /**
     * Check if the provided user is a member of a specified group.
     *
     * @param array|null $user The user to check the authorization of.
     * @param string $group The AD group to check user membership against.
     * @return bool True if $user is authorized, otherwise false
     */
    public function inAdminstrativeGroup($user, $group)
    {
        return RequestPolicy::inGroup($user, $group);
    }
}
