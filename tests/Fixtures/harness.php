<?php

/*
 * Runs one job of TestCase::spawn(), read as JSON from stdin, in its own PHP
 * process and prints its result as JSON: a request through the HTTP kernel,
 * an Artisan command, or an inspection of the registered routes.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\FreshRouteCacheCommand;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\Harness;

require __DIR__.'/../../vendor/autoload.php';

$job = json_decode((string) stream_get_contents(STDIN), true);

if (! is_array($job)) {
    fwrite(STDERR, "No job given.\n");
    exit(2);
}

$now = getenv('IDEMPOTENCY_TEST_NOW');
Carbon::setTestNow(Carbon::createFromTimestamp(is_string($now) && $now !== '' ? (int) $now : Harness::START));

$app = (new Harness('harness'))->createApplication();

while (microtime(true) < (float) ($job['start_at'] ?? 0)) {
    usleep(500);
}

switch ($job['mode']) {
    case 'route:cache':
        // Replaces the framework's route:cache in this process's Artisan.
        $kernel = $app->make(ConsoleKernel::class);
        $kernel->registerCommand(tap(new FreshRouteCacheCommand($app['files']))->setLaravel($app));
        $status = $kernel->call('route:cache');

        echo json_encode(['status' => $status, 'output' => $kernel->output(), 'file' => $app->getCachedRoutesPath()]);

        break;

    case 'artisan':
        $kernel = $app->make(ConsoleKernel::class);
        $status = $kernel->call($job['command'], $job['parameters'] ?? []);

        echo json_encode(['status' => $status, 'output' => $kernel->output()]);

        break;

    case 'routes':
        $routes = [];

        foreach ($app['router']->getRoutes() as $route) {
            /** @var Route $route */
            if (str_starts_with($route->uri(), '_')) {
                continue; // Testbench's own routes
            }

            // From Laravel 7, route:cache names unnamed routes "generated::...".
            $name = $route->getName();

            $routes[implode('|', $route->methods()).' '.$route->uri()] = [
                'name' => is_string($name) && str_starts_with($name, 'generated::') ? null : $name,
                'middleware' => $route->gatherMiddleware(),
            ];
        }

        ksort($routes);

        echo json_encode(['cached' => $app->routesAreCached(), 'routes' => $routes]);

        break;

    case 'request':
        $server = [];

        foreach ($job['headers'] ?? [] as $name => $value) {
            $name = strtoupper(str_replace('-', '_', $name));
            $server[in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $name : 'HTTP_'.$name] = $value;
        }

        $request = Request::create($job['uri'], $job['method'], [], [], [], $server, $job['body'] ?? '');
        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);

        echo json_encode([
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'body' => $response->getContent(),
            'cached' => $app->routesAreCached(),
        ]);

        $kernel->terminate($request, $response);

        break;
}
