<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

class ToolsControllerTest extends CalendarAdminCrudTestCase
{
    protected string $url = '/tools';
    protected string $alias = 'Tools';

    protected function validData(): array
    {
        return ['name' => 'Added by test'];
    }

    protected function invalidData(): array
    {
        return ['name' => ''];
    }
}
