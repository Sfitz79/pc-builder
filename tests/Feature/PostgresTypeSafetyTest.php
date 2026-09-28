<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards a class of bug that local development cannot surface.
 *
 * The components.active column is declared via $table->boolean(), which means:
 *   - SQLite (local .env):  stored as tinyint(1), so `where('active', 1)` works
 *   - PostgreSQL (Neon prod): a real bool, so `where('active', 1)` throws
 *     SQLSTATE 42883 "operator does not exist: boolean = integer"
 *
 * Both the local test suite and the local dev server run on SQLite, so the
 * integer form passes every local check and then breaks the live site. Found
 * and fixed 2026-09-28 during the first production pre-flight, where it threw
 * against real Neon data.
 */
class PostgresTypeSafetyTest extends TestCase
{
    /** Directories whose boolean-column queries must be Postgres-safe. */
    private const SOURCE_ROOTS = ['app', 'database/seeders', 'routes'];

    public function test_application_code_never_compares_boolean_columns_to_integers(): void
    {
        $offenders = [];

        foreach (self::SOURCE_ROOTS as $root) {
            $path = base_path($root);
            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                // Boolean columns declared by the schema. Each must be compared
                // with true/false, never 1/0, so the query is valid on Postgres.
                $booleanColumns = ['active', 'is_default', 'featured', 'enabled', 'published'];

                foreach ($booleanColumns as $column) {
                    $whereInteger = '/where\(\s*[\'"]'.$column.'[\'"]\s*,\s*[01]\s*\)/';
                    $arrayInteger = '/[\'"]'.$column.'[\'"]\s*=>\s*[01]\b/';

                    if (preg_match($whereInteger, $contents) || preg_match($arrayInteger, $contents)) {
                        $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "Boolean columns must be compared with true/false, not 1/0.\n"
            ."Postgres stores \$table->boolean() as a real bool and rejects `= 1` with SQLSTATE 42883,\n"
            ."so this passes on local SQLite and breaks production. Offending file(s):\n  - "
            .implode("\n  - ", array_values(array_unique($offenders)))
        );
    }

    public function test_the_integrity_queries_are_boolean_safe(): void
    {
        $service = (string) file_get_contents(base_path('app/Services/PriceIntegrityService.php'));
        $this->assertStringNotContainsString("where('active', 1)", $service);
        $this->assertStringContainsString("where('active', true)", $service);
    }
}
