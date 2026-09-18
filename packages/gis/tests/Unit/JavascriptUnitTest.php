<?php

use Symfony\Component\Process\Process;

/**
 * The client-side geometry code is pure arithmetic over typed arrays, and it
 * is where the axis-order footgun could be reintroduced on the browser side.
 * It is tested with Node's built-in runner and asserted from here, so the suite
 * still has one entry point and the repository gains no second test framework.
 */
it('passes the client-side geometry unit tests', function () {
    $process = new Process(
        ['node', '--test', 'packages/gis/tests/js/*.test.mjs'],
        base_path(),
        timeout: 120,
    );

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput());

    expect($process->getOutput())->toContain('fail 0');
})->skip(fn () => ! file_exists(base_path('node_modules')), 'node_modules not installed');
