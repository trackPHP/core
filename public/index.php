<?php
declare(strict_types=1);

use TrackPHP\Http\Request;
use TrackPHP\Http\Dispatcher;
use TrackPHP\Router\Router;

require __DIR__ . '/../vendor/autoload.php';
define('TRACKPHP_APP_PATH', dirname(__DIR__));
define('TRACKPHP_VIEW_PATH', TRACKPHP_APP_PATH . '/app/views');

$router = new Router();
$router->loadRoutes(__DIR__ . '/../config/routes.txt');
$request = Request::capture();
$dispatcher = new Dispatcher($router, '\\App\\Controllers');
$response   = $dispatcher->handle($request);
http_response_code($response->status());
foreach ($response->headers() as $name => $value) {
    header($name . ': ' . $value, true);
}
echo $response->body();
