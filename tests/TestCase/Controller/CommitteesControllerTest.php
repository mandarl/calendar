<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

class CommitteesControllerTest extends CalendarAdminCrudTestCase
{
    protected string $url = '/committees';
    protected string $alias = 'Committees';

    protected function validData(): array
    {
        return ['name' => 'Added by test'];
    }

    protected function invalidData(): array
    {
        return ['name' => ''];
    }
}
