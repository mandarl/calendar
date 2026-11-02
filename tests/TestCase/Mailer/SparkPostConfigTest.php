<?php
declare(strict_types=1);

namespace App\Test\TestCase\Mailer;

use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;
use Dms\Kit\Cake\SparkPostTransport;

/**
 * The `sparkpost` transport name resolves to the libkit SparkPost API transport, and the app's default mailer
 * profile uses it (owner decision: SparkPost API, click tracking off; the transport enforces the latter).
 */
class SparkPostConfigTest extends TestCase
{
    public function testSparkpostTransportIsTheKitTransport(): void
    {
        $this->assertSame(SparkPostTransport::class, TransportFactory::getConfig('sparkpost')['className']);
        $this->assertSame('sparkpost', Mailer::getConfig('default')['transport']);
    }

    public function testTransportCanBeBuiltFromTheAppConfig(): void
    {
        TransportFactory::drop('sparkpost_test');
        TransportFactory::setConfig('sparkpost_test', ['apiKey' => 'test-key'] + TransportFactory::getConfig('sparkpost'));

        $this->assertInstanceOf(SparkPostTransport::class, TransportFactory::get('sparkpost_test'));
        TransportFactory::drop('sparkpost_test');
    }
}
