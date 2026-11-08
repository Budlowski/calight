<?php

declare(strict_types=1);

use App\Application\Bootstrap;
use App\Http\Request;
use App\Http\Session;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Abhaengigkeiten fehlen. Bitte im Container 'composer install' ausfuehren.\n";
    exit;
}

require $root . '/vendor/autoload.php';

try {
    $bootstrap = Bootstrap::create($root);
    Session::start($bootstrap->config->sessionName, $bootstrap->config->secureCookies);
    $bootstrap->application($root)->handle(Request::fromGlobals())->send();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Die Anwendung kann derzeit nicht gestartet werden. Bitte Serverprotokoll pruefen.\n";
}
