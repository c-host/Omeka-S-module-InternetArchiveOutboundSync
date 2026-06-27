<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaIdentifierParser
{
    public function parse(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (preg_match('~^https?://archive\.org/details/([^?#]+)~i', $line, $m)) {
            return IaPath::normalize(rawurldecode($m[1]));
        }
        if (preg_match('~^https?://archive\.org/embed/([^?#]+)~i', $line, $m)) {
            return IaPath::normalize(rawurldecode($m[1]));
        }
        if (preg_match('~^https?://archive\.org/metadata/([^?#]+)~i', $line, $m)) {
            return IaPath::normalize(rawurldecode($m[1]));
        }
        if (preg_match('~^ia:(.+)$~i', $line, $m)) {
            return IaPath::normalize($m[1]);
        }
        if (preg_match('~\s~', $line)) {
            return null;
        }

        return IaPath::normalize($line);
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    public function fromItem($item): ?string
    {
        foreach ($item->value('dcterms:identifier', ['all' => true]) ?: [] as $value) {
            $text = trim((string) $value->value());
            if (str_starts_with($text, 'ia-media:')) {
                continue;
            }
            $parsed = $this->parse($text);
            if ($parsed) {
                return $parsed;
            }
        }
        return null;
    }
}
