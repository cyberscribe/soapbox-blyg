<?php
/**
 * @license MIT
 *
 * Modified by cyberscribe on 06-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace SoapboxBlyg\Vendor\League\HTMLToMarkdown;

/**
 * @internal
 */
final class Coerce
{
    private function __construct()
    {
    }

    /**
     * @param mixed $val
     */
    public static function toString($val): string
    {
        switch (true) {
            case \is_string($val):
                return $val;
            case \is_bool($val):
            case \is_float($val):
            case \is_int($val):
            case $val === null:
                return \strval($val);
            case \is_object($val) && \method_exists($val, '__toString'):
                return (string) $val;
            default:
                throw new \InvalidArgumentException('Cannot coerce this value to string');
        }
    }
}
