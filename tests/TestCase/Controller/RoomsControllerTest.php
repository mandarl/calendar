<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

class RoomsControllerTest extends CalendarAdminCrudTestCase
{
    protected string $url = '/rooms';
    protected string $alias = 'Rooms';

    protected function validData(): array
    {
        return ['name' => 'Added by test', 'exclusive' => 1];
    }

    protected function invalidData(): array
    {
        return ['name' => '', 'exclusive' => ''];
    }
}
