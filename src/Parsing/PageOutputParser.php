<?php

declare(strict_types=1);

namespace Muchwat\OpendataloaderPdf\Parsing;

final class PageOutputParser
{
    /**
     * Split numbered page markers while retaining empty chunks so physical
     * blank pages remain represented in the result.
     *
     * @return list<string>
     */
    public function parse(string $output, string $markerPrefix, string $markerSuffix): array
    {
        $pattern = '/^[\t ]*'
            .preg_quote($markerPrefix, '/')
            .'\d+'
            .preg_quote($markerSuffix, '/')
            .'[\t ]*(?:\R|$)/m';

        $matchCount = preg_match_all($pattern, $output, $matches, PREG_OFFSET_CAPTURE);

        if ($matchCount === false || $matchCount === 0) {
            return [trim($output)];
        }

        $markers = $matches[0];
        $pages = [];
        $firstMarkerOffset = $markers[0][1];
        $preamble = trim(substr($output, 0, $firstMarkerOffset));

        if ($preamble !== '') {
            $pages[] = $preamble;
        }

        foreach ($markers as $index => [$marker, $offset]) {
            $contentStart = $offset + strlen($marker);
            $contentEnd = isset($markers[$index + 1])
                ? $markers[$index + 1][1]
                : strlen($output);

            $pages[] = trim(substr($output, $contentStart, $contentEnd - $contentStart));
        }

        return $pages;
    }
}
