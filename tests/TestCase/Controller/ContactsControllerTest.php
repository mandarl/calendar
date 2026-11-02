<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

class ContactsControllerTest extends CalendarAdminCrudTestCase
{
    use EventsSeedTrait;

    protected string $url = '/contacts';
    protected string $alias = 'Contacts';

    protected function validData(): array
    {
        return [
            'name' => 'Added by test',
            'email' => 'added@example.test',
            'phone' => '555-0100',
            'w9_on_file' => 0,
        ];
    }

    protected function invalidData(): array
    {
        return [
            'name' => '',
            'email' => 'not-an-email',
            'phone' => '',
            'w9_on_file' => 0,
        ];
    }

    public function testViewShowsAttendedAndHosted(): void
    {
        $contact = $this->seedContact(['name' => 'Viewed Contact', 'ad_username' => 'viewuser']);
        $attendedEvent = $this->seedEvent(['name' => 'Attended Event']);
        $this->seedRegistration($attendedEvent, ['ad_username' => 'viewuser', 'attended' => 1]);
        $this->seedEvent(['name' => 'Hosted Event', 'created_by' => 'viewuser']);

        $this->loginAs('Honorarium Admins');
        $this->get('/contacts/view/viewuser');
        $this->assertResponseOk();
        $this->assertResponseContains('Viewed Contact');
        $this->assertResponseContains('Attended Event');
        $this->assertResponseContains('Hosted Event');
        $this->assertResponseContains('/events/view/' . $attendedEvent->id);
    }

    public function testViewAllowedRoles(): void
    {
        $this->seedContact(['name' => 'Role Contact', 'ad_username' => 'roleuser']);
        foreach (self::roles() as $role => $groups) {
            $this->cleanup();
            $this->loginAsRole($role);
            $this->get('/contacts/view/roleuser');
            if (in_array($role, ['calendarAdmin', 'honorariumAdmin', 'financialReporting'], true)) {
                $this->assertResponseOk('role ' . $role);
                $this->assertResponseContains('Role Contact', 'role ' . $role);
            } else {
                $this->assertDenied($role);
            }
        }
    }

    public function testViewOfMissingContactIs404(): void
    {
        $this->loginAs('Calendar Admins');
        $this->get('/contacts/view/nonexistent-user');
        $this->assertResponseCode(404);
    }
}
