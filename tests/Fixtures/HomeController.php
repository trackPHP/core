<?php

declare(strict_types=1);

namespace TrackPHP\Tests\Fixtures;

use TrackPHP\Http\Controller;

final class HomeController extends Controller
{
    public function index()
    {
    }

    public function greet()
    {
        $this->name = $this->param('name') ?? 'friend';
    }
}
