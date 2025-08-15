<?php
declare(strict_types=1);

namespace TrackPHP\Http;

use TrackPHP\Router\Router;
use TrackPHP\View\ViewRenderer;
use TrackPHP\Router\Exceptions\NotFoundException;
use TrackPHP\Router\Exceptions\MethodNotAllowedException;

final class Dispatcher
{
    public function __construct(
        private Router $router,
        private ViewRenderer $viewRenderer,
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

        $controller = new $class($request, $this->viewRenderer);
        $action = $route->action;

        if (!method_exists($controller, $action)) {
            return (new Response(500))->withBody("Action not found: {$class}::{$action}");
        }

        $result = $controller->{$action}();

        if ($result instanceof Response) {
            return $result;
        }
        if ($controller->hasPerformed()) {
            $performed = $controller->performedResponse();
            return $performed;
        }

        $viewName = $this->snake($route->controller) . DIRECTORY_SEPARATOR . $action;
        return $controller->render($viewName);
    }

    private function snake(string $s): string
    {
        // Insert underscore before any uppercase letter that's not at the start
        $s = preg_replace('/(?<!^)[A-Z]/', '_$0', $s);

        // Lowercase the whole thing
        return strtolower((string)$s);
    }

}
