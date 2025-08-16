<?php
declare(strict_types=1);

namespace TrackPHP\Tests\View;

use PHPUnit\Framework\TestCase;
use TrackPHP\View\ViewRenderer;
use TrackPHP\View\CacheViewRenderer;
use TrackPHP\View\ViewCompiler;

final class CacheViewRendererTest extends TestCase
{
    protected string $sandbox;
    protected string $viewsDir;
    protected string $cacheDir;

    protected function setUp(): void
    {
        // Create a unique sandbox for each test
        $this->sandbox  = sys_get_temp_dir() . '/trackphp_test_' . bin2hex(random_bytes(4));
        $this->viewsDir = $this->sandbox . '/app/views';
        $this->cacheDir = $this->sandbox . '/storage/views';

        mkdir($this->viewsDir, 0777, true);
        mkdir($this->viewsDir . '/users', 0777, true);
        mkdir($this->viewsDir . '/admin', 0777, true);
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Recursively remove the sandbox (rm -rf equivalent)
        $this->rrmdir($this->sandbox);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function makeRenderer(): ViewRenderer
    {
        return new CacheViewRenderer(new ViewCompiler(), $this->viewsDir, $this->cacheDir);
    }

    public function test_renders_view_with_escaping_and_raw(): void
    {
        $tpl = <<<'PHP'
<h1>{{ $title }}</h1>
<p>Hello, {{ $name }}</p>
<div>{!! $bioHtml !!}</div>
PHP;
        $template = 'users/show';
        $view = $this->viewsDir . DIRECTORY_SEPARATOR . $template . '.html.php';
        file_put_contents($view, $tpl);

        $r = $this->makeRenderer();
        $html = $r->render($template, [
            'title'   => 'User Profile',
            'name'    => '<Jeff>',
            'bioHtml' => '<em>Coach</em>',
        ]);

        $this->assertStringContainsString('<h1>User Profile</h1>', $html);
        $this->assertStringContainsString('Hello, &lt;Jeff&gt;', $html);
        $this->assertStringContainsString('<div><em>Coach</em></div>', $html);
    }

    public function test_compiled_file_has_helper_imports(): void
    {
        $template = 'users/show';
        $view = $this->viewsDir . DIRECTORY_SEPARATOR . $template . '.html.php';
        file_put_contents($view, '<p>{{ $name }}</p>');

        $r = $this->makeRenderer();
        $r->render($template, ['name' => 'A']);

        $compiled = glob($this->cacheDir . '/show.*.php');
        $this->assertNotEmpty($compiled, 'Compiled file not created');

        $head = file_get_contents($compiled[0], false, null, 0, 200);
        $this->assertStringContainsString('declare(strict_types=1);', $head);
        $this->assertStringContainsString('use function TrackPHP\\Support\\safeEscape;', $head);
        $this->assertStringContainsString('use function TrackPHP\\Support\\safeJs;', $head);
    }

    public function test_cache_reused_when_source_unchanged(): void
    {
        $template = 'users/show';
        $view = $this->viewsDir . DIRECTORY_SEPARATOR . $template . '.html.php';
        file_put_contents($view, '<p>{{ $name }}</p>');

        $r = $this->makeRenderer();
        $r->render($template, ['name' => 'A']);

        $compiled = glob($this->cacheDir . '/show.*.php')[0];
        $mtime1 = filemtime($compiled);

        // Render again without touching source
        usleep(1_000_000); // 1 second
        $r->render($template, ['name' => 'B']);
        $mtime2 = filemtime($compiled);

        $this->assertSame($mtime1, $mtime2, 'Cache was unexpectedly rewritten');
    }

    public function test_recompile_when_source_changes(): void
    {
        $template = 'users/show';
        $view = $this->viewsDir . DIRECTORY_SEPARATOR . $template . '.html.php';
        file_put_contents($view, '<p>{{ $name }}</p>');

        $r = $this->makeRenderer();
        $r->render($template, ['name' => 'A']);

        $compiled = glob($this->cacheDir . '/show.*.php')[0];
        $mtime1 = filemtime($compiled);

        // Modify the source view
        usleep(1_000_000); // 1 second
        file_put_contents($view, '<p>{{ $name }}</p><span>{{ $extra }}</span>');

        $html = $r->render($template, ['name' => 'A', 'extra' => 'Z']);
        $mtime2 = filemtime($compiled);

        $this->assertGreaterThan($mtime1, $mtime2, 'Cache was not regenerated after source change');
        $this->assertStringContainsString('<span>Z</span>', $html);
    }

    public function test_include_scope_isolated_and_internal_protected(): void
    {
        $tpl = <<<'PHP'
<?php // try to detect if $__file was overridden via data ?>
<?= isset($__file) && $__file === 'HACK' ? 'BAD' : 'OK' ?>
PHP;
        $template = 'users/show';
        $view = $this->viewsDir . DIRECTORY_SEPARATOR . $template . '.html.php';
        file_put_contents($view, $tpl);

        $r = $this->makeRenderer();
        $html = $r->render($template, ['__file' => 'HACK']);

        $this->assertSame('OK', trim($html), 'Internal $__file was overridden by user data');
    }

    public function test_missing_view_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('View not found');
        $this->makeRenderer()->render('users/missing', []);
    }

    /** 7) Different folders with same basename compile to distinct cache files */
    public function test_same_basename_different_folders_get_distinct_cache(): void
    {
        file_put_contents($this->viewsDir . '/admin/index.html.php', '<p>Admin {{ $x }}</p>');
        file_put_contents($this->viewsDir . '/users/index.html.php', '<p>User {{ $x }}</p>');

        $r = $this->makeRenderer();
        $r->render('/admin/index', ['x' => 'A']);
        $r->render('/users/index', ['x' => 'B']);

        $compiled = glob($this->cacheDir . '/index.*.php');
        $this->assertCount(2, $compiled, 'Expected two compiled files for same basename in different folders');
    }

    public function test_utf8_characters_render_correctly(): void
    {
        $template = 'users/show';
        $view = $this->viewsDir . DIRECTORY_SEPARATOR . $template . '.html.php';
        file_put_contents($view, '<p>{{ $emoji }}</p>');

        $r = $this->makeRenderer();
        $html = $r->render($template, ['emoji' => 'こんにちは 🌸']);

        $this->assertStringContainsString('こんにちは 🌸', $html);
    }

    public function test_it_applies_layout_and_inserts_filled_title_slot(): void
    {
        // Arrange: create layout + child
        @mkdir($this->viewsDir . '/layouts', 0777, true);

        file_put_contents(
            $this->viewsDir . '/layouts/app.html.php',
            "<html><head><title>@slot('title')</title></head><body>@slot('body')</body></html>"
        );

        file_put_contents(
            $this->viewsDir . '/home.index.html.php',
            "@useLayout('layouts/app')\n@fill('title')My Page@endfill"
        );

        $renderer = $this->makeRenderer();

        $html = $renderer->render('home.index');

        // Assert: title filled, body empty for now (we’ll test body next)
        $this->assertSame(
            '<html><head><title>My Page</title></head><body></body></html>',
            trim($html)
        );
    }

    public function test_it_renders_body_when_filled_explicitly(): void
    {
        @mkdir($this->viewsDir . '/layouts', 0777, true);

        file_put_contents(
            $this->viewsDir . '/layouts/app.html.php',
            "<html><head><title>@slot('title')</title></head><body>@slot('body')</body></html>"
        );

        file_put_contents(
            $this->viewsDir . '/home.index.html.php',
            "@useLayout('layouts/app')\n@fill('title')T@endfill\n@fill('body')<p>Hello</p>@endfill"
        );

        $renderer = $this->makeRenderer();

        $html = $renderer->render('home.index');

        $this->assertSame(
            '<html><head><title>T</title></head><body><p>Hello</p></body></html>',
            trim($html)
        );
    }

    public function test_single_line_fill_renders_into_layout_slot(): void
    {
        @mkdir($this->viewsDir . '/layouts', 0777, true);

        file_put_contents(
            $this->viewsDir . '/layouts/app.html.php',
            "<html><head><title>@slot('title')</title></head><body>@slot('body')</body></html>"
        );

        file_put_contents(
            $this->viewsDir . '/home.index.html.php',
            "@useLayout('layouts/app')\n@fill('title', 'Quick Title')"
        );

        $renderer = $this->makeRenderer();

        $html = $renderer->render('home.index');

        $this->assertSame(
            '<html><head><title>Quick Title</title></head><body></body></html>',
            trim($html)
        );
    }

    public function test_single_line_fill_with_escaped_variable(): void
    {
        @mkdir($this->viewsDir . '/layouts', 0777, true);

        file_put_contents(
            $this->viewsDir . '/layouts/app.html.php',
            "<html><head><title>@slot('title')</title></head><body>@slot('body')</body></html>"
        );

        file_put_contents(
            $this->viewsDir . '/home.index.html.php',
            "@useLayout('layouts/app')\n@fill('title', '{{ \$name }}')"
        );

        $renderer = $this->makeRenderer();

        $html = $renderer->render('home.index', ['name' => 'Jeff']);

        $this->assertSame(
            '<html><head><title>Jeff</title></head><body></body></html>',
            trim($html)
        );
    }

    public function test_single_line_fill_with_commas_parentheses_and_quotes_renders_correctly(): void
    {
        @mkdir($this->viewsDir . '/layouts', 0777, true);

        file_put_contents(
            $this->viewsDir . '/layouts/app.html.php',
            "<title>@slot('title')</title>"
        );

        file_put_contents(
            $this->viewsDir . '/home.index.html.php',
            "@useLayout('layouts/app')\n@fill('title', 'Hello, world (v2) — Bob\\'s \"Test\"')"
        );

        $renderer = $this->makeRenderer();

        $html = $renderer->render('home.index', []);
        $this->assertSame('<title>Hello, world (v2) — Bob\'s "Test"</title>', trim($html));
    }

    public function test_free_output_becomes_implicit_body_when_no_explicit_body_fill(): void
    {
        @mkdir($this->viewsDir . '/layouts', 0777, true);

        file_put_contents(
            $this->viewsDir . '/layouts/app.html.php',
            "<html><head><title>@slot('title')</title></head><body>@slot('body')</body></html>"
        );

        file_put_contents(
            $this->viewsDir . '/home.index.html.php',
            "@useLayout('layouts/app')\n@fill('title')T@endfill\n<p>Hello implicit body</p>"
        );

        $renderer = $this->makeRenderer();

        $html = $renderer->render('home.index');
        $this->assertSame(
            '<html><head><title>T</title></head><body><p>Hello implicit body</p></body></html>',
            trim($html)
        );
    }

    public function test_explicit_body_fill_wins_and_free_output_is_discarded(): void
    {
        @mkdir($this->viewsDir . '/layouts', 0777, true);

        file_put_contents(
            $this->viewsDir . '/layouts/app.html.php',
            "<html><head><title>@slot('title')</title></head><body>@slot('body')</body></html>"
        );

        file_put_contents(
            $this->viewsDir . '/home.index.html.php',
            "@useLayout('layouts/app')\n" .
            "@fill('title')T@endfill\n" .
            "<p>SHOULD_BE_DISCARDED</p>\n" .
            "@fill('body')<p>Explicit Body</p>@endfill\n" .
            "<p>ALSO_DISCARDED</p>\n"
        );

        $renderer = $this->makeRenderer();

        $html = $renderer->render('home.index');
        $this->assertSame(
            '<html><head><title>T</title></head><body><p>Explicit Body</p></body></html>',
            trim($html)
        );
    }

}
