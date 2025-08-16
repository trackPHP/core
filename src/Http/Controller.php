<?php

declare(strict_types=1);

namespace TrackPHP\Http;

use TrackPHP\View\ViewRenderer;

abstract class Controller
{
    private array $viewBag = [];
    private ?Response $performed = null;

    public function __construct(
        protected Request $request,
        protected ViewRenderer $viewRenderer
    ) {
    }

    protected function params()
    {
        return $this->request->params();
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->request->param($key, $default);
    }

    /** Capture writes to undeclared props (assigns) */
    public function __set(string $name, mixed $value): void
    {
        $this->viewBag[$name] = $value;
    }

    /** Allow reads from undeclared props */
    public function __get(string $name): mixed
    {
        return $this->viewBag[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->viewBag);
    }

    public function __unset(string $name): void
    {
        unset($this->viewBag[$name]);
    }

    protected function viewData(): array
    {
        return $this->viewBag;
    }

    public function render(string $template, array $locals = []): Response
    {
        $html = $this->viewRenderer->render($template, array_merge($this->viewData(), $locals));
        $response  = (new Response())->withBody($html);
        $this->performed = $response;
        return $response;
    }

    public function redirectTo(string $url, int $status = 302): Response
    {
        $response = (new Response($status))
            ->withHeader('Location', $url)
            ->withBody('');
        $this->performed = $response;
        return $response;
    }

    public function json(mixed $data, int $status = 200): Response
    {
        $response = (new Response($status, ['Content-Type' => 'application/json']))
            ->withBody(json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->performed = $response;
        return $response;
    }

    public function hasPerformed(): bool
    {
        return $this->performed instanceof Response;
    }

    public function performedResponse(): ?Response
    {
        return $this->performed;
    }
}
