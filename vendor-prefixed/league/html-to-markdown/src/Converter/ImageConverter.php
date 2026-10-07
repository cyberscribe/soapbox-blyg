<?php
/**
 * @license MIT
 *
 * Modified by cyberscribe on 06-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

class ImageConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $src   = $element->getAttribute('src');
        $alt   = $element->getAttribute('alt');
        $title = $element->getAttribute('title');

        if ($title !== '') {
            // No newlines added. <img> should be in a block-level element.
            return '![' . $alt . '](' . $src . ' "' . $title . '")';
        }

        return '![' . $alt . '](' . $src . ')';
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['img'];
    }
}
