<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'pgsql'
            || $app['config']->get('database.connections.pgsql.database') !== 'tg_promo_test'
            || $app['config']->get('database.connections.pgsql.url')) {
            throw new RuntimeException('Tests require PostgreSQL database tg_promo_test with no DB_URL. Clear the configuration cache before running tests.');
        }

        return $app;
    }
}
