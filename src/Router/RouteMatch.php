<?php
namespace TrackPHP\Router;
use TrackPHP\Router\Route;

final class RouteMatch
{
    public function __construct(
        public readonly Route $route,
        public readonly array $params
    ) {}
}
