<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationIdentifierCompatibilityTest extends TestCase
{
    private const FORWARD_ONLY = '2026_10_02_094522_enforce_exact_technical_identifier_collations.php';

    public function test_complete_migration_chain_has_portable_identifiers_and_reversible_index_names(): void
    {
        $connection = new class(null, 'static_only', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection
        {
            public function selectOne($query, $bindings = [], $useReadPdo = true): object
            {
                if ($query !== "SHOW COLLATION WHERE Collation = 'utf8mb4_nopad_bin'" || $bindings !== []) {
                    throw new \LogicException('Static migration audit forbids unexpected database queries.');
                }

                return (object) ['Collation' => 'utf8mb4_nopad_bin'];
            }

            public function affectingStatement($query, $bindings = []): int
            {
                $reviewedPermissions = ['employee.payroll.view', 'payroll.release',
                    'employee.attendance.view', 'employee.attendance.clock', 'employee.attendance.correction.request',
                    'attendance.view', 'attendance.manage', 'attendance.corrections.manage',
                    'employee.leave.view', 'employee.leave.request', 'employee.leave.cancel',
                    'leave.view', 'leave.manage', 'leave.approve', 'holiday.view', 'holiday.manage',
                    'employee.tasks.view', 'employee.tasks.update', 'employee.tasks.comment',
                    'employee.tickets.view', 'employee.tickets.create', 'employee.tickets.comment',
                    'tasks.view', 'tasks.manage', 'tasks.assign', 'tickets.view', 'tickets.manage'];
                $expectedPermissionCount = match (true) {
                    in_array('payroll.release', $bindings, true) => 2,
                    in_array('employee.leave.view', $bindings, true) => 8,
                    in_array('employee.tasks.view', $bindings, true) => 11,
                    default => 6,
                };
                if (! str_starts_with($query, 'insert ignore into `permissions`')
                    || count(array_intersect($reviewedPermissions, $bindings)) !== $expectedPermissionCount) {
                    throw new \LogicException('Static migration audit forbids unexpected database writes.');
                }

                return $expectedPermissionCount;
            }

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
        DB::swap($connection);
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
            $migration = require $file;
            if (basename($file) === self::FORWARD_ONLY) {
                $this->assertSame(['mysql', 'mariadb'], $migration::FORWARD_ONLY_DRIVERS);
            } else {
                $this->assertFalse(defined(get_class($migration).'::FORWARD_ONLY_DRIVERS'), 'New forward-only migrations require explicit compatibility review.');
            }
            $migration->up();
        }
        $builder->phase = 'down';
        foreach (array_reverse($files) as $file) {
            $builder->migration = basename($file);
            if (basename($file) !== self::FORWARD_ONLY) {
                (require $file)->down();

                continue;
            }
            $before = count($builder->operations);
            try {
                (require $file)->down();
                $this->fail('The approved forward-only migration must refuse rollback.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(\RuntimeException::class, get_class($exception));
                $this->assertSame('Exact identifier identity cannot be safely collapsed by rollback. Use a reviewed forward migration.', $exception->getMessage());
            }
            $this->assertCount($before, $builder->operations, 'Refused rollback must emit no schema changes.');
        }
        $changed = 0;
        foreach ($builder->operations as $operation) {
            if ($operation['migration'] !== self::FORWARD_ONLY || $operation['phase'] !== 'up') {
                continue;
            }
            foreach ($operation['columns'] as $column) {
                $this->assertTrue($column['change']);
                $this->assertSame('utf8mb4_nopad_bin', $column['collation']);
                $changed++;
            }
            foreach ($operation['sql'] as $sql) {
                $this->assertStringContainsString('utf8mb4_nopad_bin', $sql);
            }
        }
        $this->assertSame(52, $changed, 'All reviewed exact-identity columns must compile in the MariaDB audit, even on SQLite.');
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
