<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * The audit-log index (Calendar Super Admins only), including the Cake 3
 * filter quirk that is preserved: a date range starts at 23:59:59 of the
 * start day, so same-day entries before that time fall outside the range.
 */
class LogsControllerTest extends CalendarAdminTestSupport
{
    protected function seedLog(array $over = [])
    {
        // $over first: PHP's `+` keeps the left operand's keys (see EventsSeedTrait).
        return $this->make('Logs', $over + [
            'description' => 'Seeded log entry',
            'user' => 'test-user',
            'ip_address' => '127.0.0.1',
            'date_time' => '2026-01-06 06:00:00',
            'url' => '/events/view/1001',
            'controller' => 'Events',
            'action' => 'view',
        ]);
    }

    public function testIndexOnlyForSuperAdmins(): void
    {
        $this->seedLog(['description' => 'Listed log entry']);
        foreach (self::roles() as $role => $groups) {
            $this->cleanup();
            $this->loginAsRole($role);
            $this->get('/logs');
            if ($role === 'superAdmin') {
                $this->assertResponseOk();
                $this->assertResponseContains('Listed log entry');
            } else {
                $this->assertDenied($role);
            }
        }
    }

    public function testUserNameFilter(): void
    {
        $this->seedLog(['user' => 'alice', 'description' => 'Alice log entry']);
        $this->seedLog(['user' => 'bob', 'description' => 'Bob log entry']);
        $this->loginAs('Calendar Super Admins');
        $this->get('/logs?user_name=alice');
        $this->assertResponseOk();
        $this->assertResponseContains('Alice log entry');
        $this->assertResponseNotContains('Bob log entry');
    }

    public function testSearchStringFilter(): void
    {
        $this->seedLog(['description' => 'Log about needlething']);
        $this->seedLog(['description' => 'Log about something else']);
        $this->loginAs('Calendar Super Admins');
        $this->get('/logs?search_string=needlething');
        $this->assertResponseOk();
        $this->assertResponseContains('Log about needlething');
        $this->assertResponseNotContains('Log about something else');
    }

    public function testDateRangeStartsAt235959OfStartDay(): void
    {
        // 2026-01-06 06:00 UTC is 2026-01-06 00:00 Chicago: inside the range.
        $this->seedLog(['description' => 'Range log in', 'date_time' => '2026-01-06 06:00:00']);
        // 2026-01-06 05:00 UTC is 2026-01-05 23:00 Chicago: before the range's
        // start bound (2026-01-05 23:59:59 Chicago), so it falls outside even
        // though its Chicago date is the start date.
        $this->seedLog(['description' => 'Range log out', 'date_time' => '2026-01-06 05:00:00']);
        $this->loginAs('Calendar Super Admins');
        $this->get('/logs?start_date=2026-01-05&end_date=2026-01-06');
        $this->assertResponseOk();
        $this->assertResponseContains('Range log in');
        $this->assertResponseNotContains('Range log out');
    }

    public function cleanup(): void
    {
        parent::cleanup();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }
}
