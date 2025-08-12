<?php
declare(strict_types=1);

namespace TrackPHP\Tests\Http;

use PHPUnit\Framework\TestCase;
use TrackPHP\Http\Dispatcher;
use TrackPHP\Http\Request;
use TrackPHP\Router\Router;

final class DispatcherTest extends TestCase
{
    private function makeRequest(string $method, string $uri): Request
    {
        $server = [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI'    => $uri,
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
        ];
        // Adjust to your factory; matches your earlier Request::create(...)
        return \TrackPHP\Http\Request::create($server, [], [], [], '');
    }

    private function makeRouterWithBasicRoutes(): Router
    {
        $router = new Router();
        $router->addRoute('GET', '/', 'home#index');
        $router->addRoute('GET', '/hello/{name}', 'home#greet');
        $router->addRoute('GET', '/raw', 'home#raw');
        return $router;
    }

    public function test_it_dispatches_to_controller_action_and_passes_params(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $dispatcher = new Dispatcher($router, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/hello/Jeff');
        $response = $dispatcher->handle($request);

        $this->assertSame(200, $response->status());
        // text() helper sets text/plain
        $this->assertSame('text/plain; charset=utf-8', $response->headers()['Content-Type'] ?? null);
        $this->assertSame("G'day, Jeff!", $response->body());
    }

    public function test_it_returns_404_when_no_route_matches(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $dispatcher = new Dispatcher($router, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/missing');
        $response = $dispatcher->handle($request);

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('404', $response->body());
    }

    public function test_405_sets_allow_header(): void
    {
        $router = new Router();
        $router->addRoute('POST', '/things', 'Home#index'); // only POST

        $dispatcher = new Dispatcher($router, '\\TrackPHP\\Tests\\Fixtures');

        $req = $this->makeRequest('GET', '/things'); // wrong method
        $res = $dispatcher->handle($req);

        $this->assertSame(405, $res->status());
        $this->assertSame('POST', $res->headers()['Allow'] ?? null);
    }

    public function test_it_returns_500_when_controller_class_is_missing(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/oops', 'Nope#index'); // Controller class does not exist
        $dispatcher = new Dispatcher($router, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/oops');
        $response = $dispatcher->handle($request);

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('Controller not found', $response->body());
    }

    public function test_it_returns_500_when_action_is_missing(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/bad', 'Home#missingAction'); // Action does not exist
        $dispatcher = new Dispatcher($router, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/bad');
        $response = $dispatcher->handle($request);

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('Action not found', $response->body());
    }

    public function test_it_wraps_raw_string_returns_into_response(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $dispatcher = new Dispatcher($router, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/raw');
        $response = $dispatcher->handle($request);

        $this->assertSame(200, $response->status());
        $this->assertSame('raw string body', $response->body());
        // Defaults to HTML unless your Controller helper changed it later
        $this->assertSame('text/html; charset=utf-8', $response->headers()['Content-Type'] ?? null);
    }

    public function test_405_sets_allow_and_body(): void
    {
        $router = new Router();
        $router->addRoute('POST', '/things', 'Home#index');
        $dispatcher = new Dispatcher($router, '\\TrackPHP\\Tests\\Fixtures');

        $res = $dispatcher->handle($this->makeRequest('GET', '/things'));

        $this->assertSame(405, $res->status());
        $this->assertSame('POST', $res->headers()['Allow'] ?? null);
        $this->assertStringContainsString('405', $res->body());
    }

}
