<?php

declare(strict_types=1);

/**
 * 按 PSR-4 约定加载 src/ 下的 Verstamp 命名空间类。
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Verstamp\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});