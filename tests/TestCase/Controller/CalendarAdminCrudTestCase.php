<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * Round trips of an admin CRUD controller (Crud plugin: index, add, edit, delete) through the whole HTTP stack:
 * the page renders, a valid POST writes and redirects to the list, an invalid POST re-renders with the error, delete
 * only works with POST, and only Calendar Admins get in (RequestPolicy; everybody else is redirected).
 *
 * The subclasses describe one controller. The Cake 3 build had no test for any of this (the baseline only renders
 * the index/add/edit pages), so the expectations come from reading the Cake 3 code and from the comparison run
 * against the Cake 3 image (see calendar-cake5-ahl-CHANGES.md).
 */
abstract class CalendarAdminCrudTestCase extends CalendarAdminTestSupport
{
    /** URL prefix, e.g. "/categories". */
    protected string $url;

    /** Table alias, e.g. "Categories". */
    protected string $alias;

    /** Controller has add / edit / delete actions. */
    protected bool $hasAdd = true;
    protected bool $hasDelete = true;

    /**
     * Fields of a valid new row.
     *
     * @return array<string, mixed>
     */
    abstract protected function validData(): array;

    /**
     * Fields that fail validation on add/edit.
     *
     * @return array<string, mixed>
     */
    abstract protected function invalidData(): array;

    /**
     * Fields the edit test changes (merged over the existing row), and the field to check afterwards.
     *
     * @return array<string, mixed>
     */
    protected function changedData(): array
    {
        return ['name' => 'Renamed by test'];
    }

    /**
     * Text that must be on the index page for a row.
     *
     * @return string
     */
    protected function listedText(): string
    {
        return 'Seeded by test';
    }

    /**
     * Insert one row for the edit/delete/index tests.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    protected function seed()
    {
        return $this->make($this->alias, ['name' => 'Seeded by test'] + $this->validData());
    }

    public function testIndexOnlyForCalendarAdmins(): void
    {
        $this->seed();
        foreach (self::roles() as $role => $groups) {
            $this->cleanup();
            $this->loginAsRole($role);
            $this->get($this->url);
            if ($role === 'calendarAdmin') {
                $this->assertResponseOk();
                $this->assertResponseContains($this->listedText());
            } else {
                $this->assertDenied($role);
            }
        }
    }

    public function testAddFormRenders(): void
    {
        if (!$this->hasAdd) {
            $this->markTestSkipped('no add action');
        }
        $this->loginAs('Calendar Admins');
        $this->get($this->url . '/add');
        $this->assertResponseOk();
        $this->assertResponseContains('<form');
        foreach (array_keys($this->validData()) as $field) {
            $this->assertResponseContains('name="' . $field . '"');
        }
    }

    public function testAddCreatesRowAndRedirectsToList(): void
    {
        if (!$this->hasAdd) {
            $this->markTestSkipped('no add action');
        }
        $before = $this->countRows($this->alias);
        $this->loginAs('Calendar Admins');
        $this->post($this->url . '/add', $this->validData());
        $this->assertRedirect([
            'controller' => $this->controllerName(),
            'action' => 'index',
        ]);
        $this->assertSame($before + 1, $this->countRows($this->alias));
        $row = $this->getTableLocator()->get($this->alias)->find()->orderByDesc('id')->first();
        foreach ($this->validData() as $field => $value) {
            if (is_bool($value)) {
                $value = (int)$value;
            }
            $this->assertEquals($value, $row->get($field), $field);
        }
        $this->assertNotNull($row->created);
    }

    public function testAddWithInvalidDataShowsErrorAndWritesNothing(): void
    {
        if (!$this->hasAdd) {
            $this->markTestSkipped('no add action');
        }
        $before = $this->countRows($this->alias);
        $this->loginAs('Calendar Admins');
        $this->post($this->url . '/add', $this->invalidData());
        $this->assertResponseOk();
        // Cake 5 port renders field errors as Bootstrap `help-block` (slice F's FormHelper),
        // not Cake 3's `error-message` div; the message text and the unchanged row count
        // below are what prove the invalid POST was rejected.
        $this->assertResponseContains('help-block');
        $this->assertSame($before, $this->countRows($this->alias));
    }

    public function testAddIsDeniedForOtherRoles(): void
    {
        if (!$this->hasAdd) {
            $this->markTestSkipped('no add action');
        }
        $before = $this->countRows($this->alias);
        foreach (self::roles() as $role => $groups) {
            if ($role === 'calendarAdmin') {
                continue;
            }
            $this->cleanup();
            $this->loginAsRole($role);
            $this->post($this->url . '/add', $this->validData());
            $this->assertDenied($role);
        }
        $this->assertSame($before, $this->countRows($this->alias));
    }

    public function testEditFormShowsCurrentValues(): void
    {
        $row = $this->seed();
        $this->loginAs('Calendar Admins');
        $this->get($this->url . '/edit/' . $row->id);
        $this->assertResponseOk();
        $this->assertResponseContains('Seeded by test');
    }

    public function testEditUpdatesRowAndRedirectsToList(): void
    {
        $row = $this->seed();
        $this->loginAs('Calendar Admins');
        $this->put($this->url . '/edit/' . $row->id, $this->changedData());
        $this->assertRedirect(['controller' => $this->controllerName(), 'action' => 'index']);
        $fresh = $this->getTableLocator()->get($this->alias)->get($row->id);
        foreach ($this->changedData() as $field => $value) {
            $this->assertEquals($value, $fresh->get($field), $field);
        }
    }

    public function testEditWithInvalidDataKeepsRow(): void
    {
        $row = $this->seed();
        $this->loginAs('Calendar Admins');
        $this->put($this->url . '/edit/' . $row->id, $this->invalidData());
        $this->assertResponseOk();
        // See testAddWithInvalidDataShowsErrorAndWritesNothing: `help-block`, not `error-message`.
        $this->assertResponseContains('help-block');
        $fresh = $this->getTableLocator()->get($this->alias)->get($row->id);
        $this->assertSame($row->name, $fresh->name);
    }

    public function testEditOfMissingRowIs404(): void
    {
        $this->loginAs('Calendar Admins');
        $this->get($this->url . '/edit/99999999');
        $this->assertResponseCode(404);
    }

    public function testEditIsDeniedForOtherRoles(): void
    {
        $row = $this->seed();
        foreach (self::roles() as $role => $groups) {
            if ($role === 'calendarAdmin') {
                continue;
            }
            $this->cleanup();
            $this->loginAsRole($role);
            $this->put($this->url . '/edit/' . $row->id, $this->changedData());
            $this->assertDenied($role);
        }
        $this->assertSame($row->name, $this->getTableLocator()->get($this->alias)->get($row->id)->name);
    }

    public function testDeleteRemovesRowOnPostAndRedirectsToList(): void
    {
        if (!$this->hasDelete) {
            $this->markTestSkipped('no delete action');
        }
        $row = $this->seed();
        $this->loginAs('Calendar Admins');
        $this->post($this->url . '/delete/' . $row->id);
        $this->assertRedirect(['controller' => $this->controllerName(), 'action' => 'index']);
        $this->assertSame(0, $this->countRows($this->alias, ['id' => $row->id]));
    }

    public function testDeleteWithGetDoesNotDelete(): void
    {
        if (!$this->hasDelete) {
            $this->markTestSkipped('no delete action');
        }
        $row = $this->seed();
        $this->loginAs('Calendar Admins');
        $this->get($this->url . '/delete/' . $row->id);
        $this->assertSame(1, $this->countRows($this->alias, ['id' => $row->id]));
        $this->assertNotSame(200, $this->_response->getStatusCode());
    }

    public function testDeleteIsDeniedForOtherRoles(): void
    {
        if (!$this->hasDelete) {
            $this->markTestSkipped('no delete action');
        }
        $row = $this->seed();
        foreach (self::roles() as $role => $groups) {
            if ($role === 'calendarAdmin') {
                continue;
            }
            $this->cleanup();
            $this->loginAsRole($role);
            $this->post($this->url . '/delete/' . $row->id);
            $this->assertDenied($role);
        }
        $this->assertSame(1, $this->countRows($this->alias, ['id' => $row->id]));
    }

    /**
     * Drop the session/request state between the iterations of a role loop.
     *
     * @return void
     */
    public function cleanup(): void
    {
        parent::cleanup();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }

    protected function controllerName(): string
    {
        return ltrim(str_replace(' ', '', ucwords(str_replace('-', ' ', $this->url))), '/');
    }
}
