<?php

declare(strict_types=1);

use Happyr\DoctrineSpecification\Repository\EntitySpecificationRepository;

use function Functional\contains;
use function Shlinkio\Shlink\Config\env;

return (static function (): array {
    $driver = env('DB_DRIVER');
    $isMysqlCompatible = contains(['maria', 'mysql'], $driver);

    $resolveDriver = static fn () => match ($driver) {
        'postgres' => 'pdo_pgsql',
        'mssql' => 'pdo_sqlsrv',
        default => 'pdo_mysql',
    };
    $resolveDefaultPort = static fn () => match ($driver) {
        'postgres' => '5432',
        'mssql' => '1433',
        default => '3306',
    };
    $resolveCharset = static fn () => match ($driver) {
        // This does not determine charsets or collations in tables or columns, but the charset used in the data
        // flowing in the connection, so it has to match what has been set in the database.
        'maria', 'mysql' => 'utf8mb4',
        'postgres' => 'utf8',
        default => null,
    };
    $resolveConnection = static fn () => match ($driver) {
        null, 'sqlite' => [
            'driver' => 'pdo_sqlite',
            'path' => 'data/database.sqlite',
        ],
        default => [
            'driver' => $resolveDriver(),
            'dbname' => env('DB_NAME', 'shlink'),
            'user' => env('DB_USER'),
            'password' => env('DB_PASSWORD'),
            'host' => env('DB_HOST', $driver === 'postgres' ? env('DB_UNIX_SOCKET') : null),
            'port' => env('DB_PORT', $resolveDefaultPort()),
            'unix_socket' => $isMysqlCompatible ? env('DB_UNIX_SOCKET') : null,
            'charset' => $resolveCharset(),
        ],
    };

    return [

        'entity_manager' => [
            'orm' => [
                'proxies_dir' => 'data/proxies',
                'load_mappings_using_functional_style' => true,
                'default_repository_classname' => EntitySpecificationRepository::class,
            ],
            'connection' => $resolveConnection(),
        ],

    ];
})();
