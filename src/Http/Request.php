<?php

namespace TrackPHP\Http;

class Request {
    private array $routeParams = [];
    private array $params = [];

    private function __construct(
        private string $method,
        private string $path,
        private string $fullPath,
        private string $originalUrl,
        private array $queryParams,
        private array $requestParams,
        private array $cookies,
        private array $headers,
        private string $body,
        private ?string $ip
    ) {
        $this->recomputeParams();
    }

    public static function capture(): self
    {
        return self::create($_SERVER ?? [], $_GET ?? [], $_POST ?? [], $_COOKIE ?? [], file_get_contents('php://input') ?: '');
    }
    public static function create(array $server, array $get, array $post, array $cookie, string $rawBody): self
    {
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $fullPath = ($server['REQUEST_URI'] ?? '') ?: '/';
        $path = parse_url($fullPath, PHP_URL_PATH) ?: '/';
        $path = self::normalisePath($path);

        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $server['HTTP_HOST'] ?? 'localhost';
        $originalUrl = $scheme . '://' . $host . $fullPath;

        $queryParams = $get ?? [];
        $cookies = $cookie ?? [];
        $headers = self::extractHeaders($server ?? []);
        $body = $rawBody;
        $ip = $server['REMOTE_ADDR'] ?? null;

        $contentType   = self::headerValue($headers, 'Content-Type');
        $mediaType     = self::mediaTypeFrom($contentType);

        $requestParams = ($mediaType === 'application/json')
            ? (is_array($tmp = json_decode($body, true)) ? $tmp : [])
            : ($post ?? []);

        return new self(
            $method, $path, $fullPath, $originalUrl,
            $queryParams, $requestParams, $cookies,
            $headers, $body, $ip
        );
    }

    // Core
    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function fullPath(): string { return $this->fullPath; }
    public function originalUrl(): string { return $this->originalUrl; }

    // Params
    public function queryParams(): array { return $this->queryParams; }
    public function requestParams(): array { return $this->requestParams; }

    public function query(string $key, mixed $default=null): mixed
    {
        return array_key_exists($key, $this->queryParams) ? $this->queryParams[$key] : $default;
    }

    public function request(string $key, mixed $default=null): mixed
    {
        return array_key_exists($key, $this->requestParams) ? $this->requestParams[$key] : $default;
    }

    // Headers & body
    public function headers(): array { return $this->headers; }

    public function header(string $name, ?string $default=null): ?string
    {
        $v = self::headerValue($this->headers, $name);
        return $v !== null ? $v : $default;
    }

    public function contentType(): ?string { return $this->header('Content-Type'); }
    public function mediaType(): ?string { return self::mediaTypeFrom($this->contentType()); }

    public function contentLength(): int
    {
        $h = $this->header('Content-Length');
        if ($h !== null && ctype_digit((string)$h)) {
            return (int)$h;
        }
        return strlen($this->body);
    }

    public function body(): string { return $this->body; }
    public function rawPost(): string { return $this->body; }

    public function json(): ?array
    {
        $mt = $this->mediaType();
        if ($mt !== 'application/json' && !($mt && str_ends_with($mt, '+json'))) {
            return null;
        }
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }

    // Env-ish helpers
    public function ip(): ?string { return $this->ip; }

    public function isAjax(): bool
    {
        return strcasecmp($this->header('X-Requested-With') ?? '', 'XMLHttpRequest') === 0;
    }

    public function isSecure(): bool
    {
        return str_starts_with($this->originalUrl, 'https://');
    }

    public function host(): string
    {
        $host = parse_url($this->originalUrl, PHP_URL_HOST);
        return $host ?: 'localhost';
    }

    public function port(): int
    {
        $port = parse_url($this->originalUrl, PHP_URL_PORT);
        if ($port !== null) return (int)$port;
        return $this->scheme() === 'https' ? 443 : 80;
    }

    public function scheme(): string
    {
        return str_starts_with($this->originalUrl, 'https://') ? 'https' : 'http';
    }

    public function cookies(): array { return $this->cookies; }

    public function cookie(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->cookies) ? $this->cookies[$name] : $default;
    }

    // Tiny future-proofing
    public function withMethod(string $method): self
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);
        return $clone;
    }

    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        $clone->recomputeParams();
        return $clone;
    }

    public function params(): array
    {
        return $this->params;
    }

    public function param(string $key, mixed $default=null): mixed
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    public function routeParams(): array
    {
        return $this->routeParams;
    }

    private function recomputeParams(): void
    {
        // Precedence: query < body < route (route wins on conflicts)
        $this->params = array_merge(
            $this->queryParams,
            $this->requestParams,
            $this->routeParams
        );
    }

    private static function normalisePath(string $path): string
    {
        if ($path === '') $path = '/';
        if ($path[0] !== '/') $path = '/' . $path;    // ensure leading slash
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/') $path = rtrim($path, '/');
        return $path;
    }

    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = $key;
            } else {
                continue;
            }

            // Convert to Header-Case
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
            $headers[$name] = $value;
        }

        return $headers;
    }

    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return is_array($v) ? implode(',', $v) : (string)$v;
            }
        }
        return null;
    }

    private static function mediaTypeFrom(?string $contentType): ?string
    {
        if (!$contentType) return null;
        $semi = strpos($contentType, ';');
        $type = $semi === false ? $contentType : substr($contentType, 0, $semi);
        return strtolower(trim($type));
    }
}
