<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Generador mínimo de PDF para reportes en tabla (A4 horizontal, Helvetica estándar, sin dependencias externas).
 * Texto en Windows-1252 (tildes y ñ), encabezado repetido en cada hoja, filas con salto de línea y «Página X de Y».
 * Se eligió escribirlo a mano para no añadir librerías con licencias que condicionen la comercialización.
 */
final class SimplePdf
{
    public const MAX_ROWS = 5000;

    private const PAGE_W = 841.89;

    private const PAGE_H = 595.28;

    private const MARGIN = 36.0;

    private const SIZE = 8.0;

    private const LEADING = 10.0;

    private const MAX_LINES = 3;

    private const REGULAR = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 750, 556, 556, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 556, 611, 556, 556, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 556, 500, 667, 278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 0, 737, 552, 400, 549, 333, 333, 333, 576, 537, 333, 333, 333, 365, 556, 834, 834, 834, 611, 667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278, 556, 556, 556, 556, 556, 556, 556, 549, 611, 556, 556, 556, 556, 500, 556, 500];

    private const BOLD = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 750, 556, 556, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 556, 611, 556, 556, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 556, 500, 667, 278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 0, 737, 552, 400, 549, 333, 333, 333, 576, 556, 333, 333, 333, 365, 556, 834, 834, 834, 611, 722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278, 611, 611, 611, 611, 611, 611, 611, 549, 611, 611, 611, 611, 611, 556, 611, 556];

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     */
    public static function table(string $title, string $subtitle, array $headers, iterable $rows): string
    {
        $head = array_map(self::enc(...), $headers);
        $body = [];
        $truncated = false;
        foreach ($rows as $row) {
            if (count($body) >= self::MAX_ROWS) {
                $truncated = true;

                break;
            }
            $cells = [];
            foreach ($head as $i => $_) {
                $cells[] = self::enc((string) ($row[$i] ?? ''));
            }
            $body[] = $cells;
        }
        if ($truncated) {
            $subtitle .= ' · Se muestran las primeras '.self::MAX_ROWS.' filas; descargue el CSV para ver todas.';
        }

        $widths = self::columnWidths($head, $body);
        $pages = [];
        $page = '';
        $y = 0.0;
        $newPage = function () use (&$page, &$pages, &$y, $head, $widths, $title, $subtitle): void {
            if ($page !== '') {
                $pages[] = $page;
            }
            $page = '';
            $y = self::PAGE_H - self::MARGIN;
            if ($pages === []) {
                $page .= self::text(self::MARGIN, $y - 12, self::enc($title), true, 14.0);
                $page .= self::text(self::MARGIN, $y - 26, self::enc($subtitle), false, 8.5, [0.35, 0.35, 0.35]);
                $y -= 40;
            }
            $page .= self::headerRow($head, $widths, $y);
            $y -= self::LEADING + 6;
        };
        $newPage();

        foreach ($body as $cells) {
            $lines = [];
            $height = 0;
            foreach ($cells as $i => $cell) {
                $lines[$i] = self::wrap($cell, $widths[$i] - 8, false);
                $height = max($height, count($lines[$i]));
            }
            $rowH = $height * self::LEADING + 6;
            if ($y - $rowH < self::MARGIN + 14) {
                $newPage();
            }
            $x = self::MARGIN;
            foreach ($lines as $i => $cellLines) {
                $right = preg_match('/^-?[\d.,]+%?$/', $cells[$i]) === 1;
                foreach ($cellLines as $n => $line) {
                    $tx = $right ? $x + $widths[$i] - 4 - self::width($line, false) : $x + 4;
                    $page .= self::text($tx, $y - 8 - $n * self::LEADING, $line, false, self::SIZE);
                }
                $x += $widths[$i];
            }
            $y -= $rowH;
            $page .= self::line(self::MARGIN, $y, array_sum($widths) + self::MARGIN, $y);
        }
        if ($body === []) {
            $page .= self::text(self::MARGIN + 4, $y - 10, self::enc('Sin registros para los filtros aplicados.'), false, self::SIZE, [0.35, 0.35, 0.35]);
        }
        $pages[] = $page;

        $total = count($pages);
        foreach ($pages as $n => $content) {
            $footer = self::enc('Página '.($n + 1).' de '.$total);
            $pages[$n] = $content
                .self::text(self::PAGE_W - self::MARGIN - self::width($footer, false, 8.0), 20, $footer, false, 8.0, [0.35, 0.35, 0.35])
                .self::text(self::MARGIN, 20, self::enc('WMS City Ofertas'), false, 8.0, [0.35, 0.35, 0.35]);
        }

        return self::assemble($pages, self::enc($title));
    }

    /** UTF-8 a Windows-1252; lo que no existe en ese juego de caracteres se muestra como «?». */
    private static function enc(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B-\x1F]/', ' ', str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value)) ?? $value;

        return (string) mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    }

    private static function width(string $text, bool $bold, float $size = self::SIZE): float
    {
        $table = $bold ? self::BOLD : self::REGULAR;
        $sum = 0;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $sum += $table[ord($text[$i])];
        }

        return $sum * $size / 1000;
    }

    /**
     * @param  list<string>  $head
     * @param  list<list<string>>  $body
     * @return list<float>
     */
    private static function columnWidths(array $head, array $body): array
    {
        $avail = self::PAGE_W - 2 * self::MARGIN;
        $natural = [];
        foreach ($head as $i => $h) {
            $w = self::width($h, true);
            foreach ($body as $cells) {
                $w = max($w, self::width($cells[$i], false));
            }
            $natural[$i] = min(max($w + 10, 42.0), 230.0);
        }
        $sum = array_sum($natural);
        $scale = $sum > 0 ? $avail / $sum : 1.0;
        if ($scale < 1.0) {
            // No cabe: todas las columnas se reducen; el texto largo pasa a varias líneas.
            return array_map(static fn (float $w): float => max($w * $scale, 30.0), $natural);
        }

        return array_map(static fn (float $w): float => $w * $scale, $natural);
    }

    /**
     * @return list<string>
     */
    private static function wrap(string $text, float $maxWidth, bool $bold): array
    {
        if ($text === '') {
            return [''];
        }
        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (self::width($candidate, $bold) <= $maxWidth) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            // Palabra más ancha que la columna: se parte por caracteres.
            while (self::width($word, $bold) > $maxWidth && strlen($word) > 1) {
                $cut = strlen($word) - 1;
                while ($cut > 1 && self::width(substr($word, 0, $cut), $bold) > $maxWidth) {
                    $cut--;
                }
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $current = $word;
        }
        $lines[] = $current;

        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES);
            $lines[self::MAX_LINES - 1] = rtrim(substr($lines[self::MAX_LINES - 1], 0, max(strlen($lines[self::MAX_LINES - 1]) - 3, 1))).'...';
        }

        return $lines;
    }

    /**
     * @param  list<string>  $head
     * @param  list<float>  $widths
     */
    private static function headerRow(array $head, array $widths, float $y): string
    {
        $h = self::LEADING + 6;
        $out = sprintf("q 0.129 0.298 0.486 rg %.2f %.2f %.2f %.2f re f Q\n", self::MARGIN, $y - $h, array_sum($widths), $h);
        $x = self::MARGIN;
        foreach ($head as $i => $label) {
            $line = self::wrap($label, $widths[$i] - 8, true)[0];
            $out .= self::text($x + 4, $y - 9, $line, true, self::SIZE, [1.0, 1.0, 1.0]);
            $x += $widths[$i];
        }

        return $out;
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    private static function text(float $x, float $y, string $text, bool $bold, float $size, array $rgb = [0.0, 0.0, 0.0]): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return sprintf("BT /%s %.1f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, $escaped);
    }

    private static function line(float $x1, float $y1, float $x2, float $y2): string
    {
        return sprintf("q 0.85 G 0.5 w %.2f %.2f m %.2f %.2f l S Q\n", $x1, $y1, $x2, $y2);
    }

    /** @param list<string> $pages contenido de cada hoja */
    private static function assemble(array $pages, string $title): string
    {
        $objects = [];
        $pageIds = [];
        $nextId = 5;
        foreach ($pages as $content) {
            $pageIds[] = $nextId;
            $objects[$nextId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_W,
                self::PAGE_H,
                $nextId + 1,
            );
            $objects[$nextId + 1] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
            $nextId += 2;
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageIds)).'] /Count '.count($pageIds).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[$nextId] = '<< /Title ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $title).') /Producer (WMS City Ofertas) >>';
        $infoId = $nextId;
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $size = max(array_keys($objects)) + 1;
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $out .= "trailer\n<< /Size {$size} /Root 1 0 R /Info {$infoId} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }
}
