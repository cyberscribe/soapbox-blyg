<?php
/**
 * @license MIT
 *
 * Modified by cyberscribe on 06-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace SoapboxBlyg\Vendor\League\HTMLToMarkdown;

interface ConfigurationAwareInterface
{
    public function setConfig(Configuration $config): void;
}
