<?php

/*
 * The connection this package's schema lives on, registered by
 * GisServiceProvider into `database.connections` unless the deployment has
 * already defined one under the same name.
 *
 * The GIS schema sits in its own database — 1.4 million cadastral rows have no
 * business beside the application's tables, and the package is meant to be
 * reusable across projects. Nothing here references `users` by foreign key;
 * that would cross a database boundary, so ownership columns are plain
 * integers validated in the application layer.
 */

return [
    'driver' => 'mysql',
    'host' => env('GIS_DB_HOST', env('DB_HOST', '127.0.0.1')),
    'port' => env('GIS_DB_PORT', env('DB_PORT', '3306')),
    'database' => env('GIS_DB_DATABASE', 'webgis'),
    'username' => env('GIS_DB_USERNAME', env('DB_USERNAME', 'forge')),
    'password' => env('GIS_DB_PASSWORD', env('DB_PASSWORD', '')),
    'unix_socket' => env('GIS_DB_SOCKET', env('DB_SOCKET', '')),
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
