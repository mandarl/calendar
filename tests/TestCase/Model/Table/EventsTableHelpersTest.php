<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\TestCase\Model\ModelDbTestCase;
use Cake\Datasource\Exception\RecordNotFoundException;

/**
 * EventsTable helpers against the synthetic seed (events 1001/1003/1004/1026/1027/1031).
 * Seed facts: 1001 free 10 / paid 0 / cost 0, 1 confirmed + 1 cancelled free reg;
 * 1003 free 0 / paid 8 / cost 5, 2 confirmed + 1 cancelled paid reg; 1004 paid 6, 1 confirmed;
 * 1026 is the only seed event with an honorarium row.
 */
class EventsTableHelpersTest extends ModelDbTestCase
{
    public function testGetTotalAndFilledSpaces(): void
    {
        $t = $this->table('Events');
        $this->assertSame(10, $t->getTotalSpaces(1001));
        $this->assertSame(8, $t->getTotalSpaces(1003));
        $this->assertSame(6, $t->getTotalSpaces(1004));
        $this->assertSame(1, $t->getFilledSpaces(1001));
        $this->assertSame(2, $t->getFilledSpaces(1003));
        $this->assertSame(1, $t->getFilledSpaces(1004));
    }

    public function testHasFreePaidAndOpenSpaces(): void
    {
        $t = $this->table('Events');
        $this->assertTrue($t->hasFreeSpaces(1001));
        $this->assertFalse($t->hasFreeSpaces(1003));
        $this->assertFalse($t->hasPaidSpaces(1001));
        $this->assertTrue($t->hasPaidSpaces(1003));
        $this->assertTrue($t->hasPaidSpaces(1004));
        $this->assertTrue($t->hasOpenSpaces(1001));
        $this->assertTrue($t->hasOpenSpaces(1003));
    }

    public function testHasHonorarium(): void
    {
        $t = $this->table('Events');
        $this->assertSame(1, $t->hasHonorarium(1026));
        $this->assertSame(0, $t->hasHonorarium(1001));
        $this->assertSame(0, $t->hasHonorarium(1003));
    }

    public function testIsOwnedBy(): void
    {
        $t = $this->table('Events');
        $this->assertTrue($t->isOwnedBy(1001, 'user1'));
        $this->assertFalse($t->isOwnedBy(1001, 'user3'));
        $this->assertFalse($t->isOwnedBy(1001, 'nobody'));
        $this->assertFalse($t->isOwnedBy(9999, 'user1'));
    }

    public function testHelpersThrowForMissingEvent(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->table('Events')->getTotalSpaces(9999);
    }
}

