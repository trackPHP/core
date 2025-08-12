<?php
declare(strict_types=1);

namespace TrackPHP\Http;

use TrackPHP\Router\Router;
use TrackPHP\Router\Exceptions\NotFoundException;
use TrackPHP\Router\Exceptions\MethodNotAllowedException;

final class Dispatcher
{
    public function __construct(
        private Router $router,
        // App controllers live here by convention (blueprint can override)
        private string $controllerNamespace = '\\App\\Controllers'
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->match($request->method(), $request->path());
        } catch (NotFoundException) {
            return (new Response(404))->withBody('404 Not Found');
        } catch (MethodNotAllowedException $e) {
            $res = new Response(405);
            // If your exception exposes allowed methods, set Allow header
            if (method_exists($e, 'allowed')) {
                /** @var array|string[] $allowed */
                $allowed = $e->allowed();
                $res = $res->withHeader('Allow', implode(', ', $allowed));
            }
            return $res->withBody('405 Method Not Allowed');
        }

        $params = $route->params ?? [];
        $request = $request->withRouteParams($params);

        $class = rtrim($this->controllerNamespace, '\\') . '\\' . $route->controller;

        if (!class_exists($class)) {
            return (new Response(500))->withBody("Controller not found: {$class}");
        }

        $controller = new $class($request);
        $action = $route->action;

        // Set names for implicit render: app/views/{_controller}/{_action}.php
        $controller->_controller = $this->snake($route->controller);
        $controller->_action     = $action;

        if (!method_exists($controller, $action)) {
            return (new Response(500))->withBody("Action not found: {$class}::{$action}");
        }

        $controller->{$action}();
        return $controller->render();
    }

    private function snake(string $s): string
    {
        // Insert underscore before any uppercase letter that's not at the start
        $s = preg_replace('/(?<!^)[A-Z]/', '_$0', $s);

        // Lowercase the whole thing
        return strtolower((string)$s);
    }

}
