<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Slice R: RegistrationsController accept/reject/cancel logic.
 *
 * These actions are unreachable through the browser UI in both builds (no
 * template renders an accept/reject form, and Cake 3's URL-bound Security token
 * / Cake 5's FormProtection both reject a token rendered for another action
 * with a 400 — see the differential driver output), so the listener logic
 * itself is proven here with IntegrationTestTrait's valid per-URL tokens.
 * Runs against a real MariaDB with the frozen harness schema; each test runs
 * inside a transaction that is rolled back. Mail sends go through
 * EmailComponent's catch (Throwable) as in production, so these tests assert
 * status/redirect only; mail content is proven by the W3/W4/W5 write specs
 * and the curl differential driver.
 *
 *   docker network create calc5r-testnet
 *   docker run -d --name calc5r-testdb --network calc5r-testnet --tmpfs /var/lib/mysql \
 *     -e MARIADB_ROOT_PASSWORD=root-synthetic -e MARIADB_DATABASE=calendar_test \
 *     -e MARIADB_USER=cal -e MARIADB_PASSWORD=cal \
 *     mariadb:10.6 --character-set-server=utf8mb3 --collation-server=utf8mb3_general_ci
 *   docker exec -i calc5r-testdb mariadb -ucal -pcal calendar_test < 00-schema-lookups.sql
 *   docker run --rm --network calc5r-testnet -e DB_HOST=calc5r-testdb -e DB_USERNAME=cal \
 *     -e DB_PASSWORD=cal -e DB_DATABASE=calendar_test \
 *     -v "$PWD":/app -v calc5r-vendor:/app/vendor -w /app calendar-cake5:base \
 *     vendor/bin/phpunit -c phpunit.xml.dist --filter RegistrationsControllerTest
 */
class RegistrationsControllerTest extends TestCase
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

    private function loginAsCreator(): void
    {
        $this->session(['Auth' => [
            'samaccountname' => 'test-creator',
            'displayname' => 'Test Creator',
            'mail' => 'creator@example.test',
            'groups' => [],
        ]]);
    }

    /** @return array{eventId: int, regId: int} */
    private function seedPendingReg(string $cancellation, int $extend = 0, array $regOver = []): array
    {
        $db = ConnectionManager::get('default');
        $db->execute(
            'INSERT INTO contacts (name, ad_username, email, phone, w9_on_file, created, modified) ' .
            'VALUES (:name, :ad_username, :email, :phone, 0, NOW(), NOW())',
            ['name' => 'Test Contact', 'ad_username' => 'test-contact', 'email' => 'contact@example.test', 'phone' => '555-0100']
        );
        $contactId = (int)$db->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];
        $db->execute(
            'INSERT INTO events (name, short_description, long_description, event_start, event_end, ' .
            'booking_start, booking_end, cost, free_spaces, paid_spaces, attendees_require_approval, ' .
            'attendee_cancellation, extend_registration, status, room_id, contact_id, created_by, created, modified) ' .
            'VALUES (:name, :short, :long, :start, :end, :start, :end, 0, 10, 0, 1, :cancel, :extend, ' .
            '\'approved\', NULL, :contact, :creator, NOW(), NOW())',
            [
                'name' => 'Seeded Event', 'short' => 'Seeded', 'long' => 'Seeded long',
                'start' => '2031-06-01 18:00:00', 'end' => '2031-06-01 20:00:00',
                'cancel' => $cancellation, 'extend' => $extend,
                'contact' => $contactId, 'creator' => 'test-creator',
            ]
        );
        $eventId = (int)$db->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];
        $reg = $regOver + [
            'type' => 'free', 'name' => 'Test Attendee', 'email' => 'attendee@example.test',
            'phone' => '555-0101', 'ad_username' => null, 'edit_key' => 'test-edit-key',
            'status' => 'pending',
        ];
        $db->execute(
            'INSERT INTO registrations (event_id, type, name, email, phone, ad_username, edit_key, status, attended, created, modified) ' .
            'VALUES (:event, :type, :name, :email, :phone, :ad, :key, :status, 0, NOW(), NOW())',
            [
                'event' => $eventId, 'type' => $reg['type'], 'name' => $reg['name'],
                'email' => $reg['email'], 'phone' => $reg['phone'], 'ad' => $reg['ad_username'],
                'key' => $reg['edit_key'], 'status' => $reg['status'],
            ]
        );
        $regId = (int)$db->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];

        return ['eventId' => $eventId, 'regId' => $regId];
    }

    private function regStatus(int $id): string
    {
        $row = ConnectionManager::get('default')
            ->execute('SELECT status FROM registrations WHERE id = :id', ['id' => $id])
            ->fetch('assoc');

        return (string)$row['status'];
    }

    public function testAcceptPendingBeforeCutoffAsCreator(): void
    {
        $ids = $this->seedPendingReg('2031-06-01 12:00:00', 60);
        $this->loginAsCreator();
        $this->post('/registrations/accept/' . $ids['regId']);
        $this->assertResponseCode(302);
        $this->assertSame('confirmed', $this->regStatus($ids['regId']));
    }

    public function testAcceptPendingAfterCutoffStaysPending(): void
    {
        $ids = $this->seedPendingReg('2020-01-01 12:00:00', 0);
        $this->loginAsCreator();
        $this->post('/registrations/accept/' . $ids['regId']);
        $this->assertResponseCode(302);
        $this->assertSame('pending', $this->regStatus($ids['regId']));
    }

    public function testRejectPendingBeforeCutoffAsCreator(): void
    {
        $ids = $this->seedPendingReg('2031-06-01 12:00:00', 0);
        $this->loginAsCreator();
        $this->post('/registrations/reject/' . $ids['regId']);
        $this->assertResponseCode(302);
        $this->assertSame('rejected', $this->regStatus($ids['regId']));
    }

    public function testRejectPendingAfterCutoffStaysPending(): void
    {
        $ids = $this->seedPendingReg('2020-01-01 12:00:00', 0);
        $this->loginAsCreator();
        $this->post('/registrations/reject/' . $ids['regId']);
        $this->assertResponseCode(302);
        $this->assertSame('pending', $this->regStatus($ids['regId']));
    }

    public function testAcceptGetIsMethodNotAllowed(): void
    {
        $ids = $this->seedPendingReg('2031-06-01 12:00:00', 0);
        $this->loginAsCreator();
        $this->get('/registrations/accept/' . $ids['regId']);
        $this->assertResponseCode(405);
        $this->assertSame('pending', $this->regStatus($ids['regId']));
    }

    public function testCancelByEditKeyAnonymous(): void
    {
        $ids = $this->seedPendingReg('2031-06-01 12:00:00', 0, ['status' => 'confirmed']);
        $this->post('/registrations/cancel/' . $ids['regId'] . '?edit_key=test-edit-key');
        $this->assertResponseCode(302);
        $this->assertSame('cancelled', $this->regStatus($ids['regId']));
    }

    public function testCancelAfterCutoffNonAdminStaysConfirmed(): void
    {
        $ids = $this->seedPendingReg('2020-01-01 12:00:00', 0, ['status' => 'confirmed']);
        $this->loginAsCreator();
        $this->post('/registrations/cancel/' . $ids['regId']);
        $this->assertResponseCode(302);
        $this->assertSame('confirmed', $this->regStatus($ids['regId']));
    }

    public function testCancelAfterCutoffCalendarAdminOverrides(): void
    {
        $ids = $this->seedPendingReg('2020-01-01 12:00:00', 0, ['status' => 'confirmed']);
        $this->session(['Auth' => [
            'samaccountname' => 'test-admin',
            'displayname' => 'Test Admin',
            'mail' => 'admin@example.test',
            'groups' => ['Calendar Admins'],
        ]]);
        $this->post('/registrations/cancel/' . $ids['regId']);
        $this->assertResponseCode(302);
        $this->assertSame('cancelled', $this->regStatus($ids['regId']));
    }

    public function testViewSetsIsAdminForCalendarAdmin(): void
    {
        $ids = $this->seedPendingReg('2031-06-01 12:00:00', 0, ['status' => 'confirmed']);
        $this->session(['Auth' => [
            'samaccountname' => 'test-admin',
            'displayname' => 'Test Admin',
            'mail' => 'admin@example.test',
            'groups' => ['Calendar Admins'],
        ]]);
        $this->get('/registrations/view/' . $ids['regId']);
        $this->assertResponseOk();
        $this->assertSame(true, $this->viewVariable('isAdmin'));
    }

    public function testAcceptAnonymousRedirectsToLogin(): void
    {
        $ids = $this->seedPendingReg('2031-06-01 12:00:00', 0);
        $this->post('/registrations/accept/' . $ids['regId']);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('login');
        $this->assertSame('pending', $this->regStatus($ids['regId']));
    }
}
