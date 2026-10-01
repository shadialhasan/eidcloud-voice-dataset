<?php

declare(strict_types=1);

/**
 * PSR-4 autoloader for EidCloud\VoiceDataset
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'EidCloud\\VoiceDataset\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
