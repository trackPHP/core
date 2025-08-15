<?php
declare(strict_types=1);

namespace TrackPHP\View;

final class ViewCompiler
{
    public function compile(string $content): string
    {
        // Extract RAW PHP TAGS
        $phpTags = [];
        $content = preg_replace_callback('/<\?(?!xml)(?:php)?[\s\S]*?\?>/i', function ($m) use (&$phpTags) {
            $key = "\0__PHP_TAG__" . count($phpTags) . "__\0";
            $phpTags[$key] = $m[0];               // keep exactly as-is
            return $key;
        }, $content);

        // Extract @php ... @endphp blocks
        $phpBlocks = [];
        $content = preg_replace_callback('/@php\s*(.*?)\s*@endphp/s', function ($m) use (&$phpBlocks) {
            $key = "\0__PHP_BLOCK__" . count($phpBlocks) . "__\0";
            $phpBlocks[$key] = "<?php {$m[1]} ?>";
            return $key;
        }, $content);

        // Protect escaped-literal sequences
        $content = str_replace(['@{{', '@{!!'], ["\0__LIT_DBOPEN__", "\0__LIT_RAWOPEN__"], $content);

        // Strip Blade-like comments {{-- ... --}}
        $content = preg_replace('/\{\{\-\-.*?\-\-\}\}/s', '', $content);

        // Escaped output: {{ ... }}
        $content = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s',
            static fn($m) => '<?= safeEscape(' . $m[1] . ') ?>',
            $content
        );

        // Raw output: {!! ... !!}
        $content = preg_replace_callback('/\{\!\!\s*(.+?)\s*\!\!\}/s',
            static fn($m) => '<?= ' . $m[1] . ' ?>',
            $content
        );

        // Restore literals
        $content = str_replace(["\0__LIT_DBOPEN__", "\0__LIT_RAWOPEN__"], ['{{', '{!!'], $content);

        // Restore @php blocks and raw PHP tags
        if ($phpBlocks) $content = strtr($content, $phpBlocks);
        if ($phpTags)   $content = strtr($content, $phpTags);

        return $content;
    }
}
