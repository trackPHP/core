<?php

declare(strict_types=1);

namespace TrackPHP\Tests\View;

use PHPUnit\Framework\TestCase;
use TrackPHP\View\ViewCompiler;

final class ViewCompilerTest extends TestCase
{
    private ViewCompiler $c;

    protected function setUp(): void
    {
        $this->c = new ViewCompiler();
    }

    private function compile(string $tpl): string
    {
        return $this->c->compile($tpl);
    }

    public function test_compiles_escaped_output(): void
    {
        $out = $this->compile('Hello, {{ $name }}!');
        $this->assertStringContainsString('<?= safeEscape($name) ?>', $out);
    }

    public function test_compiles_raw_output(): void
    {
        $out = $this->compile('X {!! $html !!} Y');
        $this->assertStringContainsString('<?= $html ?>', $out);
        $this->assertStringNotContainsString('safeEscape($html)', $out);
    }

    public function test_whitespace_tolerance(): void
    {
        $out = $this->compile('Hello, {{    $name   }}!');
        $this->assertStringContainsString('<?= safeEscape($name) ?>', $out);
        $out = $this->compile('Hello, {{$name}}!');
        $this->assertStringContainsString('<?= safeEscape($name) ?>', $out);
    }

    public function test_strips_template_comments(): void
    {
        $out = $this->compile('A {{-- hidden --}} B');
        $this->assertSame('A  B', $out);
    }

    public function test_literal_braces_not_compiled(): void
    {
        $out = $this->compile('X @{{ $notInterpolated }} Y');
        $this->assertStringContainsString('{{ $notInterpolated }}', $out);
        $this->assertStringNotContainsString('safeEscape($notInterpolated)', $out);
    }

    public function test_php_block_passthrough(): void
    {
        $out = $this->compile('Start @php $x = 1 + 2; @endphp End');
        $this->assertStringContainsString('<?php $x = 1 + 2; ?>', $out);
    }

    public function test_no_compile_inside_php_blocks(): void
    {
        $out = $this->compile('@php $x = "{{ $name }}"; @endphp');
        // The inner braces should remain untouched in the PHP string literal
        $this->assertStringContainsString('<?php $x = "{{ $name }}"; ?>', $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    public function test_multiple_escapes_non_greedy(): void
    {
        $out = $this->compile('Hi {{ $a }} and {{ $b }}!');
        $this->assertStringContainsString('safeEscape($a)', $out);
        $this->assertStringContainsString('safeEscape($b)', $out);
    }

    public function test_mixed_content_sequence(): void
    {
        $tpl = <<<TPL
{{-- c --}}
@{{ literal }}
Hello {{ \$name }}
{!! \$html !!}
@php \$x = 42; @endphp
TPL;
        $out = $this->compile($tpl);

        $this->assertStringNotContainsString('{{--', $out);                     // comments gone
        $this->assertStringContainsString('{{ literal }}', $out);               // literal survives
        $this->assertStringContainsString('<?= safeEscape($name) ?>', $out);    // escaped
        $this->assertStringContainsString('<?= $html ?>', $out);                // raw
        $this->assertStringContainsString('<?php $x = 42; ?>', $out);           // php block
    }

    public function test_security_escaped_path_selected(): void
    {
        $out = $this->compile('{{ "<img src=x onerror=1>" }}');
        $this->assertStringContainsString('<?= safeEscape("<img src=x onerror=1>") ?>', $out);
    }

    public function test_security_raw_opt_out_selected(): void
    {
        $out = $this->compile('{!! "<em>ok</em>" !!}');
        $this->assertStringContainsString('<?= "<em>ok</em>" ?>', $out);
        $this->assertStringNotContainsString('safeEscape', $out);
    }

    public function test_no_compile_inside_raw_php_tag_multiline(): void
    {
        $tpl = <<<'PHP'
<?php
$tpl = "
Line 1
{{ $name }}
Line 3
";
?>
PHP;
        $out = $this->compile($tpl);

        $this->assertSame($tpl, $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    public function test_short_echo_tag_preserved(): void
    {
        $tpl = 'Value: <?= $name ?>';
        $out = $this->compile($tpl);

        $this->assertSame($tpl, $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    public function test_no_compile_inside_atphp_block_single_line(): void
    {
        $tpl = '@php $x = "{{ $name }}"; @endphp';
        $out = $this->compile($tpl);

        $this->assertSame('<?php $x = "{{ $name }}"; ?>', $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    public function test_no_compile_inside_atphp_block_multiline(): void
    {
        $tpl = <<<'TPL'
@php
    $json = "{
      \"greeting\": \"Hi\",
      \"template\": \"{{ $name }}\"
    }";
@endphp
TPL;
        $out = $this->compile($tpl);

        $expected = <<<'PHP'
<?php $json = "{
      \"greeting\": \"Hi\",
      \"template\": \"{{ $name }}\"
    }"; ?>
PHP;
        $this->assertSame($expected, $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    public function test_comments_inside_php_are_not_stripped(): void
    {
        $tpl = '<?php $s = "{{-- not a real template comment in PHP string --}}"; ?>';
        $out = $this->compile($tpl);

        $this->assertSame($tpl, $out);
        $this->assertStringNotContainsString('safeEscape(', $out);
    }

    public function test_literals_inside_php_not_touched(): void
    {
        $tpl = '<?php $s = "@{{ stays literal }} and @{!! stays raw !!}"; ?>';
        $out = $this->compile($tpl);

        $this->assertSame($tpl, $out);
    }

    public function test_mixed_php_and_template_only_outside_compiles(): void
    {
        $tpl = <<<'TPL'
<p>Hi {{ $name }}</p>
<?php $debug = "{{ $shouldNotCompile }}"; ?>
<p>Age: {!! $ageHtml !!}</p>
TPL;
        $out = $this->compile($tpl);

        // Outside PHP: escaped and raw replacements should occur
        $this->assertStringContainsString('<p>Hi <?= safeEscape($name) ?></p>', $out);
        $this->assertStringContainsString('<p>Age: <?= $ageHtml ?></p>', $out);

        // Inside PHP: must remain untouched
        $this->assertStringContainsString('<?php $debug = "{{ $shouldNotCompile }}"; ?>', $out);
        $this->assertStringNotContainsString('safeEscape($shouldNotCompile)', $out);
    }

    public function test_useLayout_compiles_with_single_quotes(): void
    {
        $out = $this->compile("@useLayout ('layouts/app')");
        $this->assertSame("<?php \$this->useLayout('layouts/app'); ?>", $out);
    }

    public function test_useLayout_compiles_with_double_quotes(): void
    {
        $out = $this->compile('@useLayout( "layouts/main" )');
        $this->assertSame("<?php \$this->useLayout('layouts/main'); ?>", $out);
    }

    public function test_slot_compiles_with_single_quotes(): void
    {
        $out = $this->compile("@slot('title')");
        $this->assertSame("<?= \$this->slot('title') ?>", $out);
    }

    public function test_slot_compiles_with_double_quotes(): void
    {
        $out = $this->compile('@slot  ( "title"   )');
        $this->assertSame("<?= \$this->slot('title') ?>", $out);
    }

    public function test_fill_block_compiles_to_start_and_end_calls_single_line(): void
    {
        $out = $this->compile("@fill('sidebar')<p>X</p>@endfill");

        $this->assertStringContainsString("<?php \$this->start('sidebar'); ?>", $out);
        $this->assertStringContainsString('<p>X</p>', $out);
        $this->assertStringContainsString('<?php $this->end(); ?>', $out);
    }

    public function test_fill_block_compiles_to_start_and_end_calls_multiline(): void
    {
        $src = <<<TPL
@fill("main-content")
<div>
  <h2>Hello</h2>
  <p>World</p>
</div>
@endfill
TPL;

        $out = $this->compile($src);

        $this->assertStringContainsString("<?php \$this->start('main-content'); ?>", $out);
        $this->assertStringContainsString('<h2>Hello</h2>', $out);
        $this->assertStringContainsString('<p>World</p>', $out);
        $this->assertStringContainsString('<?php $this->end(); ?>', $out);
    }

    public function test_fill_directive_with_slot_and_value(): void
    {
        $out = $this->compile("@fill('heading', '<h1>Track PHP</h1>')");

        $this->assertStringContainsString("<?php \$this->start('heading'); ?>", $out);
        $this->assertStringContainsString('<h1>Track PHP</h1>', $out);
        $this->assertStringContainsString('<?php $this->end(); ?>', $out);
    }

    public function test_fill_directive_with_slot_and_value_using_double_quotes(): void
    {
        $out = $this->compile('@fill("heading", "<h1>Track PHP</h1>")');

        $this->assertStringContainsString("<?php \$this->start('heading'); ?>", $out);
        $this->assertStringContainsString('<h1>Track PHP</h1>', $out);
        $this->assertStringContainsString('<?php $this->end(); ?>', $out);
    }

    public function test_layout_slot_fill_are_not_compiled_inside_php_blocks(): void
    {
        $src = <<<PHPBLOCK
@php
  // Inside raw PHP; leave as-is
  @useLayout('layouts/ignored')
  @fill('ignored') echo "nope"; @endfill
  @slot('ignored')
@endphp
PHPBLOCK;

        $out = $this->compile($src);

        // Should still contain the literal directives (i.e., untouched)
        $this->assertStringContainsString("@useLayout('layouts/ignored')", $out);
        $this->assertStringContainsString("@fill('ignored')", $out);
        $this->assertStringContainsString('@endfill', $out);
        $this->assertStringContainsString("@slot('ignored')", $out);
    }

    public function test_directives_allow_double_quoted_names_too(): void
    {
        $out = $this->compile('@slot ("subtitle") @fill( "subtitle")text@endfill');
        $this->assertStringContainsString("<?= \$this->slot('subtitle') ?>", $out);
        $this->assertStringContainsString("<?php \$this->start('subtitle'); ?>", $out);
        $this->assertStringContainsString('text', $out);
        $this->assertStringContainsString('<?php $this->end(); ?>', $out);
    }

    public function test_fill_single_line_allows_commas_and_parentheses(): void
    {
        $out = $this->compile("@fill('title', 'Hello, world (v2)')");
        $this->assertStringContainsString("<?php \$this->start('title'); ?>", $out);
        $this->assertStringContainsString('Hello, world (v2)', $out);
        $this->assertStringContainsString('<?php $this->end(); ?>', $out);
    }

    public function test_fill_single_line_allows_escaped_single_quote_in_single_quoted_content(): void
    {
        $out = $this->compile("@fill('title', 'Bob\\'s Page')");
        // Expect the compiled content to contain Bob's Page with the escape removed
        $this->assertStringContainsString("Bob's Page", $out);
    }

    public function test_fill_single_line_allows_escaped_double_quote_in_double_quoted_content(): void
    {
        $out = $this->compile('@fill("title", "He said: \\"Hello\\"")');
        $this->assertStringContainsString('He said: "Hello"', $out);
    }

}
