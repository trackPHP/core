<?php

namespace TrackPHP\Router;

class Route {

    public function __construct(
      public string $method,
      public string $pattern,
      public string $regexPattern,
      public string $controller,
      public string $action,
      public array $paramNames,
      public array $params = []
    ) {}
}
