<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

class CategoriesControllerTest extends CalendarAdminCrudTestCase
{
    protected string $url = '/categories';
    protected string $alias = 'Categories';

    protected function validData(): array
    {
        return ['name' => 'Added by test'];
    }

    protected function invalidData(): array
    {
        return ['name' => ''];
    }
}
