<?php

/*
 * Boundaries the package keeps, asserted rather than reviewed.
 */

it('never reaches into the application admin controllers', function () {
    $hits = shell_exec(sprintf(
        'grep -rln %s %s 2>/dev/null',
        escapeshellarg('App\\\\Http\\\\Controllers\\\\Admin'),
        escapeshellarg(base_path('packages/gis/src')),
    ));

    expect(trim((string) $hits))->toBe('');
});

it('is registered from the root autoloader and the app config', function () {
    expect(app()->getProviders(Gis\GisServiceProvider::class))->not->toBeEmpty();
    expect(config('gis.connection'))->toBe('gis');
});

it('keeps raw spatial SQL inside GeometryCast', function () {
    // The axis-order footgun is silent: mix latitude and longitude and the
    // geometry lands in the wrong hemisphere with no error raised. The defence
    // is that exactly one file may call these, so exactly one file has to get
    // the axis order right.
    $forbidden = ['ST_GeomFromText', 'ST_AsText', 'ST_AsBinary', 'ST_GeomFromWKB', 'ST_GeomFromGeoJSON'];

    $offenders = [];

    foreach ($forbidden as $function) {
        $hits = array_filter(explode("\n", (string) shell_exec(sprintf(
            'grep -rl %s %s 2>/dev/null',
            escapeshellarg($function),
            escapeshellarg(base_path('packages/gis/src')),
        ))));

        foreach ($hits as $file) {
            if (! str_ends_with(trim($file), 'src/Casts/GeometryCast.php')) {
                $offenders[] = trim($file).' calls '.$function;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('never writes feature data into the DOM as markup', function () {
    // Imported properties are untrusted; they reach the DOM through textContent
    // or .text(), never .html() or innerHTML (specification section 20).
    $hits = shell_exec(sprintf(
        'grep -rnE %s %s 2>/dev/null',
        escapeshellarg('\\.html\\(|innerHTML'),
        escapeshellarg(base_path('packages/gis/resources/js')),
    ));

    expect(trim((string) $hits))->toBe('');
});

it('never reaches for a Cartesian MySQL spatial function', function () {
    // `ST_Buffer`, `ST_Union`, `ST_Difference`, `ST_Intersection` and
    // `ST_Simplify` are Cartesian-only: on a geographic reference system they
    // either error or return a confidently wrong answer in degrees, and
    // `ST_MakeValid` does not exist in MySQL at all. Every constructive
    // operation goes through `GeometryService` instead (specification §5).
    //
    // This is an architecture test rather than a code review because the
    // shortcut is tempting and its failure is invisible: geometry in the wrong
    // place still draws, still indexes and still exports.
    $forbidden = [
        'ST_Buffer', 'ST_Union', 'ST_Difference', 'ST_Intersection',
        'ST_Simplify', 'ST_MakeValid', 'ST_ConvexHull',
    ];

    $offenders = [];

    foreach ($forbidden as $function) {
        $hits = array_filter(explode("\n", (string) shell_exec(sprintf(
            'grep -rl %s %s 2>/dev/null',
            escapeshellarg($function),
            escapeshellarg(base_path('packages/gis/src')),
        ))));

        foreach ($hits as $file) {
            $offenders[] = trim($file).' calls '.$function;
        }
    }

    expect($offenders)->toBe([]);
});
