<?php

declare(strict_types=1);

namespace Tests\Support;

/** Comprueba que la tabla de referencias cruzadas (xref) de un PDF apunta a cada objeto. */
final class PdfAssert
{
    public static function xrefIsValid(string $pdf): bool
    {
        if (preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m) !== 1) {
            return false;
        }
        $xref = (int) $m[1];
        if (substr($pdf, $xref, 5) !== "xref\n" || preg_match('/^xref\n0 (\d+)\n/', substr($pdf, $xref), $h) !== 1) {
            return false;
        }
        $entries = array_slice(explode("\n", substr($pdf, $xref)), 2, (int) $h[1]);
        foreach ($entries as $id => $line) {
            if ($id === 0) {
                continue;
            }
            if (substr($pdf, (int) substr($line, 0, 10), strlen("{$id} 0 obj")) !== "{$id} 0 obj") {
                return false;
            }
        }

        return true;
    }
}
