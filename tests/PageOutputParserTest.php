<?php

declare(strict_types=1);

use Muchwat\OpendataloaderPdf\Parsing\PageOutputParser;

function pageOutputParser(): PageOutputParser
{
    return new PageOutputParser;
}

it('returns markerless output as one trimmed page', function () {
    expect(pageOutputParser()->parse("  # Title\n\nBody  \n", 'PAGE_', '_END'))
        ->toBe(["# Title\n\nBody"]);
});

it('preserves preamble content and blank physical pages', function () {
    $output = implode("\n", [
        'Preamble',
        'PAGE_1_END',
        'First',
        'PAGE_2_END',
        '',
        'PAGE_3_END',
        'Third',
        'PAGE_4_END',
    ]);

    expect(pageOutputParser()->parse($output, 'PAGE_', '_END'))
        ->toBe(['Preamble', 'First', '', 'Third', '']);
});

it('supports CRLF markers and surrounding horizontal whitespace', function () {
    $output = " \tPAGE_1_END \t\r\nFirst\r\n\tPAGE_2_END\r\nSecond\r\n";

    expect(pageOutputParser()->parse($output, 'PAGE_', '_END'))
        ->toBe(['First', 'Second']);
});

it('quotes regular expression characters in marker components', function () {
    $output = "PREFIX[1]+1(SUFFIX)\nFirst\nPREFIX[1]+2(SUFFIX)\nSecond";

    expect(pageOutputParser()->parse($output, 'PREFIX[1]+', '(SUFFIX)'))
        ->toBe(['First', 'Second']);
});

it('does not split inline or non-numbered marker-like content', function () {
    $output = "Body PAGE_1_END inline\nPAGE_one_END\nstill one page";

    expect(pageOutputParser()->parse($output, 'PAGE_', '_END'))
        ->toBe([$output]);
});
