<?php

/*
 * The connections this package uses, keyed by role. GisServiceProvider
 * registers each one into `database.connections` under the name configured in
 * `config/gis.php`, unless the deployment has already defined one there.
 *
 * `gis` is where the package's own schema lives — its own database, because 3.6
 * million cadastral and land-use rows have no business beside the application's
 * tables, and the package is meant to be reusable across projects. Nothing in
 * it references `users` by foreign key; that would cross a database boundary,
 * so ownership columns are plain integers validated in the application layer.
 */

$common = [
    'driver' => 'mysql',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
];

return [

    'gis' => array_merge($common, [
        'host' => env('GIS_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('GIS_DB_PORT', env('DB_PORT', '3306')),
        'database' => env('GIS_DB_DATABASE', 'webgis'),
        'username' => env('GIS_DB_USERNAME', env('DB_USERNAME', 'forge')),
        'password' => env('GIS_DB_PASSWORD', env('DB_PASSWORD', '')),
        'unix_socket' => env('GIS_DB_SOCKET', env('DB_SOCKET', '')),
    ]),

];
