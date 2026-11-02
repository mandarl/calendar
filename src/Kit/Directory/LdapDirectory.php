<?php

declare(strict_types=1);

namespace Dms\Kit\Directory;

use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\LdapRecordException;
use LdapRecord\Models\ActiveDirectory\Group;
use LdapRecord\Models\ActiveDirectory\User;
use LdapRecord\Models\ModelNotFoundException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Directory access used by the calendar. Replaces three old copies of the same connection code:
 *
 *  - src/Auth/AdAuthenticate.php  L24-L45 (ctor) and L90-L136 (findAdUser): user lookup + bind-as-user + groups
 *  - src/Controller/AppController.php L278-L308 (_refreshAdGroups): groups by sAMAccountName via the service account
 *  - src/Controller/EventsController.php L194-L225 (beforeSave): add a user to a group named by `cn` (query L215, attach L223)
 *
 * LdapRecord 2.16 -> 3.8.6 changes that matter here: none for the calls above; `Container::addConnection()`,
 * `User::findByOrFail()`, `$connection->auth()->attempt()`, `$user->groups()->get()`, `$connection->query()->where()->first()`,
 * `Group::find()` and `$group->members()->attach()` all behave the same. tightenco/collect is replaced by
 * illuminate/collections (removes the PHP 8.4 deprecation noise 2.x emits).
 *
 * The connection is registered in LdapRecord's global Container under a unique name so it cannot clash with another
 * LdapRecord consumer in the same process (the old code registered everything as the DEFAULT connection).
 */
final class LdapDirectory
{
    private readonly Connection $connection;
    private readonly string $connectionName;

    public function __construct(
        private readonly LdapConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->connection = new Connection($config->toConnectionArray());
        $this->connectionName = 'dms-kit-' . spl_object_id($this);
        Container::getInstance()->addConnection($this->connection, $this->connectionName);
    }

    /** Bind as the service account now (AppController does `$connection->connect()` eagerly; queries also connect lazily). */
    public function connect(): void
    {
        try {
            $this->connection->connect();
        } catch (LdapRecordException $e) {
            throw new DirectoryException('Failed to bind to LDAP server. Check Auth configuration settings.', 0, $e);
        }
    }

    /**
     * AdAuthenticate::findAdUser(): look the user up by the username field (trimmed), bind as that user's DN with the
     * submitted password, and on success return the attributes plus the group cns.
     *
     * Returns null for: unknown user, wrong password. DELIBERATE DIFFERENCES from the old code (see README.md, Known incompatibilities):
     *  - an empty username/password returns null; the old code let LdapRecord's PasswordRequiredException escape (HTTP 500);
     *  - directory/TLS/service-bind errors raise DirectoryException (what the old code intended to do).
     */
    public function authenticate(string $username, string $password): ?DirectoryUser
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return null;
        }

        try {
            $user = $this->users()->findByOrFail($this->config->usernameField, $username);
        } catch (ModelNotFoundException) {
            return null;
        } catch (LdapRecordException $e) {
            throw new DirectoryException('Failed to bind to LDAP server. Check Auth configuration settings.', 0, $e);
        }

        try {
            if (!$this->connection->auth()->attempt($user->getDn(), $password)) {
                return null;
            }

            return $this->toDirectoryUser($user);
        } catch (LdapRecordException $e) {
            throw new DirectoryException('Failed to bind to LDAP server. Check Auth configuration settings.', 0, $e);
        }
    }

    /** Lookup without a password check. */
    public function findUser(string $username): ?DirectoryUser
    {
        try {
            return $this->toDirectoryUser($this->users()->findByOrFail($this->config->usernameField, trim($username)));
        } catch (ModelNotFoundException) {
            return null;
        } catch (LdapRecordException $e) {
            throw new DirectoryException('LDAP lookup failed', 0, $e);
        }
    }

    /**
     * AppController::_refreshAdGroups(): current group cns of a user, via the service account.
     *
     * @return list<string>|null null when the lookup failed (the old code logged a warning and returned null so callers
     *                           could fall back to the cached groups); an unknown user also yields null, like the old code.
     */
    public function groupsFor(string $username): ?array
    {
        try {
            $user = $this->users()->findByOrFail($this->config->usernameField, $username);

            return $this->groupCns($user);
        } catch (\Exception $e) {
            $this->logger->warning('AD group refresh failed for ' . $username . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * EventsController.php ~L205-L225: add a user to the group whose `cn` is $groupCn (the event's prerequisite ad_group).
     *
     * @throws GroupNotFound  when no group with that cn exists
     * @throws ModelNotFoundException when the user does not exist
     * @throws DirectoryException on directory errors
     */
    public function addUserToGroup(string $groupCn, string $username): void
    {
        try {
            $record = $this->connection->query()->where([
                ['cn', '=', $groupCn],
                ['objectclass', '=', 'group'],
            ])->first();
            if (!is_array($record) || !isset($record['dn'])) {
                throw new GroupNotFound("No directory group with cn '$groupCn'");
            }

            $group = Group::on($this->connectionName)->find($record['dn']);
            if (!$group instanceof Group) {
                throw new GroupNotFound("Group {$record['dn']} could not be loaded");
            }
            $user = $this->users()->findByOrFail($this->config->usernameField, $username);

            $group->members()->attach($user);
        } catch (GroupNotFound | ModelNotFoundException $e) {
            throw $e;
        } catch (LdapRecordException $e) {
            throw new DirectoryException('LDAP group update failed', 0, $e);
        }
    }

    /** Test/cleanup helper: the inverse of addUserToGroup(). */
    public function removeUserFromGroup(string $groupCn, string $username): void
    {
        $record = $this->connection->query()->where([['cn', '=', $groupCn], ['objectclass', '=', 'group']])->first();
        if (!is_array($record) || !isset($record['dn'])) {
            throw new GroupNotFound("No directory group with cn '$groupCn'");
        }
        $group = Group::on($this->connectionName)->find($record['dn']);
        $user = $this->users()->findByOrFail($this->config->usernameField, $username);
        $group->members()->detach($user);
    }

    private function users(): \LdapRecord\Query\Model\ActiveDirectoryBuilder
    {
        return User::on($this->connectionName);
    }

    /** @return list<string> */
    private function groupCns(User $user): array
    {
        $cns = [];
        foreach ($user->groups()->get() as $group) {
            $cns[] = (string)$group->cn[0];
        }

        return $cns;
    }

    private function toDirectoryUser(User $user): DirectoryUser
    {
        return new DirectoryUser($user->getDn(), $user->getAttributes(), $this->groupCns($user));
    }
}
