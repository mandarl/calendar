<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

class PrerequisitesControllerTest extends CalendarAdminCrudTestCase
{
    protected string $url = '/prerequisites';
    protected string $alias = 'Prerequisites';

    protected function validData(): array
    {
        return ['name' => 'Added by test', 'ad_group' => 'Test AD Group'];
    }

    protected function invalidData(): array
    {
        return ['name' => '', 'ad_group' => ''];
    }
}
