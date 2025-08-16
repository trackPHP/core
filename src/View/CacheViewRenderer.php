<?php

declare(strict_types=1);

namespace TrackPHP\View;
use TrackPHP\View\Exceptions\ViewNotFoundException;

class CacheViewRenderer implements ViewRenderer
{
    private const VIEW_EXT = '.html.php';
    private array $sections = [];
    private array $stack = [];
    private ?string $parentLayout = null;

    public function __construct(
        private ViewCompiler $compiler,
        private string $viewsPath,   // e.g. app/Views
        private string $cachePath,   // e.g. storage/views
    ) {
        if (!is_dir($this->cachePath)) {
            if (!@mkdir($this->cachePath, 0775, true) && !is_dir($this->cachePath)) {
                throw new \RuntimeException("Unable to create view cache dir: {$this->cachePath}");
            }
        }
    }

    public function render(string $template, array $data = []): string
    {
        $cache = $this->prepareTemplate($template);
        $html = $this->bufferOutput($cache, $data);
        $this->captureImplicitBody($html);

        while ($this->parentLayout !== null) {
            $layoutCache = $this->prepareTemplate($this->parentLayout);
            $this->parentLayout = null;

            // Rendering the layout will use @slot() placeholders
            $html = $this->bufferOutput($layoutCache, $data);
            $this->captureImplicitBody($html);
        }

        return $html;
    }

    public function useLayout(string $layout): void
    {
        $this->parentLayout = ltrim($layout, '/');
    }

    public function start(string $name): void
    {
        $this->stack[] = $name;
        ob_start();
    }

    public function end(): void
    {
        $name = array_pop($this->stack);
        $this->sections[$name] = (string)ob_get_clean();
    }

    public function slot(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    private function resolve(string $template): string
    {
        return $this->viewsPath . DIRECTORY_SEPARATOR . $template . self::VIEW_EXT;
    }

    private function prepareTemplate(string $template): string
    {
        $fullPath = $this->resolve($template);

        if (!is_file($fullPath)) {
            throw new ViewNotFoundException($fullPath);
        }

        $cache = $this->cacheFileFor($fullPath);
        $this->compileIfNeeded($fullPath, $cache);

        return $cache; // return path to compiled cache file
    }

    private function captureImplicitBody(string $html): void
    {
        if (!isset($this->sections['body'])) {
            $trimmed = trim($html);
            if ($trimmed !== '') {
                $this->sections['body'] = $html;
            }
        }
    }


    private function compileIfNeeded(string $fullPathTemplate, string $cache): void
    {
        $srcMTime = filemtime($fullPathTemplate) ?: 0;
        $cacheMTime = is_file($cache) ? (filemtime($cache) ?: 0) : 0;

        if ($cacheMTime >= $srcMTime) {
            return; // Up to date
        }

        $original = file_get_contents($fullPathTemplate);
        if ($original === false) {
            throw new \RuntimeException("Failed to read view: {$fullPathTemplate}");
        }

        $compiled = $this->compiler->compile($original);

        // Wrap in a guard so accidental BOMs or stray output don’t break things
        $compiled =
            "<?php declare(strict_types=1);\n" .
            "use function TrackPHP\\Support\\safeEscape;\n" .
            "use function TrackPHP\\Support\\safeJs;\n" .
            "?>\n" .
            $compiled;


        if (file_put_contents($cache, $compiled, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write compiled view: {$cache}");
        }
        @chmod($cache, 0664);
    }

    private function cacheFileFor(string $fullPathTemplate): string
    {
        $hash = sha1($fullPathTemplate);
        $base = basename($fullPathTemplate, '.html.php');
        return rtrim($this->cachePath, '/')."/{$base}.{$hash}.php";
    }

    /**
     * Include compiled PHP with isolated variable scope.
     */
    private function bufferOutput(string $__file, array $__data): string
    {
        // Extract, but don’t overwrite superglobals or internal vars
        foreach ($__data as $__k => $__v) {
            if (is_string($__k) && !str_starts_with($__k, '__')) {
                ${$__k} = $__v;
            }
        }

        ob_start();
        include $__file;
        return (string)ob_get_clean();
    }

    private function resetSlots(): void
    {
        $this->sections = [];
        $this->stack = [];
        $this->parentLayout = null;
    }
}
