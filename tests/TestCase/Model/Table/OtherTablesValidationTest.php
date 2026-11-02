<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\TestCase\Model\ModelDbTestCase;

/**
 * Validation for the tables other than Events/Registrations. All cases were measured against
 * Cake 3 by tests/differential/cases_models.php; only the framework's default error MESSAGE
 * text differs (Cake 3's generic text vs Cake 5's specific text), which these tests do not
 * assert on.
 */
class OtherTablesValidationTest extends ModelDbTestCase
{
    public function testContactsValidation(): void
    {
        $t = $this->table('Contacts');
        $base = ['name' => 'Test Instructor A', 'email' => 'instructor.a@example.test', 'phone' => '555-0100', 'w9_on_file' => '1', 'blacklisted' => '0'];
        $this->assertSame([], $t->newEntity($base)->getErrors());
        $this->assertArrayHasKey('email', $t->newEntity(array_merge($base, ['email' => 'bad']))->getErrors());
        $this->assertArrayHasKey('name', $t->newEntity(array_merge($base, ['name' => '']))->getErrors());
        $this->assertArrayHasKey('w9_on_file', $t->newEntity(array_merge($base, ['w9_on_file' => 'x']))->getErrors());
        $this->assertArrayNotHasKey('w9_on_file', $t->newEntity(array_merge($base, ['w9_on_file' => '0']))->getErrors());
    }

    public function testContactsEmailUniquenessIsARule(): void
    {
        $t = $this->table('Contacts');
        // Seed contact 2001 already holds instructor.a@example.test, so use a fresh address here.
        $first = $t->newEntity(['name' => 'Test Instructor A', 'email' => 'dup.probe@example.test', 'phone' => '555-0100', 'w9_on_file' => '1']);
        $this->assertNotFalse($t->save($first));
        $second = $t->newEntity(['name' => 'Someone Else', 'email' => 'dup.probe@example.test', 'phone' => '555-0101', 'w9_on_file' => '0']);
        $this->assertFalse($t->save($second));
        $this->assertArrayHasKey('email', $second->getErrors());
    }

    public function testHonorariaValidation(): void
    {
        $t = $this->table('Honoraria');
        $base = ['pay_contact' => '1', 'paid' => '50.00', 'event_id' => '1001', 'committee_id' => '1'];
        $this->assertSame([], $t->newEntity($base)->getErrors());
        $this->assertArrayHasKey('pay_contact', $t->newEntity(array_merge($base, ['pay_contact' => 'x']))->getErrors());
        $this->assertArrayHasKey('paid', $t->newEntity(array_merge($base, ['paid' => 'abc']))->getErrors());
        $this->assertArrayNotHasKey('paid', $t->newEntity(array_merge($base, ['paid' => '1e2']))->getErrors());
    }

    public function testSimpleTablesValidation(): void
    {
        $this->assertArrayHasKey('name', $this->table('Categories')->newEntity(['name' => ''])->getErrors());
        $this->assertSame([], $this->table('Categories')->newEntity(['name' => 'New Category'])->getErrors());
        $this->assertArrayHasKey('name', $this->table('Committees')->newEntity(['name' => ''])->getErrors());
        $this->assertArrayHasKey('name', $this->table('Tools')->newEntity(['name' => ''])->getErrors());
        $this->assertArrayHasKey('value', $this->table('Configurations')->newEntity(['name' => 'x', 'value' => 'abc'])->getErrors());
        $this->assertArrayHasKey('ad_group', $this->table('Prerequisites')->newEntity(['name' => 'x', 'ad_group' => ''])->getErrors());
        $this->assertArrayHasKey('exclusive', $this->table('Rooms')->newEntity(['name' => 'x', 'exclusive' => 'x'])->getErrors());
        $this->assertArrayNotHasKey('exclusive', $this->table('Rooms')->newEntity(['name' => 'x', 'exclusive' => '1'])->getErrors());
        $this->assertArrayHasKey('description', $this->table('CalendarSuperConfigurations')->newEntity(['description' => ''])->getErrors());
    }

    public function testUploadTablesFileValidation(): void
    {
        // W9sTable used the removed Validator::provider() in Cake 5 until this slice fixed it to
        // setProvider(); both tables must report a plain validation error for a missing file.
        $this->assertArrayHasKey('file', $this->table('W9s')->newEntity(['contact_id' => '2001', 'file' => null])->getErrors());
        $this->assertArrayHasKey('file', $this->table('Files')->newEntity(['event_id' => '1001', 'file' => null])->getErrors());
        $this->assertArrayHasKey('private', $this->table('Files')->newEntity(['event_id' => '1001', 'private' => 'x'])->getErrors());
    }
}

