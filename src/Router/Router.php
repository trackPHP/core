<?php

namespace TrackPHP\Router;

use TrackPHP\Router\Exceptions\NotFoundException;
use TrackPHP\Router\Exceptions\MethodNotAllowedException;

class Router
{
    private array $routes = [
        'GET' => [],
        'POST' => [],
        'PATCH' => [],
        'DELETE' => []
    ];
    private array $namedRoutes = [];

    private const HANDLER_REGEX = '/^([a-z][a-z0-9_]*)#([a-z][a-z0-9_]*)$/i';
    private const ALLOWED_VERBS = ['GET','POST','PATCH','DELETE'];

    public function addRoute(string $method, string $pattern, string $handler, ?string $name = null): Route
    {
        $verb = strtoupper($method);
        $this->assertMethodSupported($verb);
        [$controllerWord, $action] = $this->parseHandler($handler);

        $controllerClass = $this->controllerClassFrom($controllerWord);
        $regex = $this->compilePattern($pattern);
        $paramNames = $this->extractParamNames($pattern);

        $route = new Route($verb, $pattern, $regex, $controllerClass, $action, $paramNames);

        $routeName = $name ?? $this->defaultNameFor($controllerWord, $action);
        $this->registerRoute($route, $routeName);

        return $route;
    }

    public function path(string $name, ?array $values = []): string
    {
        $route = $this->getNamedRouteOrFail($name);
        $this->assertAllParamsProvided($route->paramNames, $values, $name);
        return $this->buildPath($route->pattern, $route->paramNames, $values);
    }

    public function match(string $method, string $uri): RouteMatch
    {
        $match = $this->resolve($method, $uri);
        if ($match !== null) {
            return $match;
        }

        // path matches another verb? → 405
        $allowed = $this->allowedMethodsFor($uri);
        if (!empty($allowed)) {
            throw new MethodNotAllowedException($method, $uri, $allowed);
        }

        // nothing matches this path at all → 404
        throw new NotFoundException("No route found for {$method} {$uri}");
    }

    public function loadRoutes(string $file): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split by whitespace into max 4 parts
            $parts = preg_split('/\s+/', $line, 4);
            if (strtoupper($parts[0]) === 'RESOURCE') {
                if (count($parts) < 2) {
                    throw new \InvalidArgumentException("RESOURCE missing name at {$file}:".($lineno + 1));
                }
                $resource = strtolower($parts[1]);
                $this->addRoute('GET', "/{$resource}", "{$resource}#index");
                $this->addRoute('GET', "/{$resource}/new", "{$resource}#new");
                $this->addRoute('POST', "/{$resource}", "{$resource}#create");
                $this->addRoute('GET', "/{$resource}/{id}", "{$resource}#show");
                $this->addRoute('GET', "/{$resource}/{id}/edit", "{$resource}#edit");
                $this->addRoute('PATCH', "/{$resource}/{id}", "{$resource}#update");
                $this->addRoute('DELETE', "/{$resource}/{id}", "{$resource}#destroy");
            } else {
                [$method, $pattern, $handler, $name] = array_pad($parts, 4, null);

                $method = strtoupper($method);
                $this->addRoute($method, $pattern, $handler, $name);
            }
        }
    }

    private function resolve(string $method, string $uri): ?RouteMatch
    {
        $method = strtoupper($method);
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route->regexPattern, $uri, $m)) {
                $values = array_map('urldecode', array_slice($m, 1));

                if (count($route->paramNames) !== count($values)) {
                    throw new \RuntimeException("Parameter count mismatch for {$route->pattern}");
                }

                $params = array_combine($route->paramNames, $values);
                return new RouteMatch($route, $params);
            }
        }
        return null;
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

    private function defaultNameFor(string $controllerWord, string $action): string
    {
        return lcfirst($controllerWord) . '.' . $action;
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
