<?php
declare(strict_types=1);

namespace TrackPHP\Tests;

use PHPUnit\Framework\TestCase;
use TrackPHP\Http\Response;

final class ResponseTest extends TestCase
{
    public function test_defaults(): void
    {
        $r = new Response();
        $this->assertSame(200, $r->status());
        $this->assertSame('text/html; charset=utf-8', $r->headers()['Content-Type'] ?? null);
        $this->assertSame('', $r->body());
    }

    public function test_withStatus_is_immutable(): void
    {
        $r1 = new Response(200);
        $r2 = $r1->withStatus(404);

        $this->assertNotSame($r1, $r2, 'withStatus must return a new instance');
        $this->assertSame(200, $r1->status(), 'original must be unchanged');
        $this->assertSame(404, $r2->status(), 'new must reflect change');
    }

    public function test_withHeader_is_immutable_and_sets_value(): void
    {
        $r1 = new Response();
        $r2 = $r1->withHeader('X-Test', 'one');

        $this->assertNotSame($r1, $r2);
        $this->assertArrayNotHasKey('X-Test', $r1->headers());
        $this->assertSame('one', $r2->headers()['X-Test'] ?? null);
    }

    public function test_withHeader_replaces_existing_header_on_new_instance_only(): void
    {
        $r1 = (new Response())->withHeader('X-Test', 'one');
        $r2 = $r1->withHeader('X-Test', 'two');

        $this->assertNotSame($r1, $r2);
        $this->assertSame('one', $r1->headers()['X-Test']);
        $this->assertSame('two', $r2->headers()['X-Test']);
    }

    public function test_withBody_is_immutable_and_sets_body(): void
    {
        $r1 = new Response();
        $r2 = $r1->withBody('Hello');

        $this->assertNotSame($r1, $r2);
        $this->assertSame('', $r1->body());
        $this->assertSame('Hello', $r2->body());
    }

    public function test_chaining_creates_new_instances_each_step(): void
    {
        $r1 = new Response();
        $r2 = $r1->withStatus(201);
        $r3 = $r2->withHeader('X-A', 'a');
        $r4 = $r3->withBody('OK');

        $this->assertNotSame($r1, $r2);
        $this->assertNotSame($r2, $r3);
        $this->assertNotSame($r3, $r4);

        // Earlier instances remain unchanged
        $this->assertSame(200, $r1->status());
        $this->assertArrayNotHasKey('X-A', $r2->headers());
        $this->assertSame('', $r3->body());

        // Latest has all changes
        $this->assertSame(201, $r4->status());
        $this->assertSame('a', $r4->headers()['X-A'] ?? null);
        $this->assertSame('OK', $r4->body());
    }

    public function test_header_getter_is_case_insensitive_and_joins_arrays_if_enabled(): void
    {
        $r = (new Response())
            ->withHeader('ACCEPT', ['text/html','application/xhtml+xml']);

        $this->assertSame('text/html,application/xhtml+xml', $r->header('accept'));
        $this->assertSame('text/html,application/xhtml+xml', $r->header('ACCEPT'));
        $this->assertNull($r->header('X-Missing'));
        $this->assertSame('fallback', $r->header('X-Missing', 'fallback'));
    }

    public function test_withoutHeader_removes_header_case_insensitively(): void
    {
        $r1 = (new Response())->withHeader('X-Test','one')->withHeader('Content-Type','text/plain');
        $r2 = $r1->withoutHeader('x-test');
        $r3 = $r2->withoutHeader('CONTENT-TYPE');

        $this->assertSame('one', $r1->headers()['X-Test']);
        $this->assertSame('text/plain', $r1->headers()['Content-Type']);
        $this->assertArrayNotHasKey('X-Test', $r2->headers());
        $this->assertArrayHasKey('Content-Type', $r2->headers());
        $this->assertArrayNotHasKey('Content-Type', $r3->headers());
    }

    public function test_withJson_sets_header_and_body_and_status(): void
    {
        $r = (new Response())->withJson(['a'=>1], 201);
        $this->assertSame(201, $r->status());
        $this->assertSame('application/json; charset=utf-8', $r->headers()['Content-Type'] ?? null);
        $this->assertSame('{"a":1}', $r->body());
    }
}
