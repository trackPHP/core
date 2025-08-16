<?php

declare(strict_types=1);

namespace TrackPHP\View;

class FakeViewRenderer implements ViewRenderer
{
    public string $lastTemplate = '';
    public array $lastData = [];

    public function render(string $template, array $data = []): string
    {
        $this->lastTemplate = $template;
        $this->lastData = $data;
        return "<fake>{$template}</fake>";
    }
}
