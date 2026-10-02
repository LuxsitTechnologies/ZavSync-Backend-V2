<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationIdentifierCompatibilityTest extends TestCase
{
    public function test_complete_migration_chain_has_portable_identifiers_and_reversible_index_names(): void
    {
        $connection = new class(null, 'static_only', '', ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection
        {
            public function isMaria(): bool
            {
                return true;
            }

            public function getServerVersion(): string
            {
                return '11.8.9';
            }

            public function getPdo(): never
            {
                throw new \LogicException('Static migration audit must never connect to a database.');
            }
        };
        $connection->useDefaultSchemaGrammar();
        $builder = new class($connection) extends MySqlBuilder
        {
            public string $migration = '';

            public string $phase = 'up';

            public array $operations = [];

            protected function build(Blueprint $blueprint): void
            {
                $sql = $blueprint->toSql();
                $this->operations[] = [
                    'migration' => $this->migration, 'phase' => $this->phase, 'table' => $blueprint->getTable(),
                    'commands' => array_map(fn ($command): array => $command->toArray(), $blueprint->getCommands()),
                    'columns' => array_map(fn ($column): array => $column->toArray(), $blueprint->getColumns()), 'sql' => $sql,
                ];
            }
        };
        Schema::swap($builder);
        $files = glob(database_path('migrations/*.php'));
        sort($files);
        foreach ($files as $file) {
            $builder->migration = basename($file);
            (require $file)->up();
        }
        $builder->phase = 'down';
        foreach (array_reverse($files) as $file) {
            $builder->migration = basename($file);
            (require $file)->down();
        }
        if (getenv('MIGRATION_IDENTIFIER_AUDIT') === '1') {
            fwrite(STDOUT, "\nMIGRATION_AUDIT=".json_encode($builder->operations, JSON_THROW_ON_ERROR)."\n");
        }
        $violations = [];
        $indexes = [];
        $foreignNames = [];
        foreach ($builder->operations as $operation) {
            $table = $operation['table'];
            foreach ($operation['commands'] as $command) {
                $type = $command['name'];
                $name = $command['index'] ?? null;
                if (! is_string($name)) {
                    continue;
                }
                if (strlen($name) > 64) {
                    $violations[] = $operation['migration'].': '.$table.' '.$type.' '.$name.' ('.strlen($name).')';
                }
                if ($operation['phase'] === 'up' && in_array($type, ['index', 'unique', 'primary', 'foreign', 'fulltext', 'spatialIndex'], true)) {
                    $key = $table.':'.($type === 'foreign' ? 'foreign:' : 'index:').strtolower($name);
                    if (isset($indexes[$key])) {
                        $violations[] = 'Duplicate name: '.$key;
                    }
                    $indexes[$key] = true;
                    if ($type === 'foreign') {
                        if (isset($foreignNames[strtolower($name)])) {
                            $violations[] = 'Duplicate schema foreign key: '.$name;
                        }
                        $foreignNames[strtolower($name)] = true;
                    }
                }
                if ($operation['phase'] === 'down' && in_array($type, ['dropIndex', 'dropUnique', 'dropForeign', 'dropPrimary'], true)) {
                    $key = $table.':'.($type === 'dropForeign' ? 'foreign:' : 'index:').strtolower($name);
                    if (! isset($indexes[$key])) {
                        $violations[] = 'Rollback references unknown index: '.$key;
                    }
                }
            }
        }
        $this->assertNotEmpty($indexes);
        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
