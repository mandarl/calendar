<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Behavior;

use App\Model\Behavior\RelationalTimeBehavior;
use ArrayObject;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * RelationalTimeBehavior: posted offsets become absolute UTC timestamps (convertFromOffset) and
 * stored dates become offsets again for the edit form (convertToOffset). Values measured against
 * Cake 3 by tests/differential/cases_models.php.
 */
class RelationalTimeBehaviorTest extends TestCase
{
    private function behavior(): RelationalTimeBehavior
    {
        return new RelationalTimeBehavior(new Table(['alias' => 'Events']));
    }

    public function testConvertFromOffset(): void
    {
        $data = new ArrayObject([
            'event_start' => '2031-01-25 00:00:00',
            'event_end' => '2031-01-25 02:00:00',
            'booking_start' => '30',
            'booking_end' => '30',
            'attendee_cancellation' => '1',
        ]);
        $this->behavior()->convertFromOffset($data);
        $this->assertSame('2031-01-24 23:30:00', $data['booking_start']);
        $this->assertSame('2031-01-25 02:30:00', $data['booking_end']);
        $this->assertSame('2031-01-24 00:00:00', $data['attendee_cancellation']);
    }

    public function testConvertFromOffsetZeroAndDay(): void
    {
        $data = new ArrayObject([
            'event_start' => '2031-01-25 00:00:00',
            'event_end' => '2031-01-25 02:00:00',
            'booking_start' => '0',
            'booking_end' => '1440',
            'attendee_cancellation' => '7',
        ]);
        $this->behavior()->convertFromOffset($data);
        $this->assertSame('2031-01-25 00:00:00', $data['booking_start']);
        $this->assertSame('2031-01-26 02:00:00', $data['booking_end']);
        $this->assertSame('2031-01-18 00:00:00', $data['attendee_cancellation']);
    }

    public function testConvertFromOffsetCastsNonNumericToZero(): void
    {
        // Chronos 1 cast the offset with (int); the Cake 5 port does the same, so '2.5' is 2 minutes.
        $data = new ArrayObject([
            'event_start' => '2031-01-25 00:00:00',
            'booking_start' => '2.5',
        ]);
        $this->behavior()->convertFromOffset($data);
        $this->assertSame('2031-01-24 23:58:00', $data['booking_start']);
    }

    public function testConvertFromOffsetMissingFieldBecomesSource(): void
    {
        // Cake 3 overwrote the field with the source date even when the offset was absent; kept.
        $data = new ArrayObject(['event_start' => '2031-01-25 00:00:00']);
        $this->behavior()->convertFromOffset($data);
        $this->assertSame('2031-01-25 00:00:00', $data['booking_start']);
    }

    public function testConvertToOffsetMinutesAndDays(): void
    {
        $b = $this->behavior();
        $start = new DateTime('2031-01-25 00:00:00', 'UTC');
        $this->assertSame(30, $b->convertToOffset(new DateTime('2031-01-24 23:30:00', 'UTC'), $start, 'booking_start'));
        $this->assertSame(150, $b->convertToOffset(new DateTime('2031-01-25 02:30:00', 'UTC'), $start, 'booking_end'));
        $this->assertSame(1, $b->convertToOffset(new DateTime('2031-01-24 00:00:00', 'UTC'), $start, 'attendee_cancellation'));
    }

    public function testConvertToOffsetWrapsAndIgnoresSign(): void
    {
        // Cake 3 quirks, kept: minutes are h*60+i of the interval (a day or more wraps), and the
        // direction is ignored (DateInterval components are absolute).
        $b = $this->behavior();
        $start = new DateTime('2031-01-25 00:00:00', 'UTC');
        $this->assertSame(60, $b->convertToOffset(new DateTime('2031-01-23 23:00:00', 'UTC'), $start, 'booking_start'));
        $this->assertSame(30, $b->convertToOffset(new DateTime('2031-01-25 00:30:00', 'UTC'), $start, 'booking_start'));
        $this->assertSame(1, $b->convertToOffset(new DateTime('2031-01-26 00:00:00', 'UTC'), $start, 'attendee_cancellation'));
    }

    public function testConvertToOffsetNullsAndLocaleStrings(): void
    {
        $b = $this->behavior();
        $start = new DateTime('2031-01-25 00:00:00', 'UTC');
        $this->assertFalse($b->convertToOffset(null, $start, 'booking_start'));
        $this->assertFalse($b->convertToOffset(new DateTime('2031-01-24 23:30:00', 'UTC'), null, 'booking_start'));
        $this->assertSame(30, $b->convertToOffset('01/24/31, 11:30 PM', '01/25/31, 12:00 AM', 'booking_start'));
    }
}

