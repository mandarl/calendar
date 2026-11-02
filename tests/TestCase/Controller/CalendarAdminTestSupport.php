<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Shared base of the controller tests of slices A (admin CRUD), H (honoraria) and L (feeds).
 *
 * These are integration tests against a real MariaDB/MySQL database that holds the application schema (the harness'
 * frozen schema: calendar-baselines/<id>/00-schema-lookups.sql). The tests never use a database whose name does not
 * end in `_test`, they skip when it is not reachable, and each test runs inside a transaction that is rolled back.
 *
 *   docker network create calc5ahl-testnet
 *   docker run -d --name calc5ahl-testdb --network calc5ahl-testnet --tmpfs /var/lib/mysql \
 *     -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=calendar_test -e MARIADB_USER=cal -e MARIADB_PASSWORD=cal \
 *     mariadb:10.6 --character-set-server=utf8mb3 --collation-server=utf8mb3_general_ci
 *   docker exec -i calc5ahl-testdb mariadb -ucal -pcal calendar_test < 00-schema-lookups.sql
 *   docker run --rm --network calc5ahl-testnet -e DB_HOST=calc5ahl-testdb -e DB_USERNAME=cal -e DB_PASSWORD=cal \
 *     -e DB_DATABASE=calendar_test -v "$PWD":/app -v calc5ahl-vendor:/app/vendor -w /app calendar-cake5:base \
 *     vendor/bin/phpunit -c phpunit.xml.dist
 *
 * (The migrations in config/Migrations extend the legacy Phinx class and do not run on migrations 5, so the schema is
 * loaded from the frozen dump. Fixtures of slice B are not used: they are Cake 3 style.)
 */
abstract class CalendarAdminTestSupport extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = ConnectionManager::get('default');
        $database = (string)($connection->config()['database'] ?? '');
        if (!str_ends_with($database, '_test')) {
            $this->markTestSkipped('Integration tests need DB_DATABASE to end in "_test" (got "' . $database . '").');
        }
        try {
            $connection->execute('SELECT 1 FROM calendar_super_configurations LIMIT 1')->closeCursor();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Test database with the application schema not reachable: ' . $e->getMessage());
        }
        $connection->begin();

        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }

    protected function tearDown(): void
    {
        $connection = ConnectionManager::get('default');
        if ($connection->inTransaction()) {
            $connection->rollback(true);
        }
        TableRegistry::getTableLocator()->clear();

        parent::tearDown();
    }

    /**
     * Log in as a user of the given AD groups (the identity the Authentication session authenticator restores).
     *
     * @param string ...$groups AD group names
     * @return void
     */
    protected function loginAs(string ...$groups): void
    {
        $this->session(['Auth' => [
            'samaccountname' => 'test-user',
            'name' => 'Test User',
            'mail' => 'test-user@example.test',
            'groups' => $groups,
        ]]);
    }

    /**
     * Roles used by the permission matrices: label => AD groups (null = anonymous).
     *
     * @return array<string, array<int, string>|null>
     */
    protected static function roles(): array
    {
        return [
            'anonymous' => null,
            'member' => [],
            'calendarAdmin' => ['Calendar Admins'],
            'honorariumAdmin' => ['Honorarium Admins'],
            'financialReporting' => ['Financial Reporting'],
            'superAdmin' => ['Calendar Super Admins'],
        ];
    }

    /**
     * @param string $role Role label from roles()
     * @return void
     */
    protected function loginAsRole(string $role): void
    {
        $groups = self::roles()[$role];
        if ($groups !== null) {
            $this->loginAs(...$groups);
        }
    }

    /**
     * Insert a row and return its id (the tables are the application's, so timestamps and rules apply).
     *
     * @param string $table Table alias
     * @param array<string, mixed> $data Field values
     * @return \Cake\Datasource\EntityInterface
     */
    protected function make(string $table, array $data)
    {
        $repository = $this->getTableLocator()->get($table);
        $entity = $repository->newEntity($data, ['validate' => false, 'accessibleFields' => ['*' => true]]);
        $this->assertNotFalse($repository->save($entity, ['checkRules' => false]), "could not insert into $table");

        return $entity;
    }

    /**
     * Number of rows of a table that match the conditions.
     *
     * @param string $table Table alias
     * @param array<string, mixed> $conditions Conditions
     * @return int
     */
    protected function countRows(string $table, array $conditions = []): int
    {
        return $this->getTableLocator()->get($table)->find()->where($conditions)->count();
    }

    /**
     * Where a denied request ends: Cake 3 flashed "You are not authorized" and redirected (anonymous visitors to the
     * login page, logged-in users to the home page).
     *
     * @param string|null $role Role label
     * @return void
     */
    protected function assertDenied(?string $role): void
    {
        $this->assertResponseCode(302, 'role ' . $role);
        $location = $this->_response->getHeaderLine('Location');
        if ($role === 'anonymous') {
            $this->assertStringContainsString('/users/login', $location, 'role ' . $role);
        } else {
            $this->assertSame('/', $location, 'role ' . $role);
        }
    }
}
