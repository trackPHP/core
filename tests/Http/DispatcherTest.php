<?php
declare(strict_types=1);

namespace TrackPHP\Tests\Http;

use PHPUnit\Framework\TestCase;
use TrackPHP\Http\Dispatcher;
use TrackPHP\Http\Request;
use TrackPHP\Router\Router;
use TrackPHP\View\FakeViewRenderer;

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
        return $router;
    }

    private function makeViewRenderer() {
        return new FakeViewRenderer();
    }

    public function test_it_dispatches_to_controller_action_and_passes_params(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $viewRenderer = $this->makeViewRenderer();
        $dispatcher = new Dispatcher($router, $viewRenderer, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/hello/Jeff');
        $response = $dispatcher->handle($request);

        $this->assertSame(200, $response->status());
        $this->assertSame('<fake>home_controller/greet</fake>', $response->body());
        $this->assertSame('text/html; charset=utf-8', $response->headers()['Content-Type'] ?? null);
        $this->assertSame('home_controller/greet', $viewRenderer->lastTemplate);
        $this->assertSame(['name' => 'Jeff'], $viewRenderer->lastData);
    }

    public function test_it_returns_404_when_no_route_matches(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $viewRenderer = $this->makeViewRenderer();
        $dispatcher = new Dispatcher($router, $viewRenderer, '\\TrackPHP\\Tests\\Fixtures');
        $request  = $this->makeRequest('GET', '/missing');
        $response = $dispatcher->handle($request);

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('404', $response->body());
    }

    public function test_405_sets_allow_header(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $router->addRoute('POST', '/things', 'home#things'); // only POST
        $viewRenderer = $this->makeViewRenderer();
        $dispatcher = new Dispatcher($router, $viewRenderer, '\\TrackPHP\\Tests\\Fixtures');

        $req = $this->makeRequest('GET', '/things'); // wrong method
        $res = $dispatcher->handle($req);

        $this->assertSame(405, $res->status());
        $this->assertSame('POST', $res->headers()['Allow'] ?? null);
    }

    public function test_it_returns_500_when_controller_class_is_missing(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $router->addRoute('GET', '/oops', 'Nope#index'); // Controller class does not exist
        $viewRenderer = $this->makeViewRenderer();
        $dispatcher = new Dispatcher($router, $viewRenderer, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/oops');
        $response = $dispatcher->handle($request);

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('Controller not found', $response->body());
    }

    public function test_it_returns_500_when_action_is_missing(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $router->addRoute('GET', '/bad', 'Home#missingAction'); // Action does not exist
        $viewRenderer = $this->makeViewRenderer();
        $dispatcher = new Dispatcher($router, $viewRenderer, '\\TrackPHP\\Tests\\Fixtures');

        $request  = $this->makeRequest('GET', '/bad');
        $response = $dispatcher->handle($request);

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('Action not found', $response->body());
    }

    public function test_405_sets_allow_and_body(): void
    {
        $router = $this->makeRouterWithBasicRoutes();
        $router->addRoute('POST', '/things', 'things#create');
        $viewRenderer = $this->makeViewRenderer();
        $dispatcher = new Dispatcher($router, $viewRenderer, '\\TrackPHP\\Tests\\Fixtures');

        $res = $dispatcher->handle($this->makeRequest('GET', '/things'));

        $this->assertSame(405, $res->status());
        $this->assertSame('POST', $res->headers()['Allow'] ?? null);
        $this->assertStringContainsString('405', $res->body());
    }

}
