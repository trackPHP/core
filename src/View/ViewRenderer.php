<?php

namespace TrackPHP\View;

interface ViewRenderer {
    public function render(string $template, array $data): string;
}
