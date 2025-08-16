<?php

declare(strict_types=1);

namespace TrackPHP\View;

final class ViewCompiler
{
    private array $phpTags = [];
    private array $phpBlocks = [];

    public function compile(string $code): string
    {
        $this->resetPlaceholders();
        $code = $this->protectRawPhpTags($code);
        $code = $this->protectPhpDirectiveBlocks($code);
        $code = $this->protectLiteralDelimiters($code);
        $code = $this->stripTemplateComments($code);
        $code = $this->compileEscapedEchos($code);
        $code = $this->compileRawEchos($code);
        $code = $this->compileUseLayoutDirective($code);
        $code = $this->compileFillSingleLine($code);
        $code = $this->compileFillBlocks($code);
        $code = $this->compileSlotEchos($code);
        $code = $this->restoreLiteralDelimiters($code);
        $code = $this->restorePhpDirectiveBlocks($code);
        $code = $this->restoreRawPhpTags($code);
        return $code;
    }

    private function resetPlaceholders(): void
    {
        $this->phpTags = [];
        $this->phpBlocks = [];
    }

    private function protectRawPhpTags(string $code): string
    {
        $regex = '/<\?(?!xml)(?:php)?[\s\S]*?\?>/i';

        $out = preg_replace_callback(
            $regex,
            function (array $m): string {
                $key = "\0__PHP_TAG__" . count($this->phpTags) . "__\0";
                $this->phpTags[$key] = $m[0];
                return $key;
            },
            $code
        );

        return is_string($out) ? $out : $code;
    }

    private function protectPhpDirectiveBlocks(string $code): string
    {
        $regex = '/@php\s*(.*?)\s*@endphp/s';

        $out = preg_replace_callback(
            $regex,
            function (array $m): string {
                $key = "\0__PHP_BLOCK__" . count($this->phpBlocks) . "__\0";
                $this->phpBlocks[$key] = "<?php {$m[1]} ?>";
                return $key;
            },
            $code
        );

        return is_string($out) ? $out : $code;
    }

    private function protectLiteralDelimiters(string $code): string
    {
        return str_replace(['@{{', '@{!!'], ["\0__LIT_DBOPEN__", "\0__LIT_RAWOPEN__"], $code);
    }

    private function stripTemplateComments(string $code): string
    {
        return preg_replace('/\{\{\-\-.*?\-\-\}\}/s', '', $code);
    }

    private function compileEscapedEchos(string $code): string
    {
        $out = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            static fn ($m) => '<?= safeEscape(' . $m[1] . ') ?>',
            $code
        );

        return is_string($out) ? $out : $code;
    }

    private function compileRawEchos(string $code): string
    {
        $out = preg_replace_callback(
            '/\{\!\!\s*(.+?)\s*\!\!\}/s',
            static fn ($m) => '<?= ' . $m[1] . ' ?>',
            $code
        );

        return is_string($out) ? $out : $code;
    }

    private function compileUseLayoutDirective(string $code): string
    {
        $out = preg_replace(
            "/@useLayout\s*\(\s*('|\")([^'\"]+)\\1\s*\)/",
            "<?php \$this->useLayout('$2'); ?>",
            $code
        );

        return is_string($out) ? $out : $code;
    }

    private function compileFillSingleLine(string $code): string
    {
        // 1) Single-quoted content
        $code = preg_replace_callback(
            // @fill('name', '...') — allow escaped \' and \\
            "/@fill\\s*\\(\\s*('|\")([\\w\\-:]+)\\1\\s*,\\s*'((?:\\\\.|[^'\\\\])*)'\\s*\\)/",
            function (array $m): string {
                $name = $m[2];
                $content = $m[3];
                // Unescape: \' -> ', \\ -> \
                $content = str_replace(["\\'", '\\\\'], ["'", '\\'], $content);
                return "<?php \$this->start('{$name}'); ?>{$content}<?php \$this->end(); ?>";
            },
            $code
        );

        // 2) Double-quoted content
        $code = preg_replace_callback(
            // @fill("name", "...") — allow escaped \" and \\
            '/@fill\s*\(\s*(\'|")([\w\-:]+)\1\s*,\s*"((?:\\\\.|[^"\\\\])*)"\s*\)/',
            function (array $m): string {
                $name = $m[2];
                $content = $m[3];
                // Unescape: \" -> ", \\ -> \
                $content = str_replace(['\\"', '\\\\'], ['"', '\\'], $content);
                return "<?php \$this->start('{$name}'); ?>{$content}<?php \$this->end(); ?>";
            },
            $code
        );

        return $code;
    }

    private function compileFillBlocks(string $code): string
    {
        $out = preg_replace(
            "/@fill\s*\(\s*('|\")([\w\-:]+)\\1\s*\)(.*?)@endfill/s",
            "<?php \$this->start('$2'); ?>$3<?php \$this->end(); ?>",
            $code
        );

        return is_string($out) ? $out : $code;
    }

    private function compileSlotEchos(string $code): string
    {
        $out = preg_replace(
            "/@slot\s*\(\s*('|\")([\w\-:]+)\\1\s*\)/",
            "<?= \$this->slot('$2') ?>",
            $code
        );

        return is_string($out) ? $out : $code;
    }

    private function restoreLiteralDelimiters(string $code): string
    {
        return str_replace(["\0__LIT_DBOPEN__", "\0__LIT_RAWOPEN__"], ['{{', '{!!'], $code);
    }

    private function restorePhpDirectiveBlocks(string $code): string
    {
        return $this->phpBlocks ? strtr($code, $this->phpBlocks) : $code;
    }

    private function restoreRawPhpTags(string $code): string
    {
        return $this->phpTags ? strtr($code, $this->phpTags) : $code;
    }
}
