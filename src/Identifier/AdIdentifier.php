<?php
declare(strict_types=1);

namespace App\Identifier;

use App\Auth\KitFactory;
use ArrayAccess;
use Authentication\Identifier\AbstractIdentifier;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Looks a user up in Active Directory and binds as that user to verify the password.
 *
 * Port of the Cake 3 `App\Auth\AdAuthenticate`; the LDAP work itself is the libkit `LdapDirectory`
 * (LdapRecord 3, StartTLS, group lookup through each group's `member` attribute). The identity is the array
 * AdAuthenticate returned (every LDAP attribute, single values flattened, plus `ssologin` = false and `groups`).
 *
 * The old `Auth.afterIdentify` listener in UsersController (create the `contacts` row on first login and attach
 * `contact_id`, `contact_error`, `blacklisted`) is applied here, after a successful bind, so it only runs for LDAP
 * logins (not for OIDC, as before).
 *
 * Difference: an empty password is rejected without touching the directory (the Cake 3 code let LdapRecord throw,
 * which was a 500); FormAuthenticator already refuses empty fields before this class is reached.
 */
class AdIdentifier extends AbstractIdentifier
{
    use LocatorAwareTrait;

    /**
     * @inheritDoc
     */
    public function identify(array $credentials): ArrayAccess|array|null
    {
        $user = KitFactory::directory()->authenticate((string)$credentials['username'], (string)$credentials['password']);
        if ($user === null) {
            return null;
        }

        return $this->afterIdentify($user->toAuthArray());
    }

    /**
     * Ensure a `contacts` row exists for the user and add its details to the identity.
     *
     * @param array<string, mixed> $user Identity built from AD.
     * @return array<string, mixed>
     */
    protected function afterIdentify(array $user): array
    {
        $contactsTable = $this->fetchTable('Contacts');
        $contact = $contactsTable->find('all')
            ->where(['ad_username' => $user['samaccountname']])
            ->first();

        $user['contact_error'] = true;

        if (!$contact) {
            $fields = ['displayname', 'samaccountname', 'mail', 'telephonenumber'];

            foreach ($fields as $field) {
                if (!isset($user[$field]) || empty($user[$field])) {
                    return $user;
                }
            }

            $data = [
                'name' => $user['displayname'],
                'ad_username' => $user['samaccountname'],
                'email' => $user['mail'],
                'phone' => $user['telephonenumber'],
                'w9_on_file' => false,
            ];

            $contact = $contactsTable->newEntity($data);
            $contactsTable->save($contact);
        }

        $user['contact_id'] = $contact->id;

        if (!empty($user['contact_id'])) {
            $user['contact_error'] = false;
        }
        $user['blacklisted'] = $contact->blacklisted;

        return $user;
    }
}
