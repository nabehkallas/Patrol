<?php

namespace App\Support;

use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use Closure;
use Illuminate\Http\Response;

/**
 * One table, downloadable as PDF or Excel with the same columns. Cells hold raw values: the PDF
 * writes numbers with their decimals, the spreadsheet keeps them as real numbers that can be
 * summed and sorted.
 */
final class ExportTable
{
    /**
     * @param  string  $name  File name stem, e.g. "transactions" (the date and extension are added).
     * @param  list<string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     * @param  array<int, int|Closure(array<int, string|int|float|null>): int>  $decimals  Numeric columns: their decimals,
     *                                                                                     or a function of the row (an amount in that row's currency).
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly ?string $subtitle,
        private readonly array $headers,
        private readonly array $rows,
        private readonly array $decimals = [],
    ) {}

    public function pdf(PdfTableExporter $exporter): Response
    {
        $rows = array_map(function (array $row) {
            $cells = [];
            foreach ($row as $index => $cell) {
                $cells[] = match (true) {
                    $cell === null || $cell === '' => '—',
                    isset($this->decimals[$index]) && is_numeric($cell) => number_format((float) $cell, $this->decimalsFor($index, $row)),
                    default => (string) $cell,
                };
            }

            return $cells;
        }, $this->rows);
        $rows = array_values($rows);

        return $exporter->download(
            filename: $this->name.'-'.now()->format('Y-m-d').'.pdf',
            title: $this->title,
            subtitle: $this->subtitle,
            headers: $this->headers,
            rows: $rows,
            direction: Locales::direction(),
        );
    }

    public function xlsx(XlsxTableExporter $exporter): Response
    {
        $formats = [];
        foreach (array_keys($this->decimals) as $index) {
            // A per-row (per-currency) column shows two decimals; Excel can't vary it by row.
            $digits = is_int($this->decimals[$index]) ? $this->decimals[$index] : 2;
            $formats[$index] = $digits > 0 ? '#,##0.'.str_repeat('0', $digits) : '#,##0';
        }

        $rows = array_values(array_map(fn (array $row) => array_map(
            fn ($cell) => is_numeric($cell) && ! is_string($cell) ? $cell : ($cell === null ? '' : $cell),
            $row,
        ), $this->rows));

        return $exporter->download(
            filename: $this->name.'-'.now()->format('Y-m-d').'.xlsx',
            title: $this->title,
            subtitle: $this->subtitle,
            headers: $this->headers,
            rows: $rows,
            direction: Locales::direction(),
            columnFormats: $formats,
        );
    }

    /** @param  array<int, string|int|float|null>  $row */
    private function decimalsFor(int $index, array $row): int
    {
        $decimals = $this->decimals[$index];

        return is_int($decimals) ? $decimals : $decimals($row);
    }
}
