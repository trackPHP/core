<?php

namespace TrackPHP\View\Exceptions;

final class ViewNotFoundException extends \RuntimeException
{
    public function __construct(string $viewPath)
    {
        parent::__construct("View not found: {$viewPath}");
    }
}

