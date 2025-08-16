<?php

declare(strict_types=1);

namespace TrackPHP\Support;

/**
 * HTML-escape for safe text nodes and attributes.
 */
function safeEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

/**
 * Safer way to embed data into JS contexts.
 */
function safeJs(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
