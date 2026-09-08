<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;

class NeonPostgresConnector extends PostgresConnector
{
    /**
     * Neon requires older libpq clients (without SNI support) to identify the
     * endpoint explicitly via the `options` connection parameter.
     */
    protected function getDsn(array $config)
    {
        $dsn = parent::getDsn($config);

        if (! empty($config['neon_endpoint'])) {
            $dsn .= ";options='endpoint={$config['neon_endpoint']}'";
        }

        return $dsn;
    }

    /**
     * Neon's pooled connections fail with "cached plan must not change result
     * type" (SQLSTATE 0A000) once the schema changes (e.g. migration adds
     * columns) because server-side prepared statement plans are cached in the
     * pooler. Disabling server-side prepares makes every query use fresh
     * simple-protocol plans and eliminates the stale-plan failures entirely.
     */
    protected function createPdoConnection($dsn, $username, $password, $options)
    {
        if (defined('PDO::PGSQL_ATTR_DISABLE_PREPARES')) {
            $options[\PDO::PGSQL_ATTR_DISABLE_PREPARES] = true;
        }

        return parent::createPdoConnection($dsn, $username, $password, $options);
    }
}
