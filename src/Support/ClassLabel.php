<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use ReflectionClass;

/**
 * The class name a harness may write about an object it did not create: in a
 * framed subagent failure, a RunFailed event and a failed run's record
 * (G-387).
 *
 * A NAMED class only. PHP names an anonymous class after the file and line
 * that declared it (`RuntimeException@anonymous` then a NUL byte, the
 * server's absolute path, the line and a counter), and that text reaches the
 * parent model and the next provider request. An anonymous class is labelled
 * by the nearest named class it extends, else the first interface it
 * implements, else a fixed text; the result is always cut at the first NUL
 * and must be shaped like a PHP class name, else it is the fixed text.
 */
final class ClassLabel
{
    /** What an object whose class has no name it may be labelled by reads as. */
    public const ANONYMOUS = 'an anonymous class';

    public static function of(object $value): string
    {
        $class = new ReflectionClass($value);
        while ($class->isAnonymous()) {
            $parent = $class->getParentClass();
            if ($parent === false) {
                $interfaces = $class->getInterfaceNames();

                return $interfaces === [] ? self::ANONYMOUS : self::named($interfaces[0]);
            }
            $class = $parent;
        }

        return self::named($class->getName());
    }

    private static function named(string $name): string
    {
        $name = explode("\0", $name, 2)[0];

        return preg_match('/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/D', $name) === 1
            ? $name : self::ANONYMOUS;
    }
}
