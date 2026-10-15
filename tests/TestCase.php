<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against a real database. RefreshDatabase wipes the default
     * connection, the survey tests write to the survey connection and the
     * transmission tests to mysql_live, so all three must be the in-memory
     * SQLite set in phpunit.xml. A cached config
     * (bootstrap/cache/config.php) overrides phpunit.xml and points them at
     * MySQL instead - clear it with `php artisan config:clear`.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app['config'];

        // The live server too: transmission tests must never reach the field researchers' database
        foreach ([$config->get('database.default'), 'survey', 'mysql_live'] as $name) {
            $connection = $config->get("database.connections.{$name}");

            if (($connection['driver'] ?? null) !== 'sqlite' || ($connection['database'] ?? null) !== ':memory:') {
                throw new \RuntimeException(
                    "Tests stopped: the '{$name}' connection is not in-memory SQLite, so they would run against a real database. "
                    . ($app->configurationIsCached() ? 'The config is cached - run `php artisan config:clear` first.' : 'Check phpunit.xml.')
                );
            }
        }

        // Each test gets a fresh in-memory survey database - forget what the last one had
        \App\Support\Survey::flush();

        return $app;
    }
}
