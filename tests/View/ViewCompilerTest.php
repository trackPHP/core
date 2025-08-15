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

    /** 1) Escaped interpolation: {{ $name }} -> <?= safeEscape($name) ?> */
    public function test_compiles_escaped_interpolation(): void
    {
        $out = $this->compile('Hello, {{ $name }}!');
        $this->assertStringContainsString('<?= safeEscape($name) ?>', $out);
    }

    /** 2) Raw interpolation: {!! $html !!} -> <?= $html ?> (no escaping) */
    public function test_compiles_raw_interpolation(): void
    {
        $out = $this->compile('X {!! $html !!} Y');
        $this->assertStringContainsString('<?= $html ?>', $out);
        $this->assertStringNotContainsString('safeEscape($html)', $out);
    }

    /** 3) Whitespace tolerance around expressions */
    public function test_whitespace_tolerance(): void
    {
        $out = $this->compile('Hello, {{    $name   }}!');
        $this->assertStringContainsString('<?= safeEscape($name) ?>', $out);
        $out = $this->compile('Hello, {{$name}}!');
        $this->assertStringContainsString('<?= safeEscape($name) ?>', $out);
    }

    /** 4) Comments stripped: {{-- hidden --}} removed entirely */
    public function test_strips_blade_style_comments(): void
    {
        $out = $this->compile('A {{-- hidden --}} B');
        $this->assertSame('A  B', $out);
    }

    /** 5) Literal braces via @{{ ... }} are preserved as {{ ... }} (not compiled) */
    public function test_literal_braces_not_compiled(): void
    {
        $out = $this->compile('X @{{ $notInterpolated }} Y');
        $this->assertStringContainsString('{{ $notInterpolated }}', $out);
        $this->assertStringNotContainsString('safeEscape($notInterpolated)', $out);
    }

    /** 6) @php ... @endphp passthrough to <?php ... ?> */
    public function test_php_block_passthrough(): void
    {
        $out = $this->compile('Start @php $x = 1 + 2; @endphp End');
        $this->assertStringContainsString('<?php $x = 1 + 2; ?>', $out);
    }

    /** 7) No compile inside @php blocks */
    public function test_no_compile_inside_php_blocks(): void
    {
        $out = $this->compile('@php $x = "{{ $name }}"; @endphp');
        // The inner braces should remain untouched in the PHP string literal
        $this->assertStringContainsString('<?php $x = "{{ $name }}"; ?>', $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    /** 8) Multiple {{ ... }} on one line compile independently (non-greedy) */
    public function test_multiple_interpolations_non_greedy(): void
    {
        $out = $this->compile('Hi {{ $a }} and {{ $b }}!');
        $this->assertStringContainsString('safeEscape($a)', $out);
        $this->assertStringContainsString('safeEscape($b)', $out);
    }

    /** 9) Mixed content order compiles correctly (comments, literals, escaped, raw, php) */
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

    /** 10) SECURITY: {{ "<img onerror=1>" }} compiles via safeEscape (prevents XSS at runtime) */
    public function test_security_escaped_path_selected(): void
    {
        $out = $this->compile('{{ "<img src=x onerror=1>" }}');
        $this->assertStringContainsString('<?= safeEscape("<img src=x onerror=1>") ?>', $out);
    }

    /** 11) SECURITY: {!! "<em>ok</em>" !!} compiles to raw echo (developer opt-out) */
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

    /** Short echo tag should be preserved as-is */
    public function test_short_echo_tag_preserved(): void
    {
        $tpl = 'Value: <?= $name ?>';
        $out = $this->compile($tpl);

        $this->assertSame($tpl, $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    /** @php block (single line): braces inside PHP string must NOT compile */
    public function test_no_compile_inside_atphp_block_single_line(): void
    {
        $tpl = '@php $x = "{{ $name }}"; @endphp';
        $out = $this->compile($tpl);

        $this->assertSame('<?php $x = "{{ $name }}"; ?>', $out);
        $this->assertStringNotContainsString('safeEscape($name)', $out);
    }

    /** @php block (multi-line): braces inside PHP string must NOT compile */
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

    /** Comments inside PHP should NOT be stripped */
    public function test_comments_inside_php_are_not_stripped(): void
    {
        $tpl = '<?php $s = "{{-- not a real blade comment in PHP string --}}"; ?>';
        $out = $this->compile($tpl);

        $this->assertSame($tpl, $out);
        $this->assertStringNotContainsString('safeEscape(', $out);
    }

    /** Literal markers inside PHP should not be touched */
    public function test_literals_inside_php_not_touched(): void
    {
        $tpl = '<?php $s = "@{{ stays literal }} and @{!! stays raw !!}"; ?>';
        $out = $this->compile($tpl);

        $this->assertSame($tpl, $out);
    }

    /** Mixed: outside PHP should compile; inside PHP should not */
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
}
