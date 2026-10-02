<?php

namespace Tests\Feature;

use App\Models\EmailProviderConnection;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

class EncryptedStorageCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_encrypted_model_attribute_has_ciphertext_compatible_storage(): void
    {
        $connection = new MySqlConnection(null, 'static_only');
        $connection->useDefaultSchemaGrammar();
        $columns = [];
        $migrationName = '';
        $capture = function (string $table, Closure $callback) use ($connection, &$columns, &$migrationName): void {
            $blueprint = new Blueprint($connection, $table, $callback);
            foreach ($blueprint->getColumns() as $column) {
                $columns[$table][$column->name] = ['type' => $column->type, 'migration' => $migrationName];
            }
        };
        Schema::shouldReceive('create')->andReturnUsing($capture);
        Schema::shouldReceive('table')->andReturnUsing($capture);
        $migrations = glob(database_path('migrations/*.php'));
        sort($migrations);
        foreach ($migrations as $migration) {
            $migrationName = basename($migration);
            (require $migration)->up();
        }
        $audit = [];
        $violations = [];
        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            if (! is_subclass_of($class, Model::class) || ! (new \ReflectionClass($class))->isInstantiable()) {
                continue;
            }
            $model = new $class;
            foreach ($model->getCasts() as $field => $cast) {
                if (! str_starts_with($cast, 'encrypted') && ! str_contains($cast, 'AsEncrypted')) {
                    continue;
                }
                $definition = $columns[$model->getTable()][$field] ?? ['type' => 'MISSING', 'migration' => 'MISSING'];
                $audit[] = ['model' => $class, 'table' => $model->getTable(), 'column' => $field, 'cast' => $cast, ...$definition];
                if (! in_array($definition['type'], ['text', 'mediumText', 'longText'], true)) {
                    $violations[] = $class.'.'.$field.' uses '.$cast.' on '.$definition['type'];
                }
            }
        }
        if (getenv('ENCRYPTED_STORAGE_AUDIT') === '1') {
            fwrite(STDOUT, "\nENCRYPTED_AUDIT=".json_encode($audit, JSON_THROW_ON_ERROR)."\n");
        }
        $this->assertNotEmpty($audit);
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_factory_encrypted_configuration_and_credentials_round_trip_without_plaintext_storage(): void
    {
        $configuration = ['host' => 'smtp.example.test', 'port' => 587, 'nested' => ['value' => 'fixture-private-configuration']];
        $credentials = ['password' => 'fixture-private-password'];
        $provider = EmailProviderConnection::factory()->create(['configuration' => $configuration, 'credentials' => $credentials]);

        $fresh = $provider->fresh();
        $this->assertSame($configuration, $fresh->configuration);
        $this->assertSame($credentials, $fresh->credentials);
        foreach (['configuration' => 'fixture-private-configuration', 'credentials' => 'fixture-private-password'] as $column => $marker) {
            $raw = DB::table('email_provider_connections')->where('id', $provider->id)->value($column);
            $this->assertIsString($raw);
            $this->assertStringNotContainsString($marker, $raw);
            $this->assertFalse(json_validate($raw), 'Encrypted storage contains opaque ciphertext, not database-native JSON.');
            $this->assertArrayNotHasKey($column, $fresh->toArray());
        }
        $provider->update(['configuration' => null, 'credentials' => null]);
        $this->assertNull($provider->fresh()->configuration);
        $this->assertNull($provider->fresh()->credentials);
    }

    public function test_provider_creation_keeps_secrets_out_of_api_audit_and_application_logs(): void
    {
        $context = $this->stage11OutreachContext();
        Http::preventStrayRequests();
        $handler = new TestHandler;
        Log::swap(new Logger(new MonologLogger('encrypted-storage-test', [$handler])));
        $response = $this->postJson('/api/v1/outreach/providers', [
            'name' => 'Storage security fixture', 'provider_type' => 'SMTP',
            'configuration' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'private-marker@example.test'],
            'credentials' => ['password' => 'fixture-password-marker'],
        ], ['X-Company-Id' => $context['company']->id])->assertCreated();
        $audit = DB::table('audit_logs')->where('entity_id', $response->json('id'))->get();
        $this->assertNotEmpty($audit);
        $logs = array_map(fn ($record): array => $record->toArray(), $handler->getRecords());
        foreach (['fixture-password-marker', 'private-marker@example.test'] as $marker) {
            $this->assertStringNotContainsString($marker, $response->getContent());
            $this->assertStringNotContainsString($marker, $audit->toJson());
            $this->assertStringNotContainsString($marker, json_encode($logs, JSON_THROW_ON_ERROR));
        }
    }

    public function test_development_seed_persists_demo_smtp_using_encrypted_model_casts(): void
    {
        Http::preventStrayRequests();
        $this->seed();
        $provider = EmailProviderConnection::query()->where('name', 'Demo SMTP')->sole();

        $this->assertSame('DISCONNECTED', $provider->status);
        $this->assertSame('smtp.example.test', $provider->configuration['host']);
        $this->assertSame('test-secret', $provider->credentials['password']);
        $this->assertStringNotContainsString('smtp.example.test', $provider->getRawOriginal('configuration'));
        $this->assertStringNotContainsString('test-secret', $provider->getRawOriginal('credentials'));
    }
}
