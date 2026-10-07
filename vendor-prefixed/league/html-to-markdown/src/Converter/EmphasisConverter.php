<?php
/**
 * @license MIT
 *
 * Modified by cyberscribe on 06-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace SoapboxBlyg\Vendor\League\HTMLToMarkdown\Converter;

use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Coerce;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\Configuration;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ConfigurationAwareInterface;
use SoapboxBlyg\Vendor\League\HTMLToMarkdown\ElementInterface;

class EmphasisConverter implements ConverterInterface, ConfigurationAwareInterface
{
    /** @var Configuration */
    protected $config;

    protected function getNormTag(?ElementInterface $element): string
    {
        if ($element !== null && ! $element->isText()) {
            $tag = $element->getTagName();
            if ($tag === 'i' || $tag === 'em') {
                return 'em';
            }

            if ($tag === 'b' || $tag === 'strong') {
                return 'strong';
            }
        }

        return '';
    }

    public function setConfig(Configuration $config): void
    {
        $this->config = $config;
    }

    public function convert(ElementInterface $element): string
    {
        $tag   = $this->getNormTag($element);
        $value = $element->getValue();

        if (! \trim($value)) {
            return $value;
        }

        if ($tag === 'em') {
            $style = Coerce::toString($this->config->getOption('italic_style'));
        } else {
            $style = Coerce::toString($this->config->getOption('bold_style'));
        }

        $prefix = \ltrim($value) !== $value ? ' ' : '';
        $suffix = \rtrim($value) !== $value ? ' ' : '';

        /* If this node is immediately preceded or followed by one of the same type don't emit
         * the start or end $style, respectively. This prevents <em>foo</em><em>bar</em> from
         * being converted to *foo**bar* which is incorrect. We want *foobar* instead.
         */
        $preStyle  = $this->getNormTag($element->getPreviousSibling()) === $tag ? '' : $style;
        $postStyle = $this->getNormTag($element->getNextSibling()) === $tag ? '' : $style;

        return $prefix . $preStyle . \trim($value) . $postStyle . $suffix;
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['em', 'i', 'strong', 'b'];
    }
}
