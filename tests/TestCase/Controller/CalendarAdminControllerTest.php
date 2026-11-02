<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * The Super Calendar Admin settings page: it edits configuration id 7
 * ("Allow Honoraria") and calendar super configuration id 1 (the honoraria
 * message), and only Calendar Super Admins may use it.
 */
class CalendarAdminControllerTest extends CalendarAdminTestSupport
{
    public function testEditPageOnlyForSuperAdmins(): void
    {
        foreach (self::roles() as $role => $groups) {
            $this->cleanup();
            $this->loginAsRole($role);
            $this->get('/calendar-admin/edit');
            if ($role === 'superAdmin') {
                $this->assertResponseOk();
                $this->assertResponseContains('Allow Honoraria');
                $this->assertResponseContains('Message to be displayed');
            } else {
                $this->assertDenied($role);
            }
        }
    }

    public function testEditUpdatesBothRows(): void
    {
        $this->loginAs('Calendar Super Admins');
        $this->post('/calendar-admin/edit', [
            'id' => 7,
            'value' => 2,
            'Honoraria' => ['message' => 'Test honoraria message'],
        ]);
        $this->assertResponseOk();
        $this->assertFlashMessage('Updated successfully');
        $config = $this->getTableLocator()->get('Configurations')->get(7);
        $this->assertSame(2, (int)$config->value);
        $message = $this->getTableLocator()->get('CalendarSuperConfigurations')->get(1);
        $this->assertSame('Test honoraria message', $message->value);
    }

    public function testEditIsDeniedForOtherRolesAndWritesNothing(): void
    {
        foreach (self::roles() as $role => $groups) {
            if ($role === 'superAdmin') {
                continue;
            }
            $this->cleanup();
            $this->loginAsRole($role);
            $this->post('/calendar-admin/edit', [
                'id' => 7,
                'value' => 2,
                'Honoraria' => ['message' => 'Must not land'],
            ]);
            $this->assertDenied($role);
        }
        $config = $this->getTableLocator()->get('Configurations')->get(7);
        $this->assertSame(1, (int)$config->value);
        $message = $this->getTableLocator()->get('CalendarSuperConfigurations')->get(1);
        $this->assertSame('', (string)$message->value);
    }

    /**
     * Drop the session/request state between the iterations of a role loop.
     */
    public function cleanup(): void
    {
        parent::cleanup();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }
}
