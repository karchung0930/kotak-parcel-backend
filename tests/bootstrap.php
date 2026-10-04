<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;

require __DIR__.'/../vendor/autoload.php';

/*
 * The tests empty and migrate their database. Check once, before the first
 * test, that it is the test database from phpunit.xml and that it answers,
 * so a mistake gives one clear message instead of a failure for every test
 * or an emptied development database.
 */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$stop = function (string $problem, string $hint): never {
    fwrite(STDERR, "\n{$problem}\n\n{$hint}\nSee docs/local-development.md.\n\n");

    exit(1);
};

if ($app->configurationIsCached()) {
    $stop(
        'The config is cached, so the tests would ignore phpunit.xml and use the development database.',
        'Run `php artisan config:clear` first; `composer test` does this for you.',
    );
}

$connection = $app->make('db')->connection();
$database = (string) $connection->getDatabaseName();
$target = sprintf(
    '%s: %s on %s:%s',
    $connection->getName(),
    $database,
    $connection->getConfig('host') ?? '?',
    $connection->getConfig('port') ?? '?',
);

if (! str_ends_with($database, '_testing')) {
    $stop(
        "The tests empty their database, and this one is not a test database ({$target}).",
        'They only run on a database whose name ends in _testing (kotak_testing, set in phpunit.xml). Check DB_URL in your environment.',
    );
}

try {
    $connection->getPdo();
} catch (Throwable $e) {
    preg_match('/\[(\d{4})\]/', $e->getMessage(), $code);

    $stop(
        "The tests cannot reach their database ({$target}).\n{$e->getMessage()}",
        match ($code[1] ?? '') {
            '1044', '1045', '1049' => "MySQL is running but refused the login or has no {$database} database.\n".
                'Check that DB_USERNAME and DB_PASSWORD in .env match .env.example, and the values the Docker volume was created with.',
            default => 'Start MySQL with `docker compose up -d --wait`.',
        },
    );
}

// Every test boots its own application, so leave nothing of this one behind.
$connection->disconnect();
$app->flush();
HandleExceptions::flushState();
