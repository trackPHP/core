<?php
declare(strict_types=1);

namespace TrackPHP\Http;
use TrackPHP\View\ViewRenderer;

abstract class Controller
{
    private array $__viewData = [];
    private ?Response $performed = null;

    /** Set by the Dispatcher for implicit template lookup */
    protected string $_controller = '';
    protected string $_action     = '';

    public function __construct(
        protected Request $request,
        protected ViewRenderer $viewRenderer
    ) {}

    protected function params() {
        return $this->request->params();
    }

    public function param(string $key, mixed $default=null): mixed
    {
        return $this->request->param($key, $default);
    }

    /** Capture writes to undeclared props (assigns) */
    public function __set(string $name, mixed $value): void
    {
        if ($name !== '' && $name[0] !== '_') {
            $this->__viewData[$name] = $value;
        }
    }

    /** Allow reads from undeclared props */
    public function __get(string $name): mixed
    {
        if ($name !== '' && $name[0] !== '_') {
            return $this->__viewData[$name] ?? null;
        }
        return null;
    }

    public function __isset(string $name): bool
    {
        return ($name !== '' && $name[0] !== '_') && array_key_exists($name, $this->__viewData);
    }

    public function __unset(string $name): void
    {
        if ($name !== '' && $name[0] !== '_') {
            unset($this->__viewData[$name]);
        }
    }

    protected function viewData(): array
    {
        return $this->__viewData;
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

