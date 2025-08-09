<?php

namespace TrackPHP\Tests\Router;

use PHPUnit\Framework\TestCase;
use TrackPHP\Router\Route;

final class RouteTest extends TestCase
{
    public function test_it_stores_all_properties_correctly(): void
    {
        $route = new Route(
            method: 'GET',
            pattern: '/posts/{id}',
            regexPattern: '#^/posts/([^/]+)$#',
            controller: 'PostController',
            action: 'show',
            paramNames: ['id'],
            params: ['id' => '42']
        );

        $this->assertSame('GET', $route->method);
        $this->assertSame('/posts/{id}', $route->pattern);
        $this->assertSame('#^/posts/([^/]+)$#', $route->regexPattern);
        $this->assertSame('PostController', $route->controller);
        $this->assertSame('show', $route->action);
        $this->assertSame(['id'], $route->paramNames);
        $this->assertSame(['id' => '42'], $route->params);
    }
}

