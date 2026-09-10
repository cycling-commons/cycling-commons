<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Refuse to run the suite against anything but a *_test database. Real env
// vars beat every .env* file, so inside the dev container the compose-provided
// DATABASE_URL (dev DB) would silently win over .env.test — this guard turns
// that mistake into a hard stop instead of test writes against dev data.
$dbUrl = $_SERVER['DATABASE_URL'] ?? '';
$dbName = ltrim((string) parse_url($dbUrl, \PHP_URL_PATH), '/');
if (!str_ends_with($dbName, '_test')) {
    fwrite(\STDERR, sprintf(
        "Refusing to run tests: DATABASE_URL points at \"%s\", not a *_test database.\n".
        "Inside the dev container, override it explicitly, e.g.:\n".
        "  docker exec -e DATABASE_URL=\"postgresql://cc:cc@db:5432/cyclingcommons_test?serverVersion=18&charset=utf8\" cycling-commons-dev-app-1 php bin/phpunit\n",
        '' === $dbName ? '(unset)' : $dbName,
    ));
    exit(1);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
