<?php

namespace Tests\Feature\Acceleration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class AccelerationTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app->detectEnvironment(fn () => 'testing');
            $app['config']->set([
                'app.env' => 'testing',
                'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
                'app.debug' => false,
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null,
                'cache.default' => 'array',
                'cache.stores.redis' => ['driver' => 'array'],
                'queue.default' => 'sync',
                'session.driver' => 'array',
                'mail.default' => 'array',
                'logging.default' => 'null',
                'debugbar.enabled' => false,
            ]);
        });
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        $this->withoutMiddleware(\App\Http\Middleware\InitializePlugins::class);
    }
}
