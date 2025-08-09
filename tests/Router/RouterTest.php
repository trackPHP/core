<?php

namespace TrackPHP\Tests\Router;

use PHPUnit\Framework\TestCase;
use TrackPHP\Router\Router;
use TrackPHP\Router\Route;

final class RouterTest extends TestCase
{
    public function test_adding_a_route_with_duplicate_param_names_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate route parameters not allowed: id');

        $router = new Router();
        $router->addRoute('GET', '/posts/{id}/comments/{id}', 'comments#show');
    }

    public function test_it_registers_root_path(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', 'home#index');

        $route = $router->match('GET', '/');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('GET', $route->method);
        $this->assertSame('/', $route->pattern);
        $this->assertSame('#^/$#', $route->regexPattern);
        $this->assertSame('HomeController', $route->controller);
        $this->assertSame('index', $route->action);
        $this->assertSame([], $route->params);
    }

    public function test_it_registers_a_get_route_with_no_params(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/home', 'home#index');

        $route = $router->match('GET', '/home');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('GET', $route->method);
        $this->assertSame('/home', $route->pattern);
        $this->assertSame('#^/home$#', $route->regexPattern);
        $this->assertSame('HomeController', $route->controller);
        $this->assertSame('index', $route->action);
        $this->assertSame([], $route->params);
    }

    public function test_it_matches_route_with_single_parameter(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/posts/{postId}', 'posts#show');

        $route = $router->match('GET', '/posts/42');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('GET', $route->method);
        $this->assertSame('/posts/{postId}', $route->pattern);
        $this->assertSame('#^/posts/([^/]+)$#', $route->regexPattern);
        $this->assertSame('PostsController', $route->controller);
        $this->assertSame('show', $route->action);
        $this->assertSame(['postId' => '42'], $route->params);
    }

    public function test_it_matches_dynamic_route_with_multiple_params(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/posts/{postId}/comments/{commentId}', 'comments#show');

        $route = $router->match('GET', '/posts/42/comments/56');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('GET', $route->method);
        $this->assertSame('/posts/{postId}/comments/{commentId}', $route->pattern);
        $this->assertSame('#^/posts/([^/]+)/comments/([^/]+)$#', $route->regexPattern);
        $this->assertSame('CommentsController', $route->controller);
        $this->assertSame('show', $route->action);
        $this->assertSame([
            'postId' => '42',
            'commentId' => '56'
        ], $route->params);
    }

    public function test_it_returns_null_for_unmatched_pattern(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/about', 'pages#about');

        $this->assertNull($router->match('GET', '/not-found'));
    }

    public function test_it_distinguishes_between_http_methods(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/login', 'auth#form');
        $router->addRoute('POST', '/login', 'auth#submit');

        $getRoute = $router->match('GET', '/login');
        $postRoute = $router->match('POST', '/login');

        $this->assertSame('form', $getRoute->action);
        $this->assertSame('submit', $postRoute->action);
    }

    public function test_it_throws_exception_for_invalid_handler_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $router = new Router();
        $router->addRoute('GET', '/bad', 'missingSeparator');
    }

    public function test_it_throws_exception_for_handler_with_two_hashes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $router = new Router();
        $router->addRoute('GET', '/bad', 'double#hash#problem');
    }

    public function test_it_treats_trailing_slash_as_different_pattern(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/about', 'pages#about');

        $this->assertNull($router->match('GET', '/about/')); // if strict
    }

    public function test_it_matches_first_route_on_duplicate_pattern(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/home', 'pages#first');
        $router->addRoute('GET', '/home', 'pages#second');

        $route = $router->match('GET', '/home');

        $this->assertSame('first', $route->action);
    }

    public function test_it_handles_root_route(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/', 'home#index');

        $route = $r->match('GET', '/');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('HomeController', $route->controller);
        $this->assertSame('index', $route->action);
        $this->assertSame('#^/$#', $route->regexPattern);
    }

    public function test_it_returns_null_for_post_when_only_get_is_registered(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/login', 'auth#form');

        $this->assertNull($r->match('POST', '/login'));
    }

    public function test_it_returns_null_for_get_when_only_post_is_registered(): void
    {
        $r = new Router();
        $r->addRoute('POST', '/login', 'auth#submit');

        $this->assertNull($r->match('GET', '/login'));
    }

    public function test_it_returns_null_for_completely_unsupported_method(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/anything', 'pages#show');

        $this->assertNull($r->match('PUT', '/anything'));
    }

    public function test_it_does_not_match_when_single_param_is_empty(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/posts/{id}', 'posts#show');

        $this->assertNull($r->match('GET', '/posts/'));   // missing id
        $this->assertNull($r->match('GET', '/posts//'));   // missing id with extra slash
    }

    public function test_it_does_not_match_when_any_multi_param_is_empty(): void
    {
        $r = new Router();
        $r->addRoute('GET', '/posts/{postId}/comments/{commentId}', 'comments#show');

        $this->assertNull($r->match('GET', '/posts//comments/56'));   // missing postId
        $this->assertNull($r->match('GET', '/posts/42/comments/'));   // missing commentId
        $this->assertNull($r->match('GET', '/posts/42/comments//'));   // missing commentId with ending slash
    }

    public function test_it_generates_path_for_unnamed_static_route() {
        $router = new Router();
        $router->addRoute('GET', '/now', 'staticPages#now');
        $this->assertSame('/now', $router->path('staticPages.now'));
    }

    public function test_it_generates_path_for_named_static_route() {
        $router = new Router();
        $router->addRoute('GET', '/about', 'pages#about', 'custom');
        $this->assertSame('/about', $router->path('custom'));
    }

    public function test_it_generates_path_for_dynamic_route() {
        $router = new Router();
        $router->addRoute('GET', '/post/{id}', 'post#show');
        $this->assertSame('/post/1', $router->path('post.show', ['id'=>1]));
    }

    public function test_it_generates_path_for_dynamic_route_with_multiple_params() {
        $router = new Router();
        $router->addRoute('GET', '/post/{post}/comments/{comment}', 'comments#show');
        $this->assertSame('/post/1/comments/2', $router->path('comments.show', ['post'=>1, 'comment'=>2]));
    }

    public function test_it_throws_exception_if_named_route_does_not_exist() {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No route found with name: nonexistent");
        $router = new Router();
        $router->path('nonexistent');
    }

    public function test_it_throws_exception_if_not_enough_parameters_provided() {
        $router = new Router();
        $router->addRoute('GET', '/posts/{postId}/comments/{commentId}', 'comments#show', name: 'comment_show');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing parameter(s) for route 'comment_show': commentId");
        $router->path('comment_show', ['postId' => 1]);
    }

    public function test_it_replaces_all_placeholders_correctly() {
        $router = new Router();
        $router->addRoute('GET', '/posts/{postId}/comments/{commentId}', 'comments#show');

        $url = $router->path('comments.show', ['postId' => 42, 'commentId' => 7]);
        $this->assertSame('/posts/42/comments/7', $url);
    }

    public function test_it_ignores_extra_parameters() {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', 'users#show', name: 'user_show');
        $url = $router->path('user_show', ['id' => 5, 'extra' => 'value']);
        $this->assertSame('/users/5', $url);
    }

    public function test_it_throws_error_on_duplicate_named_routes() {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Named route already exists: users.show');
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', 'users#show');
        $router->addRoute('GET', '/posts/{id}', 'posts#show', name: 'users.show');
    }
}

