<?php
declare(strict_types=1);

namespace TrackPHP\Tests\Fixtures;

use TrackPHP\Http\Controller;
use TrackPHP\Http\Response;

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

