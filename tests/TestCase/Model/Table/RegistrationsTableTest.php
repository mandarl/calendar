<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\TestCase\Model\ModelDbTestCase;

/**
 * RegistrationsTable validation (everything except refund(), which slice R owns and which
 * still calls the removed Braintree_* statics, so it is deliberately not exercised here).
 * Seed facts: registration 3001 is event 1001 / attendee.3001@example.test / ad user1;
 * 3003 is event 1003 / attendee.3003@example.test / ad user3.
 */
class RegistrationsTableTest extends ModelDbTestCase
{
    private function base(): array
    {
        return [
            'event_id' => '1001',
            'type' => 'free',
            'name' => 'Test Attendee',
            'email' => 'new.person@example.test',
            'phone' => '',
            'ad_username' => 'newuser1',
            'send_text' => '0',
            'edit_key' => 'abc123XYZ',
            'status' => 'confirmed',
            'attended' => '0',
            'ad_assigned' => '0',
        ];
    }

    public function testValidRegistrationHasNoErrors(): void
    {
        $e = $this->table('Registrations')->newEntity($this->base());
        $this->assertSame([], $e->getErrors());
    }

    public function testEmailFormatAndScopedUniqueness(): void
    {
        $t = $this->table('Registrations');
        $bad = $t->newEntity(array_merge($this->base(), ['email' => 'not-an-email']));
        $this->assertArrayHasKey('email', $bad->getErrors());

        $dupSame = $t->newEntity(array_merge($this->base(), ['email' => 'attendee.3001@example.test']));
        $this->assertSame(
            'The email address is already associated with a registration for this event.',
            $dupSame->getErrors()['email']['unique']
        );

        // Same email on a different event passes the scoped unique rule.
        $dupOther = $t->newEntity(array_merge($this->base(), ['event_id' => '1003', 'email' => 'attendee.3001@example.test']));
        $this->assertArrayNotHasKey('email', $dupOther->getErrors());
    }

    public function testAdUsernameScopedUniqueness(): void
    {
        $t = $this->table('Registrations');
        $dupSame = $t->newEntity(array_merge($this->base(), ['ad_username' => 'user1']));
        $this->assertArrayHasKey('ad_username', $dupSame->getErrors());
        $dupOther = $t->newEntity(array_merge($this->base(), ['event_id' => '1003', 'ad_username' => 'user1']));
        $this->assertArrayNotHasKey('ad_username', $dupOther->getErrors());
    }

    public function testEditKeyMustBeAlphanumeric(): void
    {
        $t = $this->table('Registrations');
        foreach (['abc-123', 'abc 123', 'abc_123'] as $bad) {
            $e = $t->newEntity(array_merge($this->base(), ['edit_key' => $bad]));
            $this->assertArrayHasKey('edit_key', $e->getErrors(), $bad);
        }
        $ok = $t->newEntity(array_merge($this->base(), ['edit_key' => 'abc123XYZ']));
        $this->assertArrayNotHasKey('edit_key', $ok->getErrors());
    }

    public function testTypeAndStatusInLists(): void
    {
        $t = $this->table('Registrations');
        $this->assertArrayHasKey('type', $t->newEntity(array_merge($this->base(), ['type' => 'x']))->getErrors());
        $this->assertArrayNotHasKey('type', $t->newEntity(array_merge($this->base(), ['type' => 'paid']))->getErrors());
        $this->assertArrayHasKey('status', $t->newEntity(array_merge($this->base(), ['status' => 'approved']))->getErrors());
        foreach (['confirmed', 'cancelled', 'pending'] as $ok) {
            $this->assertArrayNotHasKey('status', $t->newEntity(array_merge($this->base(), ['status' => $ok]))->getErrors());
        }
    }

    public function testBooleanFieldsRejectNonBooleans(): void
    {
        $t = $this->table('Registrations');
        $this->assertArrayHasKey('send_text', $t->newEntity(array_merge($this->base(), ['send_text' => 'x']))->getErrors());
        $this->assertArrayNotHasKey('send_text', $t->newEntity(array_merge($this->base(), ['send_text' => '1']))->getErrors());
    }

    public function testSaveRules(): void
    {
        $t = $this->table('Registrations');
        $ok = $t->newEntity($this->base());
        $this->assertNotFalse($t->save($ok));

        $badEvent = $t->newEntity(array_merge($this->base(), ['event_id' => '9999']));
        $this->assertFalse($t->save($badEvent));
        $this->assertArrayHasKey('event_id', $badEvent->getErrors());

        $dup = $t->newEntity(array_merge($this->base(), ['email' => 'attendee.3001@example.test']));
        $this->assertFalse($t->save($dup));
        $this->assertArrayHasKey('email', $dup->getErrors());
    }
}

