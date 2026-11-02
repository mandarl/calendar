<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * The plain Honoraria scaffold (Calendar Admins only). The index test is the
 * regression test for slice F's crawl finding: the event and committee cells
 * rendered empty in the integration tree because its index passed `contain`
 * in the paginate settings, which Cake 5 ignores. This copy puts the contain
 * on the query, so the cells must carry links.
 */
class HonorariaControllerTest extends CalendarAdminTestSupport
{
    use EventsSeedTrait;

    private function seedHonorariumWithEvent(): array
    {
        $event = $this->seedEvent(['name' => 'Honorarium Event']);
        $committee = $this->seedCommittee(['name' => 'Honorarium Committee']);
        $honorarium = $this->seedHonorarium($event, $committee);

        return [$event, $committee, $honorarium];
    }

    public function testIndexShowsEventAndCommitteeLinks(): void
    {
        [$event, $committee, $honorarium] = $this->seedHonorariumWithEvent();
        $this->loginAs('Calendar Admins');
        $this->get('/honoraria');
        $this->assertResponseOk();
        $this->assertResponseContains('/events/view/' . $event->id);
        $this->assertResponseContains('Honorarium Event');
        $this->assertResponseContains('/committees/view/' . $committee->id);
        $this->assertResponseContains('Honorarium Committee');
    }

    public function testIndexOnlyForCalendarAdmins(): void
    {
        $this->seedHonorariumWithEvent();
        foreach (self::roles() as $role => $groups) {
            $this->cleanup();
            $this->loginAsRole($role);
            $this->get('/honoraria');
            if ($role === 'calendarAdmin') {
                $this->assertResponseOk();
            } else {
                $this->assertDenied($role);
            }
        }
    }

    public function testViewShowsEventAndCommittee(): void
    {
        [$event, $committee, $honorarium] = $this->seedHonorariumWithEvent();
        $this->loginAs('Calendar Admins');
        $this->get('/honoraria/view/' . $honorarium->id);
        $this->assertResponseOk();
        $this->assertResponseContains('Honorarium Event');
        $this->assertResponseContains('Honorarium Committee');
    }

    public function testAddCreatesRowAndRedirectsToList(): void
    {
        $event = $this->seedEvent(['name' => 'Honorarium Add Event']);
        $committee = $this->seedCommittee();
        $before = $this->countRows('Honoraria');
        $this->loginAs('Calendar Admins');
        $this->get('/honoraria/add');
        $this->assertResponseOk();
        $this->post('/honoraria/add', [
            'event_id' => $event->id,
            'committee_id' => $committee->id,
            'pay_contact' => 1,
            'paid' => 0,
        ]);
        $this->assertRedirect(['controller' => 'Honoraria', 'action' => 'index']);
        $this->assertSame($before + 1, $this->countRows('Honoraria'));
    }

    public function testEditUpdatesRowAndRedirectsToList(): void
    {
        [$event, $committee, $honorarium] = $this->seedHonorariumWithEvent();
        $this->loginAs('Calendar Admins');
        $this->post('/honoraria/edit/' . $honorarium->id, ['paid' => 1]);
        $this->assertRedirect(['controller' => 'Honoraria', 'action' => 'index']);
        $fresh = $this->getTableLocator()->get('Honoraria')->get($honorarium->id);
        $this->assertSame(1, (int)$fresh->paid);
    }

    public function testDeleteRemovesRowOnPostAndRedirectsToList(): void
    {
        [$event, $committee, $honorarium] = $this->seedHonorariumWithEvent();
        $this->loginAs('Calendar Admins');
        $this->post('/honoraria/delete/' . $honorarium->id);
        $this->assertRedirect(['controller' => 'Honoraria', 'action' => 'index']);
        $this->assertSame(0, $this->countRows('Honoraria', ['id' => $honorarium->id]));
    }

    public function testWritesDeniedForOtherRoles(): void
    {
        [$event, $committee, $honorarium] = $this->seedHonorariumWithEvent();
        foreach (self::roles() as $role => $groups) {
            if ($role === 'calendarAdmin') {
                continue;
            }
            $this->cleanup();
            $this->loginAsRole($role);
            $this->post('/honoraria/add', [
                'event_id' => $event->id,
                'committee_id' => $committee->id,
                'pay_contact' => 1,
                'paid' => 0,
            ]);
            $this->assertDenied($role);
            $this->cleanup();
            $this->loginAsRole($role);
            $this->post('/honoraria/delete/' . $honorarium->id);
            $this->assertDenied($role);
        }
        $this->assertSame(1, $this->countRows('Honoraria', ['id' => $honorarium->id]));
    }

    public function cleanup(): void
    {
        parent::cleanup();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }
}
