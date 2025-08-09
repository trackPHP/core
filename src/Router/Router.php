<?php

namespace TrackPHP\Router;
use TrackPHP\Router\Exceptions\NotFoundException;
use TrackPHP\Router\Exceptions\MethodNotAllowedException;

class Router {

    private array $routes = [
        'GET' => [],
        'POST' => []
    ];
    private array $namedRoutes = [];

    private const HANDLER_REGEX = '/^([a-z][a-z0-9_]*)#([a-z][a-z0-9_]*)$/i';
    private const ALLOWED_VERBS = ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'];

    public function addRoute(string $method, string $pattern, string $handler, ?string $name = null): Route
    {
        $verb = strtoupper($method);
        $this->assertMethodSupported($verb);
        [$controllerWord, $action] = $this->parseHandler($handler);

        $controllerClass = $this->controllerClassFrom($controllerWord);
        $regex = $this->compilePattern($pattern);
        $paramNames = $this->extractParamNames($pattern);

        $route = new Route($verb, $pattern, $regex, $controllerClass, $action, $paramNames, []);

        $routeName = $name ?? $this->defaultNameFor($controllerClass, $action);
        $this->registerRoute($route, $routeName);

        return $route;
    }

    public function match(string $method, string $uri): ?Route
    {
        $method = strtoupper($method);

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route->regexPattern, $uri, $m)) {
                $values = array_map('urldecode', array_slice($m, 1));

                if (count($route->paramNames) !== count($values)) {
                    throw new \RuntimeException("Parameter count mismatch for {$route->pattern}");
                }

                $bound = clone $route;
                $bound->params = array_combine($route->paramNames, $values);
                return $bound;
            }
        }
        return null;
    }

    public function dispatch(string $method, string $uri): string
    {
        $route = $this->findRouteOrFail($method, $uri);

        $controller = $this->instantiateController($route->controller); // e.g. "HomeController"
        $this->assertActionCallable($controller, $route->action);

        // ensure numeric args in order
        $params = array_values($route->params ?? []);

        return $controller->{$route->action}(...$params);
    }

    public function path(string $name, ?array $values = []): string
    {
        $route = $this->getNamedRouteOrFail($name);
        $this->assertAllParamsProvided($route->paramNames, $values, $name);
        return $this->buildPath($route->pattern, $route->paramNames, $values);
    }

    private function assertMethodSupported(string $method): void
    {
        if (!in_array($method, self::ALLOWED_VERBS, true)) {
            throw new \InvalidArgumentException("Unsupported HTTP method: {$method}");
        }
    }

    private function parseHandler(string $handler): array
    {
        if (!preg_match(self::HANDLER_REGEX, $handler, $m)) {
            throw new \InvalidArgumentException(
                "Invalid handler '{$handler}'. Expected 'word#word' like 'home#index'."
            );
        }
        return [$m[1], $m[2]]; // [controllerWord, action]
    }

    private function controllerClassFrom(string $word): string
    {
        return ucfirst($word) . 'Controller';
    }

    private function defaultNameFor(string $controllerClass, string $action): string
    {
        $base = preg_replace('/Controller$/', '', $controllerClass);
        return lcfirst($base) . '.' . $action;
    }

    private function registerRoute(Route $route, string $name): void
    {
        // Don’t silently overwrite named routes
        if (isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Named route already exists: {$name}");
        }

        $this->routes[$route->method][] = $route;
        $this->namedRoutes[$name] = $route;
    }

    private function extractParamNames(string $pattern): array
    {
        preg_match_all('/\{([^\/]+)\}/', $pattern, $matches);
        $paramNames = $matches[1];
        if (count($paramNames) !== count(array_unique($paramNames))) {
            $duplicates = array_diff_assoc($paramNames, array_unique($paramNames));
            $list  = implode(', ', array_unique($duplicates));
            throw new \InvalidArgumentException(
                "Duplicate route parameters not allowed: {$list}"
            );
        }
        return $paramNames; // Returns something like ['postId', 'commentId']
    }

    private function compilePattern(string $pattern): string
    {
        // Escape slashes and convert dynamic segments
        $regexPattern = preg_replace('#\{[^/]+\}#', '([^/]+)', $pattern);
        return '#^' . $regexPattern . '$#';
    }

    private function findRouteOrFail(string $method, string $uri): Route
    {
        $route = $this->match($method, $uri);
        if ($route !== null) {
            return $route;
        }

        // path matches another verb? → 405
        $allowed = $this->allowedMethodsFor($uri);
        if (!empty($allowed)) {
            throw new MethodNotAllowedException(
                "Method {$method} not allowed for {$uri}"
            );
        }

        // nothing matches this path at all → 404
        throw new NotFoundException("No route found for {$method} {$uri}");
    }

    private function getNamedRouteOrFail(string $name): Route
    {
        $route = $this->namedRoutes[$name] ?? null;
        if (!$route) {
            throw new \InvalidArgumentException("No route found with name: {$name}");
        }
        return $route;
    }

    private function allowedMethodsFor(string $uri): array
    {
        $allowed = [];
        foreach ($this->routes as $verb => $routes) {
            foreach ($routes as $r) {
                if (preg_match($r->regexPattern, $uri)) {
                    $allowed[] = $verb;
                    break;
                }
            }
        }
        return array_values(array_unique($allowed));
    }

    private function instantiateController(string $baseName): object
    {
        $class = 'App\\Controllers\\' . $baseName;
        if (!class_exists($class)) {
            throw new \RuntimeException("Controller {$baseName} not found");
        }
        return new $class();
    }

    private function assertActionCallable(object $controller, string $action): void
    {
        if (!is_callable([$controller, $action])) {
            $class = get_class($controller);
            throw new \RuntimeException("Method {$action} not found on {$class}");
        }
    }

    private function assertAllParamsProvided(array $names, array $params, string $routeName): void
    {
        $missing = array_diff($names, array_keys($params));
        if ($missing) {
            throw new \InvalidArgumentException(
                "Missing parameter(s) for route '{$routeName}': " . implode(', ', $missing)
            );
        }
    }

    private function buildPath(string $pattern, array $names, array $params): string
    {
        $map = [];
        foreach ($names as $n) {
            $map['{' . $n . '}'] = rawurlencode((string) $params[$n]);
        }
        return strtr($pattern, $map);
    }
}
