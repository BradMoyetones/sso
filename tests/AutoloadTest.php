<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifica que cada archivo de src/ se pueda cargar por PSR-4 con el nombre que
 * dicta su ruta, para que un renombrado incompleto no llegue a las apps.
 */
class AutoloadTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sourceClasses(): array
    {
        $src = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        $classes = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($src) + 1, -4);
            $class = 'Fpjd\\Sso\\' . str_replace('/', '\\', $relative);
            $classes[$class] = [$class];
        }

        ksort($classes);

        return $classes;
    }

    #[DataProvider('sourceClasses')]
    public function testClassIsAutoloadable(string $class): void
    {
        $this->assertTrue(
            class_exists($class) || interface_exists($class) || trait_exists($class),
            "No se pudo cargar $class por PSR-4."
        );
    }
}
