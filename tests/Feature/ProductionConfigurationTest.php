<?php

use Symfony\Component\Process\Process;

test('production hides unexpected error details even if debug was enabled in the environment', function () {
    $script = <<<'PHP_SCRIPT'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();

        $app['router']->get('/audit-production-error', function () {
            throw new RuntimeException('Synthetic sensitive error details.');
        });

        $request = Illuminate\Http\Request::create('/audit-production-error', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $kernel->handle($request);
        echo $response->getStatusCode(), "\n", $response->getContent();
        PHP_SCRIPT;

    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'true',
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/promptedit-test-'.bin2hex(random_bytes(8)).'.php',
        'LOG_CHANNEL' => 'null',
    ]);

    $process->mustRun();

    expect($process->getOutput())->toStartWith("500\n")
        ->toContain('Server Error')
        ->not->toContain('Synthetic sensitive error details.')
        ->not->toContain('"trace"');
});
