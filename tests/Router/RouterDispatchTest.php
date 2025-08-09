<?php

namespace App\Controllers;

class DummyController
{
    public function index(): string
    {
        return 'OK: index';
    }

    public function show($id): string
    {
        return "OK: show {$id}";
    }

    public function pair($first, $second): string
    {
        return "OK: pair {$first},{$second}";
    }
}

namespace TrackPHP\Tests\Router;

use PHPUnit\Framework\TestCase;
use TrackPHP\Router\Router;
use TrackPHP\Router\Exceptions\NotFoundException;
use TrackPHP\Router\Exceptions\MethodNotAllowedException;

final class RouterDispatchTest extends TestCase
{
    public function test_dispatch_invokes_controller_action_for_static_route(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/', 'dummy#index');

        $out = $r->dispatch('GET', '/');
        $this->assertSame('OK: index', $out);
    }

    public function test_dispatch_passes_single_parameter_in_order(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/things/{id}', 'dummy#show');

        $out = $r->dispatch('GET', '/things/42');
        $this->assertSame('OK: show 42', $out);
    }

    public function test_dispatch_passes_multiple_parameters_in_route_order(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/pairs/{first}/{second}', 'dummy#pair');

        $out = $r->dispatch('GET', '/pairs/A/B');
        $this->assertSame('OK: pair A,B', $out);
    }

    public function test_dispatch_throws_not_found_for_missing_path(): void
    {
        $this->expectException(NotFoundException::class);

        $r = new Router();
        $r->dispatch('GET', '/missing');
    }

    public function test_dispatch_throws_method_not_allowed_for_wrong_verb(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        $r = new Router();
        $r->addRoute('GET', '/only-get', 'dummy#index');
        $r->dispatch('POST', '/only-get');
    }

    public function test_dispatch_throws_error_when_method_does_not_exist(): void
    {
        $this->expectException(\RuntimeException::class);

        $r = new Router();
        $r->addRoute('GET', '/home', 'dummy#nomethod');
        $r->dispatch('GET', '/home');
    }
}

