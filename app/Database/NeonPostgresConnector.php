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
}
