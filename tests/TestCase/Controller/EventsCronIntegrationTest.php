<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeZone;
use Throwable;

/**
 * Slice X: EventsController::cron() branches, driven through a real GET.
 *
 * Rows are built relative to the test run's own clock (no faked anchor):
 * ended/old/soon dates are computed from DateTime::now() in setUp, so the
 * test is meaningful wherever it runs. Setup rows use free registrations
 * (no transaction_id), so the completion branch never touches a payment
 * gateway; mail sends go through EmailComponent's catch (Throwable) as in
 * production, so these tests assert status/flags only — mail content is
 * proven by W6 and the differential capture. Webhook targets default to
 * empty in this environment (no-targets noop).
 *
 * Setup rows are written in the add/edit forms' own language, because the
 * time behaviours rewrite them on marshal (slice B): event_start/event_end
 * as `m/d/Y g:i A` America/Chicago strings (FriendlyTime converts them to
 * UTC), booking_start/booking_end as minute offsets and
 * attendee_cancellation as a day offset from the event start
 * (RelationalTime converts them to absolute UTC timestamps). Passing
 * absolute datetimes for those fields would NOT survive marshal.
 *
 * Runs against a real MariaDB with the frozen harness schema (lookups
 * only); each test runs inside a transaction that is rolled back. Never
 * run against a non-`_test` database (the guard below skips otherwise).
 *
 *   docker run -d --name calc5x-testdb --network calc5x_backend --tmpfs /var/lib/mysql \
 *     -e MARIADB_ROOT_PASSWORD=<test-only password> \
 *     mariadb:10.6 --character-set-server=utf8 --collation-server=utf8_general_ci
 *   docker exec calc5x-testdb mariadb -uroot -p<test-only password> -e "CREATE DATABASE calendar_test"
 *   docker exec -i calc5x-testdb mariadb -uroot -p<test-only password> calendar_test < 00-schema-lookups.sql
 *   docker run --rm --network calc5x_backend -e DB_HOST=calc5x-testdb -e DB_USERNAME=root \
 *     -e DB_PASSWORD=<test-only password> -e DB_DATABASE=calendar_test \
 *     -v "$PWD":/app -v calc5x-vendor:/app/vendor -w /app calendar-cake5:base \
 *     vendor/bin/phpunit -c phpunit.xml.dist --filter EventsCronIntegrationTest
 */
final class EventsCronIntegrationTest extends TestCase
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
            $row = $connection->execute('SELECT COUNT(*) AS c FROM configurations')->fetch('assoc');
            if ((int)($row['c'] ?? 0) < 2) {
                $this->markTestSkipped('Test database lacks the Configurations seed rows.');
            }
            $row = $connection->execute('SELECT COUNT(*) AS c FROM rooms WHERE id = 3')->fetch('assoc');
            if ((int)($row['c'] ?? 0) < 1) {
                $this->markTestSkipped('Test database lacks room id 3.');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Test database with the application schema not reachable: ' . $e->getMessage());
        }
        $connection->begin();
        $this->seedRows();
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

    private function table(string $alias): object
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    /**
     * The form format FriendlyTimeBehavior parses (Chicago wall clock).
     * Minute precision matches the behaviour's own round-trip.
     */
    private function formDate(DateTime $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('America/Chicago'))->format('m/d/Y g:i A');
    }

    private function eventData(int $id, array $overrides): array
    {
        $now = DateTime::now();

        return array_merge([
            'id' => $id,
            'name' => 'Cron Test Event ' . $id,
            'short_description' => 'Short.',
            'long_description' => '<p>Long.</p>',
            'advisories' => null,
            // Form language: start/end as Chicago strings; booking offsets
            // in minutes, cancellation offset in days (see class docblock).
            'event_start' => $this->formDate($now->modify('+30 days')),
            'event_end' => $this->formDate($now->modify('+30 days')->modify('+2 hours')),
            'booking_start' => 30,
            'booking_end' => 30,
            'cost' => 0,
            'eventbrite_link' => null,
            'free_spaces' => 6,
            'paid_spaces' => 0,
            'members_only' => 0,
            'age_restriction' => 0,
            'attendees_require_approval' => 0,
            'attendee_cancellation' => 1,
            'extend_registration' => 0,
            'class_number' => 0,
            'sponsored' => 0,
            'status' => 'approved',
            'room_id' => 3,
            'contact_id' => 2001,
            'fulfills_prerequisite_id' => null,
            'requires_prerequisite_id' => null,
            'part_of_id' => null,
            'copy_of_id' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'created_by' => 'user1',
            'cancel_notification' => 0,
            'reminder_notification' => 0,
            'notifyInstructorRegistrations' => 0,
            'notifyInstructorCancellations' => 0,
        ], $overrides);
    }

    private function regData(int $id, int $eventId, string $status): array
    {
        return [
            'id' => $id,
            'event_id' => $eventId,
            'type' => 'free',
            'name' => 'Cron Test Attendee ' . $id,
            'email' => 'cron-test-' . $id . '@example.test',
            'phone' => '555-0299',
            'ad_username' => null,
            'send_text' => 0,
            'edit_key' => 'editkeycron' . $id,
            'status' => $status,
            'attended' => 0,
            'ad_assigned' => 0,
            'transaction_id' => null,
        ];
    }

    private function seedRows(): void
    {
        $now = DateTime::now();
        $events = $this->table('Events');
        $regs = $this->table('Registrations');
        $honoraria = $this->table('Honoraria');

        // Ended approved event with one pending (free) registration.
        $this->save($events, $this->eventData(1191, [
            'event_start' => $this->formDate($now->modify('-2 days')),
            'event_end' => $this->formDate($now->modify('-1 day')),
        ]));
        $this->save($regs, $this->regData(3191, 1191, 'pending'));

        // Pending, created 5 days ago, no honorarium -> plain auto-approve (2 days).
        // created is forced past via updateQuery: TimestampBehavior would
        // otherwise stamp it with now on save.
        $this->save($events, $this->eventData(1192, ['status' => 'pending']));
        $events->updateQuery()
            ->set(['created' => $now->modify('-5 days'), 'modified' => $now->modify('-5 days')])
            ->where(['id' => 1192])
            ->execute();

        // Pending, created 4 days ago, WITH honorarium -> honorarium auto-approve (3 days).
        $this->save($events, $this->eventData(1193, ['status' => 'pending']));
        $events->updateQuery()
            ->set(['created' => $now->modify('-4 days'), 'modified' => $now->modify('-4 days')])
            ->where(['id' => 1193])
            ->execute();
        $this->save($honoraria, [
            'id' => 5199,
            'event_id' => 1193,
            'pay_contact' => 2004,
            'committee_id' => 1,
            'paid' => null,
        ]);

        // Approved, starts in 6 hours -> start reminder (confirmed reg only).
        // cancel_notification is preset: with a day-granularity cancellation
        // offset, any start inside 24h would otherwise also trip the cancel
        // branch, so the cancel side is already-sent here by construction.
        $this->save($events, $this->eventData(1194, [
            'event_start' => $this->formDate($now->modify('+6 hours')),
            'event_end' => $this->formDate($now->modify('+8 hours')),
            'cancel_notification' => 1,
        ]));
        $this->save($regs, $this->regData(3194, 1194, 'confirmed'));

        // Approved, starts in 30 days, cancellation cutoff = start - 29 days
        // = now + 1 day (< tomorrow, which is now + 24h at cron time, since
        // setup ran strictly before the cron call) -> cancel reminder only.
        $this->save($events, $this->eventData(1195, [
            'attendee_cancellation' => 29,
        ]));
        $this->save($regs, $this->regData(3195, 1195, 'confirmed'));
        $this->save($regs, $this->regData(3196, 1195, 'pending'));

        // Part event, ended, approved, with a pending reg: completion and
        // cancel branches must skip it; the start branch still flips its
        // reminder flag.
        $this->save($events, $this->eventData(1196, [
            'event_start' => $this->formDate($now->modify('-2 days')),
            'event_end' => $this->formDate($now->modify('-1 day')),
            'part_of_id' => 1191,
        ]));
        $this->save($regs, $this->regData(3197, 1196, 'pending'));
    }

    private function save(object $table, array $data): void
    {
        $entity = $table->newEntity($data, ['validate' => false]);
        // id is guarded on every entity (accessible '*' except id), so set
        // it explicitly with the guard off; the test needs fixed ids.
        if (isset($data['id'])) {
            $entity->set('id', $data['id'], ['guard' => false]);
        }
        $ok = $table->save($entity, ['validate' => false, 'checkRules' => false]);
        $this->assertNotFalse($ok, 'Setup row did not save: ' . json_encode($data));
    }

    public function testCronCompletesEndedEventAndCancelsPendingRegs(): void
    {
        $this->get('/events/cron');
        $this->assertResponseOk();

        $events = $this->table('Events');
        $regs = $this->table('Registrations');
        $this->assertSame('completed', $events->get(1191)->status);
        $this->assertSame('cancelled', $regs->get(3191)->status);
        // Completion does not touch the reminder flags of the completed event.
        $this->assertSame(0, (int)$events->get(1191)->cancel_notification);
        $this->assertSame(0, (int)$events->get(1191)->reminder_notification);
    }

    public function testCronAutoApprovesPlainAndHonorariumEvents(): void
    {
        $this->get('/events/cron');
        $this->assertResponseOk();

        $events = $this->table('Events');
        $this->assertSame('approved', $events->get(1192)->status);
        $this->assertSame('approved', $events->get(1193)->status);
        $this->assertSame(0, (int)$events->get(1192)->cancel_notification);
        $this->assertSame(0, (int)$events->get(1192)->reminder_notification);
        $this->assertSame(0, (int)$events->get(1193)->cancel_notification);
        $this->assertSame(0, (int)$events->get(1193)->reminder_notification);
    }

    public function testCronSendsStartReminderOnlyToConfirmed(): void
    {
        $this->get('/events/cron');
        $this->assertResponseOk();

        $events = $this->table('Events');
        $this->assertSame(1, (int)$events->get(1194)->reminder_notification);
        $this->assertSame(1, (int)$events->get(1194)->cancel_notification);
        // Reminder mails do not change registration status.
        $this->assertSame('confirmed', $this->table('Registrations')->get(3194)->status);
    }

    public function testCronSendsCancelReminderAndSetsFlag(): void
    {
        $this->get('/events/cron');
        $this->assertResponseOk();

        $events = $this->table('Events');
        $this->assertSame(1, (int)$events->get(1195)->cancel_notification);
        $this->assertSame(0, (int)$events->get(1195)->reminder_notification);
        $this->assertSame('confirmed', $this->table('Registrations')->get(3195)->status);
        $this->assertSame('pending', $this->table('Registrations')->get(3196)->status);
    }

    public function testCronSkipsPartEventsExceptStartReminderFlag(): void
    {
        $this->get('/events/cron');
        $this->assertResponseOk();

        $events = $this->table('Events');
        $part = $events->get(1196);
        $this->assertSame('approved', $part->status);
        $this->assertSame(0, (int)$part->cancel_notification);
        $this->assertSame(1, (int)$part->reminder_notification);
        $this->assertSame('pending', $this->table('Registrations')->get(3197)->status);
    }

    public function testSecondCronIsANoop(): void
    {
        $this->get('/events/cron');
        $this->assertResponseOk();
        $this->get('/events/cron');
        $this->assertResponseOk();

        $events = $this->table('Events');
        $this->assertSame('completed', $events->get(1191)->status);
        $this->assertSame('approved', $events->get(1192)->status);
        $this->assertSame('approved', $events->get(1193)->status);
    }
}
