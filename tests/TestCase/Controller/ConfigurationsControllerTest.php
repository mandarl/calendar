<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * Configurations: Crud add/edit round trips, with two preserved Cake 3 quirks:
 * the index only lists ids < 7, and there is no add template (the Cake 3 build
 * had no Configurations/add.ctp either), so rendering the add form is a 500
 * in both builds while a successful add POST still redirects to the index.
 */
class ConfigurationsControllerTest extends CalendarAdminCrudTestCase
{
    protected string $url = '/configurations';
    protected string $alias = 'Configurations';
    protected bool $hasDelete = false;

    protected function validData(): array
    {
        return ['name' => 'Added by test', 'value' => 42];
    }

    protected function invalidData(): array
    {
        return ['name' => '', 'value' => ''];
    }

    public function testIndexOnlyForCalendarAdmins(): void
    {
        // A seeded row (id 8+) is outside the index's id < 7 filter, so check a
        // lookup row that ships with the schema instead of the seeded row.
        foreach (self::roles() as $role => $groups) {
            $this->cleanup();
            $this->loginAsRole($role);
            $this->get($this->url);
            if ($role === 'calendarAdmin') {
                $this->assertResponseOk();
                $this->assertResponseContains('Automatic Approval Time');
            } else {
                $this->assertDenied($role);
            }
        }
    }

    public function testAddFormRenders(): void
    {
        // No add template in either build (see class docblock): rendering the
        // add form is a 500, not a form.
        $this->loginAs('Calendar Admins');
        $this->get($this->url . '/add');
        $this->assertResponseCode(500);
    }

    public function testAddWithInvalidDataShowsErrorAndWritesNothing(): void
    {
        // Invalid data re-renders the (missing) add template: 500, nothing written.
        $before = $this->countRows($this->alias);
        $this->loginAs('Calendar Admins');
        $this->post($this->url . '/add', $this->invalidData());
        $this->assertResponseCode(500);
        $this->assertSame($before, $this->countRows($this->alias));
    }
}
