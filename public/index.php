<?php

require __DIR__ . '/../vendor/autoload.php';

$router = require __DIR__ . '/../config/routes.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

try {
    $response = $router->dispatch($method, $uri);
    echo $response;
} catch (\TrackPHP\Exceptions\NotFoundException $e) {
    http_response_code(404);
    echo '<h1>404 Not Found</h1>';
} catch (\TrackPHP\Exceptions\MethodNotAllowedException $e) {
    http_response_code(405);
    echo '<h1>405 Method Not Allowed</h1>';
}
