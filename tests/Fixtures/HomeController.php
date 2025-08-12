<?php
declare(strict_types=1);

namespace TrackPHP\Tests\Fixtures;

use TrackPHP\Http\Controller;
use TrackPHP\Http\Response;

final class HomeController extends Controller
{
    // Returns HTML Response (uses helper)
    public function index(): Response|string
    {
        return $this->html('<h1>Home</h1>');
    }

    // Returns TEXT Response with route param (uses helper)
    public function greet(): Response|string
    {
        $name = $this->param('name') ?? 'friend';
        return $this->text("G'day, {$name}!");
    }

    // Returns a raw string to ensure Dispatcher normalises it to a Response
    public function raw(): string
    {
        return 'raw string body';
    }
}

