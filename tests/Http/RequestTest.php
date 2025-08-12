<?php
declare(strict_types=1);

namespace TrackPHP\Tests\Http;

use PHPUnit\Framework\TestCase;
use TrackPHP\Http\Request;

final class RequestTest extends TestCase
{
    private array $SERVER_BACKUP = [];
    private array $GET_BACKUP = [];
    private array $POST_BACKUP = [];
    private array $COOKIE_BACKUP = [];

    protected function setUp(): void
    {
        // Backup superglobals
        $this->SERVER_BACKUP = $_SERVER ?? [];
        $this->GET_BACKUP    = $_GET ?? [];
        $this->POST_BACKUP   = $_POST ?? [];
        $this->COOKIE_BACKUP = $_COOKIE ?? [];

        // Clear everything to a known state
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        // Restore superglobals
        $_SERVER = $this->SERVER_BACKUP;
        $_GET    = $this->GET_BACKUP;
        $_POST   = $this->POST_BACKUP;
        $_COOKIE = $this->COOKIE_BACKUP;
    }

    public function test_capture_basic_get_with_query_builds_core_fields(): void
    {
        // Arrange: simulate a GET request to http://example.test:8080/foo//bar/?a=1&b=2
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/foo//bar/?a=1&b=2';
        $_SERVER['HTTPS']          = 'off';
        $_SERVER['HTTP_HOST']      = 'example.test:8080';

        $_GET    = ['a' => '1', 'b' => '2'];
        $_POST   = [];
        $_COOKIE = ['sid' => 'abc123'];

        // Act
        $req = Request::capture();

        // Assert: method + URL parts
        $this->assertSame('GET', $req->method());
        $this->assertSame('/foo/bar', $req->path(), 'Path should be normalised (collapse slashes, trim trailing).');
        $this->assertSame('/foo//bar/?a=1&b=2', $req->fullPath());
        $this->assertSame('http://example.test:8080/foo//bar/?a=1&b=2', $req->originalUrl());

        // Query / Request params
        $this->assertSame(['a' => '1', 'b' => '2'], $req->queryParams());
        $this->assertSame([], $req->requestParams(), 'No body params for GET.');
        $this->assertSame(['a' => '1', 'b' => '2'], $req->params(), 'Merged view should equal query when body is empty.');

        // Headers
        $this->assertSame('example.test:8080', $req->header('Host'));

        // Scheme/Host/Port helpers
        $this->assertFalse($req->isSecure());
        $this->assertSame('http', $req->scheme());
        $this->assertSame('example.test', $req->host());
        $this->assertSame(8080, $req->port());
    }

    public function test_capture_post_form_populates_request_params_and_headers(): void
    {
        // Arrange: POST to http://example.test/form?a=1 with body a=2&c=3
        $_SERVER['REQUEST_METHOD']   = 'POST';
        $_SERVER['REQUEST_URI']      = '/form?a=1';
        $_SERVER['HTTPS']            = 'off';
        $_SERVER['HTTP_HOST']        = 'example.test';
        $_SERVER['CONTENT_TYPE']     = 'application/x-www-form-urlencoded; charset=UTF-8';
        $_SERVER['CONTENT_LENGTH']   = '11'; // Arbitrary for test

        $_GET    = ['a' => '1'];
        $_POST   = ['a' => '2', 'c' => '3']; // body should take precedence for 'a'
        $_COOKIE = [];

        // Act
        $req = Request::capture();

        // Assert: method + URL parts
        $this->assertSame('POST', $req->method());
        $this->assertSame('/form', $req->path());
        $this->assertSame('/form?a=1', $req->fullPath());
        $this->assertSame('http://example.test/form?a=1', $req->originalUrl());

        // Params: query vs body
        $this->assertSame(['a' => '1'], $req->queryParams());
        $this->assertSame(['a' => '2', 'c' => '3'], $req->requestParams());

        // Merged params — body wins for 'a'
        $this->assertSame(['a' => '2', 'c' => '3'], $req->params());

        // Content-Type / mediaType
        $this->assertSame('application/x-www-form-urlencoded; charset=UTF-8', $req->contentType());
        $this->assertSame('application/x-www-form-urlencoded', $req->mediaType());

        // Content-Length from header
        $this->assertSame(11, $req->contentLength());

        // JSON helper should be null for non-JSON content
        $this->assertNull($req->json());
    }

    public function test_create_json_post_decodes_body_and_sets_request_params(): void
    {
        // Arrange: POST /api with application/json body
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/api?v=1',
            'HTTPS'          => 'on',
            'HTTP_HOST'      => 'example.test',
            'CONTENT_TYPE'   => 'application/json; charset=utf-8',
            // Intentionally omit CONTENT_LENGTH to exercise fallback to strlen($body)
        ];
        $get = ['v' => '1', 'a' => '999'];
        $post   = ['ignored' => 'because-json']; // should be ignored for JSON
        $cookie = [];
        $body   = json_encode(['a' => 2, 'c' => 3]);

        // Act
        $req = \TrackPHP\Http\Request::create($server, $get, $post, $cookie, $body);

        // Assert: URL parts
        $this->assertSame('POST', $req->method());
        $this->assertSame('/api', $req->path());
        $this->assertSame('/api?v=1', $req->fullPath());
        $this->assertSame('https://example.test/api?v=1', $req->originalUrl());

        // Headers (case-insensitive lookup)
        $this->assertSame('application/json; charset=utf-8', $req->header('content-type'));

        // Media type / content length fallback
        $this->assertSame('application/json', $req->mediaType());
        $this->assertSame(strlen($body), $req->contentLength());

        // Params: requestParams from decoded JSON, query untouched, merged = body wins
        $this->assertSame(['a' => 2, 'c' => 3], $req->requestParams());
        $this->assertSame(['v' => '1', 'a' => '999'], $req->queryParams());
        $this->assertEqualsCanonicalizing(
            ['a' => 2, 'c' => 3, 'v' => '1'], // 'a' stays from body
            $req->params()
        );


        // JSON helper returns the decoded payload
        $this->assertSame(['a' => 2, 'c' => 3], $req->json());
    }

    public function test_isAjax_and_header_case_insensitivity(): void
    {
        // Arrange
        $server = [
            'REQUEST_METHOD'        => 'GET',
            'REQUEST_URI'           => '/xhr',
            'HTTPS'                 => 'on',
            'HTTP_HOST'             => 'example.test',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', // mixed case header via SERVER
            'HTTP_X_CUSTOM_HEADER'  => 'PingSkills',
            'CONTENT_TYPE'          => 'application/json; charset=UTF-8',
        ];
        $get    = [];
        $post   = [];
        $cookie = [];
        $body   = '';

        // Act
        $req = \TrackPHP\Http\Request::create($server, $get, $post, $cookie, $body);

        // Assert: AJAX detection
        $this->assertTrue($req->isAjax());

        // Header lookups are case-insensitive
        $this->assertSame('XMLHttpRequest', $req->header('X-Requested-With'));
        $this->assertSame('XMLHttpRequest', $req->header('x-requested-with'));
        $this->assertSame('PingSkills', $req->header('x-custom-header'));

        // Header names normalised in headers() (Header-Case keys present)
        $headers = $req->headers();
        $this->assertArrayHasKey('X-Requested-With', $headers);
        $this->assertArrayHasKey('X-Custom-Header', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);

        // Content type helpers
        $this->assertSame('application/json; charset=UTF-8', $req->contentType());
        $this->assertSame('application/json', $req->mediaType());

        // Missing header returns default
        $this->assertSame('default', $req->header('Not-There', 'default'));
    }

    public function test_path_normalisation_and_fullpath_preservation(): void
    {
        $cases = [
            // [REQUEST_URI, expected normalised path]
            ['/',                    '/'],
            ['/foo',                 '/foo'],
            ['/foo/',                '/foo'],
            ['/foo//bar///',         '/foo/bar'],
            ['foo',                  '/foo'],       // missing leading slash
            ['foo//bar/',            '/foo/bar'],   // missing leading slash + collapse + trim
            ['/foo/bar/?q=1',        '/foo/bar'],   // query should be stripped from path
            ['',                     '/'],          // empty URI becomes root
        ];

        foreach ($cases as [$uri, $expectedPath]) {
            $server = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => $uri,
                'HTTPS'          => 'off',
                'HTTP_HOST'      => 'example.test',
            ];

            $req = \TrackPHP\Http\Request::create($server, [], [], [], '');

            $this->assertSame($expectedPath, $req->path(), "Normalised path for URI '{$uri}'");
            // fullPath should remain whatever was provided (including query/duplicate slashes)
            $this->assertSame($uri === '' ? '/' : $uri, $req->fullPath(), "Full path preserved for URI '{$uri}'");
        }
    }

    public function test_withMethod_returns_clone_and_updates_only_method(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/items',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
        ];

        $req  = \TrackPHP\Http\Request::create($server, [], [], [], '');
        $req2 = $req->withMethod('POST');

        // Original unchanged
        $this->assertSame('GET', $req->method());
        // New instance has updated method
        $this->assertSame('POST', $req2->method());

        // Other fields identical
        $this->assertSame($req->path(),        $req2->path());
        $this->assertSame($req->fullPath(),    $req2->fullPath());
        $this->assertSame($req->originalUrl(), $req2->originalUrl());
        $this->assertSame($req->headers(),     $req2->headers());
        $this->assertSame($req->params(),      $req2->params());

        // Distinct objects
        $this->assertNotSame($req, $req2);

        // Also check uppercasing of arbitrary input
        $req3 = $req->withMethod('patch');
        $this->assertSame('PATCH', $req3->method());
        // Original still unchanged
        $this->assertSame('GET', $req->method());
    }

    public function test_scheme_host_port_defaults_and_nonstandard(): void
    {
        // http, implicit 80
        $reqHttp = \TrackPHP\Http\Request::create(
            ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/','HTTPS'=>'off','HTTP_HOST'=>'example.test'],
            [], [], [], ''
        );
        $this->assertSame('http', $reqHttp->scheme());
        $this->assertSame('example.test', $reqHttp->host());
        $this->assertSame(80, $reqHttp->port());
        $this->assertFalse($reqHttp->isSecure());

        // https, implicit 443
        $reqHttps = \TrackPHP\Http\Request::create(
            ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/','HTTPS'=>'on','HTTP_HOST'=>'secure.test'],
            [], [], [], ''
        );
        $this->assertSame('https', $reqHttps->scheme());
        $this->assertSame('secure.test', $reqHttps->host());
        $this->assertSame(443, $reqHttps->port());
        $this->assertTrue($reqHttps->isSecure());

        // explicit non-standard port
        $req8080 = \TrackPHP\Http\Request::create(
            ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/path?q=1','HTTPS'=>'off','HTTP_HOST'=>'api.test:8080'],
            [], [], [], ''
        );
        $this->assertSame('http', $req8080->scheme());
        $this->assertSame('api.test', $req8080->host());
        $this->assertSame(8080, $req8080->port());
        $this->assertSame('http://api.test:8080/path?q=1', $req8080->originalUrl());
    }

    public function test_body_and_contentLength_header_vs_fallback(): void
    {
        // Case A: numeric CONTENT_LENGTH is used
        $bodyA = 'hello-json';
        $reqA = \TrackPHP\Http\Request::create(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/a',
                'HTTPS'          => 'off',
                'HTTP_HOST'      => 'example.test',
                'CONTENT_TYPE'   => 'application/json',
                'CONTENT_LENGTH' => (string)strlen($bodyA),
            ],
            [],
            [],
            [],
            $bodyA
        );
        $this->assertSame($bodyA, $reqA->body());
        $this->assertSame($bodyA, $reqA->rawPost());
        $this->assertSame(strlen($bodyA), $reqA->contentLength());

        // Case B: header missing -> fallback to strlen(body)
        $bodyB = '{"x":1}';
        $reqB = \TrackPHP\Http\Request::create(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/b',
                'HTTPS'          => 'on',
                'HTTP_HOST'      => 'example.test',
                'CONTENT_TYPE'   => 'application/json; charset=utf-8',
                // no CONTENT_LENGTH
            ],
            [],
            [],
            [],
            $bodyB
        );
        $this->assertSame(strlen($bodyB), $reqB->contentLength());

        // Case C: header present but non-numeric -> fallback to strlen(body)
        $bodyC = 'abc123';
        $reqC = \TrackPHP\Http\Request::create(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/c',
                'HTTPS'          => 'off',
                'HTTP_HOST'      => 'example.test',
                'CONTENT_TYPE'   => 'text/plain',
                'CONTENT_LENGTH' => 'NaN', // invalid
            ],
            [],
            [],
            [],
            $bodyC
        );
        $this->assertSame(strlen($bodyC), $reqC->contentLength());
    }

    public function test_cookies_and_ip_are_exposed(): void
    {
        // IP address uses $_SERVER
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/cookies',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
            'REMOTE_ADDR'    => '203.0.113.42',
        ];
        $cookies = ['sid' => 'abc123', 'theme' => 'dark'];

        $req = \TrackPHP\Http\Request::create($server, [], [], $cookies, '');

        $this->assertSame(['sid' => 'abc123', 'theme' => 'dark'], $req->cookies());
        $this->assertSame('abc123', $req->cookie('sid'));
        $this->assertSame('fallback', $req->cookie('missing', 'fallback'));

        $this->assertSame('203.0.113.42', $req->ip());

        // Missing header returns null without default, default when provided
        $this->assertNull($req->header('X-Missing'));
        $this->assertSame('fallback', $req->header('X-Missing', 'fallback'));
    }

    public function test_json_helper_edge_cases(): void
    {
        // Case A: Content-Type missing -> mediaType null, json() null
        $reqA = \TrackPHP\Http\Request::create(
            ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/x','HTTPS'=>'off','HTTP_HOST'=>'example.test'],
            [], [], [], '{"ok":true}'
        );
        $this->assertNull($reqA->contentType());
        $this->assertNull($reqA->mediaType());
        $this->assertNull($reqA->json());

        // Case B: Content-Type present but not JSON -> json() null
        $reqB = \TrackPHP\Http\Request::create(
            ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/x','HTTPS'=>'off','HTTP_HOST'=>'example.test','CONTENT_TYPE'=>'text/plain'],
            [], [], [], '{"ok":true}'
        );
        $this->assertSame('text/plain', $reqB->contentType());
        $this->assertSame('text/plain', $reqB->mediaType());
        $this->assertNull($reqB->json());

        // Case C: JSON Content-Type but invalid JSON -> json() null, requestParams empty
        $reqC = \TrackPHP\Http\Request::create(
            ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/x','HTTPS'=>'off','HTTP_HOST'=>'example.test','CONTENT_TYPE'=>'application/json; charset=utf-8'],
            [], [], [], '{invalid json}'
        );
        $this->assertSame('application/json', $reqC->mediaType());
        $this->assertNull($reqC->json());
        $this->assertSame([], $reqC->requestParams());
    }

    public function test_header_multiple_values_join_and_default_fallback(): void
    {
        // Simulate multiple values for a header by passing an array in SERVER.
        // (PHP won’t do this in real requests, but our headerValue() supports it.)
        $server = [
            'REQUEST_METHOD'          => 'GET',
            'REQUEST_URI'             => '/h',
            'HTTPS'                   => 'off',
            'HTTP_HOST'               => 'example.test',
            'HTTP_X_MULTI'            => ['a', 'b', 'c'],
            'HTTP_ACCEPT'             => 'text/html,application/xhtml+xml',
            'CONTENT_TYPE'            => 'text/plain; charset=UTF-8',
        ];

        $req = \TrackPHP\Http\Request::create($server, [], [], [], '');

        // Multiple values are joined with commas
        $this->assertSame('a,b,c', $req->header('X-Multi'));
        $this->assertSame('a,b,c', $req->header('x-multi')); // case-insensitive

        // Regular single-value header works and keeps original value
        $this->assertSame('text/plain; charset=UTF-8', $req->contentType());
        $this->assertSame('text/plain', $req->mediaType());

        // Accept header available via case-insensitive lookup
        $this->assertSame('text/html,application/xhtml+xml', $req->header('ACCEPT'));

        // Missing header → null, or provided default
        $this->assertNull($req->header('X-Missing'));
        $this->assertSame('fallback', $req->header('X-Missing', 'fallback'));
    }

    public function test_json_parses_application_json(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/items',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
            'HTTP_CONTENT_TYPE' => 'application/json; charset=UTF-8',
        ];
        $body = '{"a":1,"b":"x"}';

        $req = Request::create($server, [], [], [], $body);

        $this->assertSame('application/json', $req->mediaType());
        $this->assertSame(['a' => 1, 'b' => 'x'], $req->json());
    }

    public function test_json_parses_plus_json_subtype(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/items',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
            'HTTP_CONTENT_TYPE' => 'application/problem+json',
        ];
        $body = '{"title":"Bad Request","status":400}';

        $req = Request::create($server, [], [], [], $body);

        // mediaType() should still return the bare type
        $this->assertSame('application/problem+json', $req->contentType());
        $this->assertSame('application/problem+json', $req->mediaType());
        $this->assertSame(['title' => 'Bad Request', 'status' => 400], $req->json());
    }

    public function test_json_returns_null_for_wrong_content_type(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/items',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
            'HTTP_CONTENT_TYPE' => 'text/plain',
        ];
        $body = '{"a":1}'; // JSON-looking, but content type is wrong

        $req = Request::create($server, [], [], [], $body);

        $this->assertNull($req->json());
    }

    public function test_json_returns_null_for_invalid_json(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/items',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];
        $body = '{"a": 1, '; // invalid JSON

        $req = Request::create($server, [], [], [], $body);

        $this->assertNull($req->json());
    }

    public function test_withRouteParams_is_immutable_and_params_merge_order(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/posts/123',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
        ];
        $query  = ['id' => 'q', 'page' => '2'];          // lowest precedence
        $body   = ['id' => 'b', 'filter' => 'new'];      // middle
        $route  = ['id' => '123', 'slug' => 'hello'];    // highest

        $req  = Request::create($server, $query, $body, [], '');
        $req2 = $req->withRouteParams($route);

        // Immutability
        $this->assertNotSame($req, $req2);
        $this->assertSame($query, $req->queryParams(), 'Original unchanged');
        $this->assertSame($body,  $req->requestParams(), 'Original unchanged');

        // Merge precedence: query < body < route
        $this->assertSame(
            ['id' => '123', 'page' => '2', 'filter' => 'new', 'slug' => 'hello'],
            $req2->params()
        );

        // Original params unaffected
        $this->assertSame(
            ['id' => 'b', 'page' => '2', 'filter' => 'new'],
            $req->params()
        );
    }

    public function test_create_defaults_path_to_root_when_request_uri_missing(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            // no REQUEST_URI
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
        ];

        $req = Request::create($server, [], [], [], '');

        $this->assertSame('/', $req->path());
        $this->assertSame('/', $req->fullPath());
        $this->assertSame('http://example.test/', $req->originalUrl());
    }

}

