<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Behavior;

use App\Model\Behavior\FriendlyTimeBehavior;
use ArrayObject;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/**
 * FriendlyTimeBehavior: posted `m/d/Y g:i A` America/Chicago strings become UTC `Y-m-d H:i:s`
 * strings, and stored values convert back for the edit form. Values here are the ones the
 * differential suite (tests/differential/cases_events.php, cases_models.php) measured against Cake 3.
 */
class FriendlyTimeBehaviorTest extends TestCase
{
    private function behavior(): FriendlyTimeBehavior
    {
        return new FriendlyTimeBehavior(new Table(['alias' => 'Events']));
    }

    public function testConvertFromFormatChicagoToUtc(): void
    {
        $data = new ArrayObject([
            'event_start' => '01/25/2031 6:00 PM',
            'event_end' => '01/25/2031 8:00 PM',
        ]);
        $this->behavior()->convertFromFormat($data);
        $this->assertSame('2031-01-26 00:00:00', $data['event_start']);
        $this->assertSame('2031-01-26 02:00:00', $data['event_end']);
    }

    public function testConvertFromFormatDst(): void
    {
        $data = new ArrayObject([
            'event_start' => '03/09/2031 1:30 AM',
            'event_end' => '03/09/2031 3:00 AM',
        ]);
        $this->behavior()->convertFromFormat($data);
        $this->assertSame('2031-03-09 07:30:00', $data['event_start']);
        $this->assertSame('2031-03-09 08:00:00', $data['event_end']);

        $fall = new ArrayObject(['event_start' => '11/02/2031 1:30 AM']);
        $this->behavior()->convertFromFormat($fall);
        $this->assertSame('2031-11-02 06:30:00', $fall['event_start']);
    }

    public function testConvertFromFormatMidnightAndNoon(): void
    {
        $data = new ArrayObject([
            'event_start' => '01/25/2031 12:00 AM',
            'event_end' => '01/25/2031 12:00 PM',
        ]);
        $this->behavior()->convertFromFormat($data);
        $this->assertSame('2031-01-25 06:00:00', $data['event_start']);
        $this->assertSame('2031-01-25 18:00:00', $data['event_end']);
    }

    public function testConvertFromFormatGarbageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->behavior()->convertFromFormat(new ArrayObject(['event_start' => 'not a date']));
    }

    public function testConvertFromFormatLeavesMissingFieldsAlone(): void
    {
        $data = new ArrayObject(['name' => 'x']);
        $this->behavior()->convertFromFormat($data);
        $this->assertSame(['name' => 'x'], $data->getArrayCopy());
    }

    public function testConvertToFormatUtcObjects(): void
    {
        $b = $this->behavior();
        $this->assertSame('01/24/2031 6:00 PM', $b->convertToFormat(new DateTime('2031-01-25 00:00:00', 'UTC')));
        $this->assertSame('01/24/2031 6:00 PM', $b->convertToFormat(new DateTime('2031-01-25 00:00:30', 'UTC')));
        $this->assertSame('03/09/2031 3:00 AM', $b->convertToFormat(new DateTime('2031-03-09 08:00:00', 'UTC')));
        $this->assertSame('11/02/2031 1:30 AM', $b->convertToFormat(new DateTime('2031-11-02 07:30:00', 'UTC')));
    }

    public function testConvertToFormatChicagoObjectFollowsCake3RoundTrip(): void
    {
        // Cake 3 parsed the object's locale string (wall clock in its own timezone) as UTC, so a
        // Chicago 6:00 PM object came back as 12:00 PM, not 6:00 PM. Reproduced, not "fixed".
        $b = $this->behavior();
        $this->assertSame('01/25/2031 12:00 PM', $b->convertToFormat(new DateTime('2031-01-25 18:00:00', 'America/Chicago')));
    }

    public function testConvertToFormatLocaleStrings(): void
    {
        $b = $this->behavior();
        $this->assertSame('01/24/2031 12:00 PM', $b->convertToFormat('01/24/31, 6:00 PM'));
        $this->assertSame('01/24/2031 6:00 PM', $b->convertToFormat('01/25/31, 12:00 AM'));
        $this->assertSame('01/25/2031 6:00 AM', $b->convertToFormat('01/25/31, 12:00 PM'));
    }
}

