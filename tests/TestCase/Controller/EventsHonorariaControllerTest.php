<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * Events honoraria lists (Honorarium Admins only), the finance view and the
 * CSV export (Financial Reporting only).
 */
class EventsHonorariaControllerTest extends CalendarAdminTestSupport
{
    use EventsSeedTrait;

    private function seedHonorariumEvent(string $name, string $status, array $over = [])
    {
        $event = $this->seedEvent(['name' => $name, 'status' => $status] + $over);
        $committee = $this->seedCommittee();
        $this->seedHonorarium($event, $committee);

        return $event;
    }

    public function testPendingHonorariaListsOnlyPendingWithHonorarium(): void
    {
        $pending = $this->seedHonorariumEvent('Pending Honorarium Event', 'pending');
        $this->seedHonorariumEvent('Approved Honorarium Event', 'approved');
        $noHonorarium = $this->seedEvent(['name' => 'Pending Without Honorarium', 'status' => 'pending']);
        $this->loginAs('Honorarium Admins');
        $this->get('/events/honoraria/pending');
        $this->assertResponseOk();
        $this->assertResponseContains('Pending Honorarium Event');
        $this->assertResponseNotContains('Approved Honorarium Event');
        $this->assertResponseNotContains('Pending Without Honorarium');
    }

    public function testAcceptedHonorariaListsApprovedAndCompleted(): void
    {
        $this->seedHonorariumEvent('Accepted Approved Event', 'approved');
        $this->seedHonorariumEvent('Accepted Completed Event', 'completed');
        $this->seedHonorariumEvent('Accepted Pending Event', 'pending');
        $this->loginAs('Honorarium Admins');
        $this->get('/events/honoraria/accepted');
        $this->assertResponseOk();
        $this->assertResponseContains('Accepted Approved Event');
        $this->assertResponseContains('Accepted Completed Event');
        $this->assertResponseNotContains('Accepted Pending Event');
    }

    public function testRejectedHonorariaListsOnlyRejected(): void
    {
        // A real rejected event always carries who rejected it and why (set by moderation);
        // the template links rejected_by, so seed those fields rather than leaving them null.
        $this->seedHonorariumEvent('Rejected Honorarium Event', 'rejected', [
            'rejected_by' => 'test-user', 'rejection_reason' => 'Test rejection reason',
        ]);
        $this->seedHonorariumEvent('Not Rejected Event', 'approved');
        $this->loginAs('Honorarium Admins');
        $this->get('/events/honoraria/rejected');
        $this->assertResponseOk();
        $this->assertResponseContains('Rejected Honorarium Event');
        $this->assertResponseNotContains('Not Rejected Event');
    }

    public function testHonorariaListsDeniedForOtherRoles(): void
    {
        $this->seedHonorariumEvent('Role List Event', 'pending');
        foreach (['pending', 'accepted', 'rejected', 'upcoming'] as $list) {
            foreach (self::roles() as $role => $groups) {
                if ($role === 'honorariumAdmin') {
                    continue;
                }
                $this->cleanup();
                $this->loginAsRole($role);
                $this->get('/events/honoraria/' . $list);
                $this->assertDenied($role);
            }
        }
    }

    public function testUpcomingHonorariaCountsThisMonth(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $now->format('Y-m-10 16:00:00');
        $end = $now->format('Y-m-10 18:00:00');
        $this->seedHonorariumEvent('Upcoming This Month One', 'approved', [
            'event_start' => $start, 'event_end' => $end,
        ]);
        $this->seedHonorariumEvent('Upcoming This Month Two', 'completed', [
            'event_start' => $start, 'event_end' => $end,
        ]);
        $this->seedHonorariumEvent('Upcoming Pending Month', 'pending', [
            'event_start' => $start, 'event_end' => $end,
        ]);
        $this->loginAs('Honorarium Admins');
        $this->get('/events/honoraria/upcoming');
        $this->assertResponseOk();
        $this->assertResponseContains($now->format('M') . ' - 2');
    }

    public function testExportHonorariaListsCompletedInRange(): void
    {
        $inRange = $this->seedHonorariumEvent('Export In Range', 'completed', [
            'event_start' => '2031-01-15 16:00:00', 'event_end' => '2031-01-15 18:00:00',
        ]);
        $this->seedHonorariumEvent('Export Out Of Range', 'completed', [
            'event_start' => '2031-03-15 16:00:00', 'event_end' => '2031-03-15 18:00:00',
        ]);
        $this->seedHonorariumEvent('Export Not Completed', 'approved', [
            'event_start' => '2031-01-16 16:00:00', 'event_end' => '2031-01-16 18:00:00',
        ]);
        $this->loginAs('Financial Reporting');
        $this->get('/events/export-honoraria?start_date=2031-01-01&end_date=2031-01-31');
        $this->assertResponseOk();
        $this->assertResponseContains('Export In Range');
        $this->assertResponseNotContains('Export Out Of Range');
        $this->assertResponseNotContains('Export Not Completed');
    }

    public function testExportHonorariaPaidPostUpdatesRow(): void
    {
        $event = $this->seedHonorariumEvent('Export Paid Event', 'completed', [
            'event_start' => '2031-01-15 16:00:00', 'event_end' => '2031-01-15 18:00:00',
        ]);
        $honorarium = $this->getTableLocator()->get('Honoraria')->find()
            ->where(['event_id' => $event->id])->first();
        $this->loginAs('Financial Reporting');
        $this->post('/events/export-honoraria?start_date=2031-01-01&end_date=2031-01-31', [
            'paid' => [$honorarium->id => 2],
        ]);
        $this->assertResponseOk();
        $fresh = $this->getTableLocator()->get('Honoraria')->get($honorarium->id);
        $this->assertSame(2, (int)$fresh->paid);
    }

    public function testExportHonorariaCsvIsByteExactForSeededRows(): void
    {
        $committee = $this->seedCommittee(['name' => 'CSV Committee']);
        $contact = $this->seedContact([
            'name' => 'CSV Contact', 'email' => 'csv-contact@example.test', 'phone' => '555-0111',
        ]);
        $room = $this->seedRoom();
        $eventA = $this->seedEvent([
            'name' => 'CSV Event A', 'contact' => $contact, 'room' => $room,
            'status' => 'completed',
            'event_start' => '2031-01-15 16:00:00', 'event_end' => '2031-01-15 18:00:00',
        ]);
        $this->seedHonorarium($eventA, $committee, ['pay_contact' => 1, 'paid' => 0]);
        foreach (['One', 'Two', 'Three'] as $label) {
            $this->seedRegistration($eventA, ['name' => 'Attendee ' . $label, 'attended' => 1]);
        }
        $eventB = $this->seedEvent([
            'name' => 'CSV Event B', 'contact' => $contact, 'room' => $room,
            'status' => 'completed',
            'event_start' => '2031-01-16 16:00:00', 'event_end' => '2031-01-16 18:00:00',
        ]);
        $this->seedHonorarium($eventB, $committee, ['pay_contact' => 0, 'paid' => 0]);
        $this->seedRegistration($eventB, ['name' => 'Attendee One', 'attended' => 1]);
        $this->seedRegistration($eventB, ['name' => 'Attendee NoShow', 'attended' => 0]);

        $this->loginAs('Financial Reporting');
        $this->get('/events/export-honoraria-csv?start_date=2031-01-01&end_date=2031-01-31');
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'text/csv');
        $this->assertHeaderContains('Content-Disposition', 'honoria_export.csv');
        // Quoting is PHP fputcsv's (fields containing a space are quoted, others are not),
        // verified identical on PHP 7.4 (Cake 3 image) and PHP 8.4 (Cake 5 image) for this header.
        $expected = '"Event ID","Event Time","Event Name",Committee,"Contact Name","Contact Email","Contact Phone",Attendees,"Requirements Met","Pay Contact?",Paid' . "\n"
            . $eventA->id . ',"01-15-2031 10:00AM","CSV Event A","CSV Committee","CSV Contact",csv-contact@example.test,555-0111,3,"Honoraria Met",Yes,"Not Paid"' . "\n"
            . $eventB->id . ',"01-16-2031 10:00AM","CSV Event B","CSV Committee","CSV Contact",csv-contact@example.test,555-0111,1,"Honoraria Not Met","Don\'t Pay",N/A' . "\n";
        $this->assertSame($expected, (string)$this->_response->getBody());
    }

    public function testExportDeniedForOtherRoles(): void
    {
        $this->seedHonorariumEvent('Export Role Event', 'completed');
        foreach (['export-honoraria', 'export-honoraria-csv'] as $action) {
            foreach (self::roles() as $role => $groups) {
                if ($role === 'financialReporting') {
                    continue;
                }
                $this->cleanup();
                $this->loginAsRole($role);
                $this->get('/events/' . $action . '?start_date=2031-01-01&end_date=2031-01-31');
                $this->assertDenied($role);
            }
        }
    }

    public function cleanup(): void
    {
        parent::cleanup();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }
}
