<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;
use Authorization\Policy\RequestPolicyInterface;
use Cake\Http\ServerRequest;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Request-level authorization: the group rules that used to live in each controller's isAuthorized().
 *
 * Rules (unchanged from the Cake 3 build):
 *  - default                   Calendar Admins
 *  - CalendarAdmin, Logs       Calendar Super Admins
 *  - Contacts::view            Honorarium Admins, Financial Reporting or Calendar Admins
 *  - Events                    per-action table below; unlisted actions are denied for everyone
 *  - Registrations             owner (edit key / AD user) or event creator or Calendar Admins
 *
 * Actions that the controller marks as public (AuthenticationComponent::allowUnauthenticated) are never
 * checked, exactly as AuthComponent::allow() bypassed isAuthorized().
 *
 * The user is the identity array built at login: `groups` is a list of AD group names.
 */
class RequestPolicy implements RequestPolicyInterface
{
    use LocatorAwareTrait;

    /**
     * @inheritDoc
     */
    public function canAccess(?IdentityInterface $identity, ServerRequest $request): bool
    {
        return $this->check(self::userFromIdentity($identity), $request);
    }

    /**
     * Same decision, from a plain user array (null = anonymous).
     *
     * @param array<string, mixed>|null $user Identity data.
     * @param \Cake\Http\ServerRequest $request Request (controller, action and pass params are used).
     * @return bool
     */
    public function check(?array $user, ServerRequest $request): bool
    {
        $controller = $request->getParam('controller');
        $action = $request->getParam('action');
        $pass = $request->getParam('pass') ?? [];
        $id = (int)($pass[0] ?? 0);

        switch ($controller) {
            case 'CalendarAdmin':
            case 'Logs':
                return self::inGroup($user, 'Calendar Super Admins');

            case 'Contacts':
                if ($action == 'view') {
                    if (self::inGroup($user, 'Honorarium Admins') || self::inGroup($user, 'Financial Reporting')) {
                        return true;
                    }
                }

                return self::inGroup($user, 'Calendar Admins');

            case 'Events':
                return $this->checkEvents($user, $action, $id);

            case 'Registrations':
                return $this->checkRegistrations($user, $request, $action, $id);
        }

        // Categories, Committees, Configurations, Files, Prerequisites, Rooms, Tools, ... and any controller that
        // did not override isAuthorized().
        return self::inGroup($user, 'Calendar Admins');
    }

    /**
     * @param array<string, mixed>|null $user User
     * @param string $action Action
     * @param int $id First passed argument (event id)
     * @return bool
     */
    protected function checkEvents(?array $user, string $action, int $id): bool
    {
        if (in_array($action, ['add', 'attending', 'submitted'])) {
            return !is_null($user);
        }

        if (in_array($action, ['attendance', 'assignments', 'cancel', 'edit'])) {
            return $this->fetchTable('Events')->isOwnedBy($id, $user['samaccountname'] ?? null)
                || self::inGroup($user, 'Calendar Admins');
        }

        // Calendar Admins only
        if ($action === 'pending' || $action === 'all') {
            return self::inGroup($user, 'Calendar Admins');
        }

        // Honorarium Admins only
        if (in_array($action, ['acceptedHonoraria', 'pendingHonoraria', 'rejectedHonoraria', 'upcomingHonoraria'])) {
            return self::inGroup($user, 'Honorarium Admins');
        }

        // Finance Reporting only
        if (in_array($action, ['exportHonoraria', 'exportHonorariaCsv'])) {
            return self::inGroup($user, 'Financial Reporting');
        }

        if (in_array($action, ['approve', 'reject', 'processRejection'])) {
            if ($this->fetchTable('Events')->hasHonorarium($id)) {
                return self::inGroup($user, 'Honorarium Admins');
            }

            return self::inGroup($user, 'Calendar Admins');
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $user User
     * @param \Cake\Http\ServerRequest $request Request
     * @param string $action Action
     * @param int $regId Registration id (first passed argument)
     * @return bool
     */
    protected function checkRegistrations(?array $user, ServerRequest $request, string $action, int $regId): bool
    {
        $registrations = $this->fetchTable('Registrations');

        if (in_array($action, ['accept', 'cancel', 'reject', 'view'])) {
            if (isset($user['samaccountname'])) {
                $registration = $registrations->get($regId, contain: ['Events']);

                if ($user['samaccountname'] == $registration->event->created_by) {
                    return true;
                }
            }
        }

        // NOTE: the Cake 3 isAuthorized() also did $this->set('isAdmin', <Calendar Admins>) here as a side effect for
        // the registration templates; RegistrationsController must set that view var itself now.

        return $registrations->isOwnedBy($regId, [
            'ad_username' => $user['samaccountname'] ?? null,
            'edit_key' => $request->getQuery('edit_key'),
        ]) || self::inGroup($user, 'Calendar Admins');
    }

    /**
     * True when the user is a member of the named AD group.
     *
     * @param array<string, mixed>|null $user User
     * @param string $group Group name
     * @return bool
     */
    public static function inGroup(?array $user, string $group): bool
    {
        return $user
            && array_key_exists('groups', $user)
            && is_array($user['groups'])
            && in_array($group, $user['groups']);
    }

    /**
     * Plain user array from an identity (Authorization identity wrapping the Authentication identity).
     *
     * @param object|null $identity Identity
     * @return array<string, mixed>|null
     */
    public static function userFromIdentity(?object $identity): ?array
    {
        if ($identity === null) {
            return null;
        }
        $data = method_exists($identity, 'getOriginalData') ? $identity->getOriginalData() : $identity;

        if ($data instanceof \ArrayObject) {
            return $data->getArrayCopy();
        }

        return is_array($data) ? $data : null;
    }
}
