<?php
namespace TrackPHP\Http;

final class Response
{
    public function __construct(
        private int $status = 200,
        private array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
        private string $body = ''
    ) {}

    public function withStatus(int $status): self {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withBody(string $body): self {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function status(): int {
        return $this->status;
    }

    public function headers(): array {
        return $this->headers;
    }

    public function body(): string {
        return $this->body;
    }

    public function header(string $name, ?string $default=null): ?string {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) return $v;
        }
        return $default;
    }

    public function withoutHeader(string $name): self {
        $clone = clone $this;
        foreach ($clone->headers as $k => $_) {
            if (strcasecmp($k, $name) === 0) unset($clone->headers[$k]);
        }
        return $clone;
    }

    public function withHeader(string $name, string|array $value): self {
        $clone = clone $this;
        $clone->headers[$name] = is_array($value) ? implode(',', $value) : $value;
        return $clone;
    }

    public function withJson(mixed $data, int $status = 200): self {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $r = $this->withBody($json ?? 'null')->withStatus($status);
        return $r->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
