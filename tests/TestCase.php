<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Pharaonic\Laravel\ApiIdempotency\ApiIdempotencyServiceProvider;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestRejected;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReplayed;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReserved;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestUnresolved;
use Pharaonic\Laravel\ApiIdempotency\Stores\Store;
use Pharaonic\Laravel\ApiIdempotency\Stores\Stores;
use Pharaonic\Laravel\ApiIdempotency\Support\Record;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\RouteServiceProvider;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;
use RuntimeException;

/**
 * The fixture application. Its database and Redis come from the environment,
 * so child processes started by spawn() share them with the test:
 *
 * IDEMPOTENCY_TEST_CONNECTION  JSON database connection (default: SQLite in memory)
 * IDEMPOTENCY_TEST_REDIS       JSON "database.redis" configuration
 * IDEMPOTENCY_TEST_CONFIG      JSON map of configuration keys to override
 *
 * Integration tests run against the real servers named by:
 *
 * IDEMPOTENCY_TEST_MYSQL       JSON {"host", "port", "username", "password", "database"}
 * IDEMPOTENCY_TEST_PGSQL       JSON {"host", "port", "username", "password", "database"}
 * IDEMPOTENCY_TEST_REDIS_HOST  "host:port"
 */
abstract class TestCase extends Orchestra
{
    public const START = 1767225600; // 2026-01-01 00:00:00 UTC

    /**
     * Artisan options that run the package migration, which is never loaded until it is published
     */
    public const MIGRATIONS = ['--path' => __DIR__.'/../database/migrations', '--realpath' => true];

    /**
     * Every event the package dispatches
     */
    public const EVENTS = [
        IdempotentRequestReserved::class,
        IdempotentRequestReplayed::class,
        IdempotentRequestRejected::class,
        IdempotentRequestUnresolved::class,
    ];

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ApiIdempotencyServiceProvider::class,
            RouteServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('auth.guards.fixture', ['driver' => 'fixture']);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', self::environment('IDEMPOTENCY_TEST_CONNECTION') ?? [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $redis = self::environment('IDEMPOTENCY_TEST_REDIS');

        if ($redis !== null) {
            $config->set('database.redis', $redis);
        }

        foreach (self::environment('IDEMPOTENCY_TEST_CONFIG') ?? [] as $key => $value) {
            $config->set((string) $key, $value);
        }

        $prefix = getenv('IDEMPOTENCY_TEST_REDIS_PREFIX');
        $config->set('pharaonic.api-idempotency.store.prefix', is_string($prefix) && $prefix !== '' ? $prefix : 'idempotency-test:');
    }

    protected function setUp(): void
    {
        parent::setUp();

        SideEffects::reset();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::START));
        $this->migrate();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        SideEffects::reset();

        parent::tearDown();
    }

    /**
     * Create the package table and the fixture orders table from scratch
     */
    protected function migrate(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('migrations');

        $this->app->make(Kernel::class)->call('migrate', self::MIGRATIONS);

        Schema::create('orders', static function ($table): void {
            $table->increments('id');
            $table->integer('total');
        });
    }

    /**
     * A JSON request with an idempotency key, as user $user (0: anonymous)
     *
     * @param  array<mixed>  $body
     * @param  array<string, string>  $headers
     * @return mixed the framework's TestResponse
     */
    protected function idempotent(string $uri, array $body = [], ?string $key = 'key-1', int $user = 1, array $headers = [], string $method = 'POST')
    {
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        if ($user > 0) {
            $headers['Authorization'] = 'Bearer user-'.$user;
        }

        return $this->json($method, $uri, $body, $headers);
    }

    /**
     * A request with a raw body
     *
     * @param  array<string, string>  $headers
     * @return mixed the framework's TestResponse
     */
    protected function raw(string $uri, string $content, array $headers = [], string $method = 'POST')
    {
        $headers += ['Idempotency-Key' => 'key-1', 'Authorization' => 'Bearer user-1'];

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers), $content);
    }

    /**
     * No route action ran and no key was stored
     */
    protected function assertNothingRan(): void
    {
        $this->assertSame(0, SideEffects::$count);
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    /**
     * The state of the only stored record
     */
    protected function storedState(): ?string
    {
        $record = DB::table('idempotency_keys')->first();

        return $record === null ? null : (string) $record->state;
    }

    /**
     * Send a request through the HTTP kernel from inside another request
     */
    protected function nested(string $uri, string $body, string $key = 'key-1', int $user = 1): \Symfony\Component\HttpFoundation\Response
    {
        $server = $this->transformHeadersToServerVars([
            'Idempotency-Key' => $key,
            'Authorization' => 'Bearer user-'.$user,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        return $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create($uri, 'POST', [], [], [], $server, $body));
    }

    protected function advance(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    /**
     * The backends integration tests run on: SQLite always, the others when configured
     *
     * @return array<string, array{string}>
     */
    public static function backends(): array
    {
        return [
            'sqlite file' => ['sqlite'],
            'mysql' => ['mysql'],
            'pgsql' => ['pgsql'],
            'redis (phpredis)' => ['phpredis'],
            'redis (predis)' => ['predis'],
        ];
    }

    /**
     * Point this application at a backend, or skip the test when it is not available
     *
     * Returns the environment that gives child processes the same backend.
     *
     * @return array<string, string>
     */
    protected function useBackend(string $backend): array
    {
        $database = ['driver' => 'sqlite', 'database' => $this->temporaryFile('sqlite'), 'prefix' => ''];
        touch($database['database']);
        $env = [];

        if ($backend === 'mysql' || $backend === 'pgsql') {
            $server = self::environment('IDEMPOTENCY_TEST_'.strtoupper($backend));

            if ($server === null) {
                $this->markTestSkipped('Set IDEMPOTENCY_TEST_'.strtoupper($backend).' to run against '.$backend.'.');
            }

            if (! extension_loaded('pdo_'.$backend)) {
                $this->markTestSkipped('pdo_'.$backend.' is not installed.');
            }

            $database = $server + ['driver' => $backend, 'charset' => $backend === 'mysql' ? 'utf8mb4' : 'utf8', 'prefix' => ''];
        }

        if ($backend === 'phpredis' || $backend === 'predis') {
            $host = getenv('IDEMPOTENCY_TEST_REDIS_HOST');

            if (! is_string($host) || $host === '') {
                $this->markTestSkipped('Set IDEMPOTENCY_TEST_REDIS_HOST to run against Redis.');
            }

            if ($backend === 'phpredis' && ! extension_loaded('redis')) {
                $this->markTestSkipped('ext-redis is not installed.');
            }

            [$redisHost, $redisPort] = array_pad(explode(':', $host, 2), 2, '6379');
            $redis = ['client' => $backend, 'options' => [], 'default' => ['host' => $redisHost, 'port' => (int) $redisPort, 'database' => 0]];
            $prefix = 'idempotency-test:'.bin2hex(random_bytes(6)).':';

            config(['database.redis' => $redis, 'pharaonic.api-idempotency.store.driver' => 'redis', 'pharaonic.api-idempotency.store.prefix' => $prefix]);
            $this->app->forgetInstance('redis');
            $env += [
                'IDEMPOTENCY_TEST_REDIS' => (string) json_encode($redis),
                'IDEMPOTENCY_TEST_REDIS_PREFIX' => $prefix,
                'IDEMPOTENCY_STORE' => 'redis',
            ];
        }

        config(['database.connections.testing' => $database]);
        $this->app['db']->purge('testing');
        $this->migrate();

        return $env + ['IDEMPOTENCY_TEST_CONNECTION' => (string) json_encode($database)];
    }

    /**
     * A path in the system temporary directory, deleted after the test
     */
    protected function temporaryFile(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'idempotency-'.bin2hex(random_bytes(8)).'.'.$extension;
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $path;
    }

    /**
     * @return array<mixed>|null
     */
    protected static function environment(string $name): ?array
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Run the fixture application in child processes, all at the same moment
     *
     * Each job is ['mode' => 'request', 'method' => ..., 'uri' => ..., 'headers' => [...], 'body' => '...']
     * or ['mode' => 'artisan', 'command' => ..., 'parameters' => [...]], with optional per-job 'env'.
     * Returns each job's decoded output, or ['crashed' => exit code] when it died.
     *
     * @param  list<array<string, mixed>>  $jobs
     * @param  array<string, string>  $env
     * @return list<array<string, mixed>>
     */
    protected static function spawn(array $jobs, array $env = []): array
    {
        $startAt = microtime(true) + 1.5;
        $processes = [];
        $files = [];
        $base = array_merge(getenv(), $env);

        foreach ($jobs as $job) {
            $job['start_at'] = $startAt;
            $jobEnv = array_merge($base, is_array($job['env'] ?? null) ? $job['env'] : []);
            unset($job['env']);

            // The job goes through a file and only its path is an argument, so no
            // platform's argument quoting can change the JSON. register_argc_argv is
            // forced on: Windows runners' php.ini turns it off, leaving no $argv.
            $file = tempnam(sys_get_temp_dir(), 'idempotency-job-');

            if ($file === false || file_put_contents($file, json_encode($job, JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Could not write a child process job.');
            }

            $files[] = $file;

            $process = proc_open(
                [PHP_BINARY, '-d', 'register_argc_argv=1', __DIR__.'/Fixtures/worker.php', $file],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__),
                array_map('strval', $jobEnv),
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start a child process.');
            }

            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            $decoded = json_decode($output, true);

            $results[] = is_array($decoded) ? $decoded : ['crashed' => $code, 'output' => $output, 'errors' => $errors];
        }

        array_map('unlink', $files);

        return $results;
    }

    /**
     * A request job for spawn()
     *
     * @param  array<mixed>|null  $body
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    protected static function job(string $uri, ?array $body, string $key = 'key-1', int $user = 1, array $env = [], string $method = 'POST'): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Idempotency-Key' => $key];

        if ($user > 0) {
            $headers['Authorization'] = 'Bearer user-'.$user;
        }

        return [
            'mode' => 'request',
            'method' => $method,
            'uri' => $uri,
            'headers' => $headers,
            'body' => $body === null ? '' : json_encode($body),
            'env' => $env,
        ];
    }

    /**
     * A completed record on a 600 s route, one on a 24 h route, a failed one and an abandoned one
     */
    protected function seedPruneRecords(): void
    {
        $this->idempotent('/orders/short', ['quantity' => 1], 'a');
        $this->idempotent('/orders', ['quantity' => 1], 'b');
        $this->idempotent('/fail', [], 'c');

        $this->app['events']->listen(\Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReserved::class, static function (): void {
            throw new \RuntimeException('Worker killed.');
        });
        $this->idempotent('/orders', ['quantity' => 1], 'd');
        $this->app['events']->forget(\Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReserved::class);

        $this->assertSame(4, DB::table('idempotency_keys')->count());
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    protected function describeResults(array $results): string
    {
        return (string) json_encode($results, JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, string>
     */
    protected function crashWorker(string $backend, ?string &$ledger): array
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('Killing a worker needs the posix extension.');
        }

        $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log')];

        $killed = self::spawn([self::job('/orders', ['quantity' => 2], 'key-1', 1, ['IDEMPOTENCY_TEST_KILL' => '1'])], $env)[0];

        $this->assertArrayHasKey('crashed', $killed, 'The worker must die before answering.');
        $this->assertSame(['store'], SideEffects::ledger($ledger));

        return $env;
    }

    protected function abandonReservation(): void
    {
        // A worker that dies right after reserving: the route never ran.
        Event::listen(IdempotentRequestReserved::class, static function (): void {
            throw new RuntimeException('Worker killed.');
        });

        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(500);
        $this->assertSame('processing', $this->storedState());
        $this->app['events']->forget(IdempotentRequestReserved::class);
    }

    /**
     * @param  array<string, string>  $server
     */
    protected function jsonRequest(string $body, string $uri = '/orders', string $method = 'POST', array $server = []): Request
    {
        return Request::create($uri, $method, [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    }

    protected function storeFor(string $backend): Store
    {
        $this->useBackend($backend);

        return $this->app->make(Stores::class)->get();
    }

    protected function storeRecord(string $owner, string $fingerprint = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff', string $key = 'key'): Record
    {
        return Record::processing(hash('sha256', $key), $fingerprint, $owner, self::START, 120, 600, 'POST', '/orders');
    }

    protected function middlewareRequest(): Request
    {
        $request = Request::create('http://localhost/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k'], '{"a":1}');
        $request->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 1]));

        return $request;
    }
}
