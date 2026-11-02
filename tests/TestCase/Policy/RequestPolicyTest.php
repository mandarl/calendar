<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\RequestPolicy;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * The group rules of the old per-controller isAuthorized() methods (Events/Registrations rules that need the
 * database are covered by the controller tests of their slices).
 */
class RequestPolicyTest extends TestCase
{
    private function request(string $controller, string $action, array $pass = []): ServerRequest
    {
        return (new ServerRequest())
            ->withParam('controller', $controller)
            ->withParam('action', $action)
            ->withParam('pass', $pass);
    }

    private function user(string ...$groups): array
    {
        return ['samaccountname' => 'test-user', 'groups' => $groups];
    }

    public function testAdminControllersNeedCalendarAdmins(): void
    {
        $policy = new RequestPolicy();
        foreach (['Categories', 'Committees', 'Configurations', 'Contacts', 'Prerequisites', 'Rooms', 'Tools', 'Files'] as $c) {
            $this->assertTrue($policy->check($this->user('Calendar Admins'), $this->request($c, 'index')), $c);
            $this->assertFalse($policy->check($this->user('Honorarium Admins'), $this->request($c, 'index')), $c);
            $this->assertFalse($policy->check(null, $this->request($c, 'index')), $c);
        }
    }

    public function testSuperAdminControllers(): void
    {
        $policy = new RequestPolicy();
        foreach (['CalendarAdmin', 'Logs'] as $c) {
            $this->assertTrue($policy->check($this->user('Calendar Super Admins'), $this->request($c, 'edit')));
            $this->assertFalse($policy->check($this->user('Calendar Admins'), $this->request($c, 'edit')));
        }
    }

    public function testContactsViewIsAlsoOpenToHonorariumAndFinance(): void
    {
        $policy = new RequestPolicy();
        $view = $this->request('Contacts', 'view', ['x']);
        $this->assertTrue($policy->check($this->user('Honorarium Admins'), $view));
        $this->assertTrue($policy->check($this->user('Financial Reporting'), $view));
        $this->assertFalse($policy->check($this->user('Honorarium Admins'), $this->request('Contacts', 'index')));
    }

    public function testEventsListsAreSplitByGroup(): void
    {
        $policy = new RequestPolicy();
        $this->assertTrue($policy->check($this->user(), $this->request('Events', 'add')));
        $this->assertTrue($policy->check($this->user(), $this->request('Events', 'attending')));
        $this->assertFalse($policy->check(null, $this->request('Events', 'add')));
        $this->assertTrue($policy->check($this->user('Calendar Admins'), $this->request('Events', 'pending')));
        $this->assertFalse($policy->check($this->user('Honorarium Admins'), $this->request('Events', 'pending')));
        $this->assertTrue($policy->check($this->user('Honorarium Admins'), $this->request('Events', 'pendingHonoraria')));
        $this->assertFalse($policy->check($this->user('Calendar Admins'), $this->request('Events', 'pendingHonoraria')));
        $this->assertTrue($policy->check($this->user('Financial Reporting'), $this->request('Events', 'exportHonoraria')));
        // actions that were never listed are denied to everybody
        $this->assertFalse($policy->check($this->user('Calendar Admins', 'Calendar Super Admins'), $this->request('Events', 'eventsJson')));
    }

    public function testGroupsMissingOrMalformedMeanNoAccess(): void
    {
        $this->assertFalse(RequestPolicy::inGroup(['samaccountname' => 'x'], 'Calendar Admins'));
        $this->assertFalse(RequestPolicy::inGroup(['groups' => 'Calendar Admins'], 'Calendar Admins'));
        $this->assertFalse(RequestPolicy::inGroup(null, 'Calendar Admins'));
    }
}
