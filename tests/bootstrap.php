<?php declare(strict_types=1);

// PSR-4 autoloader for local runs without Composer. CI uses the Composer
// autoloader; both resolve Beliq\Shopware\ to src/ and the test namespace to
// tests/. Shopware\Core\* is not mapped here: the mapper and client tests never
// touch the runtime classes, so no Shopware install is needed to run them.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Beliq\\Shopware\\Tests\\' => __DIR__ . '/',
        'Beliq\\Shopware\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
