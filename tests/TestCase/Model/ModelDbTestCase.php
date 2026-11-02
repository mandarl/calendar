<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model;

use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * Base for model tests that need the database (table schema, seed rows, rules checks).
 *
 * They run against the `default` connection. Without a reachable database (plain
 * `vendor/bin/phpunit` with no DB env) they SKIP visibly instead of erroring; the
 * documented run is inside the calc5b docker network against the synthetic database,
 * where every test executes. Each test runs inside a transaction that is rolled back.
 */
abstract class ModelDbTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $conn = ConnectionManager::get('default');
            $conn->execute('SELECT 1');
            $conn->begin();
        } catch (Throwable) {
            $this->markTestSkipped('Requires a reachable default database (see calendar-cake5-b-CHANGES.md).');
        }
    }

    protected function tearDown(): void
    {
        try {
            ConnectionManager::get('default')->rollback();
        } catch (Throwable) {
            // Connection was never established; nothing to roll back.
        }
        parent::tearDown();
    }

    protected function table(string $alias): object
    {
        return FactoryLocator::get('Table')->get($alias);
    }
}

