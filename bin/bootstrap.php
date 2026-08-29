<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader. Composer nie jest wymagany do uruchomienia MVP.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Milczenie\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
