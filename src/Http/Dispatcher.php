<?php

declare(strict_types=1);

namespace TrackPHP\Http;

use TrackPHP\Http\Response;
use TrackPHP\View\ViewRenderer;
use TrackPHP\Router\{Route, Router, RouteMatch};
use TrackPHP\Router\Exceptions\{NotFoundException, MethodNotAllowedException};

class Dispatcher
{
    public function __construct(
        private Router $router,
        private ViewRenderer $viewRenderer,
        private string $controllerNamespace = '\\App\\Controllers'
    ) {
    }

    public function handle(Request $request): Response
    {
        $match = $this->matchRoute($request);
        if ($match instanceof Response) {
            return $match;
        }

        $request = $request->withRouteParams($match->params ?? []);

        $controller = $this->resolveController($match->route, $request);
        if ($controller instanceof Response) {
            return $controller;
        }

        $result = $this->invokeAction($controller, $match->route->action);
        if ($result instanceof Response) {
            return $result;
        }

        // Fallback: auto-render the conventional view
        return $this->renderDefault($controller, $match->route);
    }

    private function matchRoute(Request $request): RouteMatch|Response
    {
        try {
            return $this->router->match($request->method(), $request->path());
        } catch (NotFoundException) {
            return (new Response(404))->withBody('404 Not Found');
        } catch (MethodNotAllowedException $e) {
            $allowed = method_exists($e, 'allowed') ? array_map('strtoupper', (array) $e->allowed()) : [];
            $res = new Response(405);
            if ($allowed) {
                $res = $res->withHeader('Allow', implode(', ', $allowed));
            }
            return $res->withBody('405 Method Not Allowed');
        }
    }

    private function resolveController(Route $route, Request $request): Controller|Response
    {
        $class = rtrim($this->controllerNamespace, '\\') . '\\' . $route->controller;
        if (!class_exists($class)) {
            return (new Response(500))->withBody("Controller not found: {$class}");
        }

        $controller = new $class($request, $this->viewRenderer);
        return $controller;
    }

    private function invokeAction(Controller $controller, string $action): ?Response
    {
        if (!method_exists($controller, $action)) {
            return (new Response(500))->withBody(sprintf(
                'Action not found: %s::%s',
                $controller::class,
                $action
            ));
        }

        $result = $controller->{$action}();

        if ($result instanceof Response) {
            return $result;
        }

        return $result; // will trigger default render
    }

    private function renderDefault(Controller $controller, Route $route): Response
    {
        $viewName = $this->snake($route->controller) . DIRECTORY_SEPARATOR . $route->action;
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
