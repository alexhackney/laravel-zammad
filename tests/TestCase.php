<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Tests;

use AlexHackney\Zammad\ZammadServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AlexHackney\\Zammad\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            ZammadServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        // Fake endpoint: tests never reach a real Zammad (see tests/Pest.php).
        $app['config']->set('zammad.url', 'https://zammad.test');
        $app['config']->set('zammad.token', 'test-token');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
