<?php
declare(strict_types=1);

namespace TrackPHP\Http;

abstract class Controller
{
    private array $__viewData = [];

    /** Set by the Dispatcher for implicit template lookup */
    protected string $_controller = '';
    protected string $_action     = '';

    public function __construct(
        protected Request $request
    ) {}

    protected function params() {
        return $this->request->params();
    }

    public function param(string $key, mixed $default=null): mixed
    {
        return $this->request->param($key, $default);
    }

    public function setControllerAction(string $controller, string $action): void
    {
        $this->_controller = $controller;
        $this->_action     = $action;
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

    /** Expose assigns for renderer/tests */
    protected function viewData(): array
    {
        return $this->__viewData;
    }

    /** Convention-only render: app/views/{controller}/{action}.php */
    public function render(): Response
    {
        if (!defined('TRACKPHP_VIEW_PATH')) {
            throw new \RuntimeException('TRACKPHP_VIEW_PATH is not defined.');
        }
        if ($this->_controller === '' || $this->_action === '') {
            throw new \RuntimeException('Controller/action not set for implicit render.');
        }

        $path = rtrim((string)TRACKPHP_VIEW_PATH, '/\\')
              . '/' . $this->_controller
              . '/' . $this->_action
              . '.html.php';

        if (!is_file($path)) {
            throw new \RuntimeException("View not found: {$path}");
        }

        ob_start();
        // expose assigns as $vars
        extract($this->__viewData, EXTR_SKIP);
        include $path;
        $body = (string)ob_get_clean();

        return (new Response())->withBody($body);
    }

}

