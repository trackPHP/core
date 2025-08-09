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
      public array $params
    ) {
        $this->method = $method;
        $this->pattern = $pattern;
        $this->regexPattern = $regexPattern;
        $this->controller = $controller;
        $this->action = $action;
        $this->paramNames = $paramNames;
        $this->params = $params;
    }
}
