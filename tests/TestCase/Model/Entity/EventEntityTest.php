<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\Event;
use Cake\TestSuite\TestCase;
use TypeError;

/**
 * Event virtual fields. Values measured against Cake 3 by tests/differential/cases_models.php
 * (virtual:str:*). The diffs are ABSOLUTE in both versions: a cancellation after the start still
 * reports a positive day count.
 */
class EventEntityTest extends TestCase
{
    private function event(?string $start, ?string $cancel, ?string $bookingStart, ?string $bookingEnd): Event
    {
        return new Event([
            'event_start' => $start,
            'attendee_cancellation' => $cancel,
            'booking_start' => $bookingStart,
            'booking_end' => $bookingEnd,
        ]);
    }

    public function testVirtualFieldsFromStrings(): void
    {
        $e = $this->event('2031-01-25 00:00:00', '2031-01-24 00:00:00', '2031-01-24 23:30:00', '2031-01-25 02:30:00');
        $this->assertSame(1, $e->cancellation_days);
        $this->assertSame(30, $e->setup_time);
        $this->assertSame(150, $e->teardown_time);
    }

    public function testVirtualFieldsAreAbsolute(): void
    {
        $e = $this->event('2031-01-25 00:00:00', '2031-01-26 00:00:00', '2031-01-25 00:30:00', '2031-01-25 01:00:00');
        $this->assertSame(1, $e->cancellation_days);
        $this->assertSame(30, $e->setup_time);
        $this->assertSame(60, $e->teardown_time);
    }

    public function testVirtualFieldsNullWhenInputsMissing(): void
    {
        $e = $this->event('2031-01-25 00:00:00', null, '2031-01-24 23:30:00', '2031-01-25 02:30:00');
        $this->assertNull($e->cancellation_days);
        $this->assertSame(30, $e->setup_time);
        $this->assertSame(150, $e->teardown_time);

        $e2 = $this->event(null, '2031-01-24 00:00:00', '2031-01-24 23:30:00', '2031-01-25 02:30:00');
        $this->assertNull($e2->cancellation_days);
        $this->assertNull($e2->setup_time);
        $this->assertNull($e2->teardown_time);
    }

    public function testVirtualFieldsThrowOnObjectValuesAsInCake3(): void
    {
        // Preserved Cake 3 bug, measured in both versions: when the properties hold DateTime
        // objects (as they do on entities loaded from the database), createFromFormat() is handed
        // an object whose locale string does not match 'Y-m-d H:i:s', so the access throws.
        // Cake 3 threw InvalidArgumentException; Cake 5 throws TypeError. No template uses these
        // virtual fields, so both surface as a 500 in the same place.
        $e = new Event([
            'event_start' => new \Cake\I18n\DateTime('2031-01-25 00:00:00', 'UTC'),
            'attendee_cancellation' => new \Cake\I18n\DateTime('2031-01-24 00:00:00', 'UTC'),
        ]);
        $this->expectException(TypeError::class);
        $e->cancellation_days; // @phpstan-ignore-line
    }
}

