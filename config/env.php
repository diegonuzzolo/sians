<?php
function env($key, $default = null) {
    static $vars;
    if ($vars === null) {
        $vars = [];
        $path = dirname(__FILE__) . '/../.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with($line, '#')) continue;
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $vars[$k] = $v;
            }
        }
    }
    return $vars[$key] ?? $default;
}
