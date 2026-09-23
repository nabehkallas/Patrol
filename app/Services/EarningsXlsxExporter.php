<?php

namespace App\Services;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A colored, dashboard-style .xlsx for the Earnings report -- built to a specific visual
 * reference the station requested (color-blocked sections, fuel types placed side by side)
 * rather than XlsxTableExporter's plain stacked title+table sections, which is why this doesn't
 * reuse that service: every section here needs its own fill color and the fuel-type sections
 * need side-by-side column placement, neither of which that shared exporter supports.
 */
class EarningsXlsxExporter
{
    private const YELLOW = 'FFF2CC';

    private const BLUE = 'D9E2F3';

    private const GREEN = 'D9EAD3';

    private const PEACH = 'FCE5CD';

    private const RED = 'E06666';

    private const FUEL_BLOCK_WIDTH = 3; // rate/label, liters, earnings

    private const FUEL_BLOCK_GAP = 1;

    /**
     * @param  list<array{label: string, price_syp: float}>  $priceReference
     * @param  list<array<string, mixed>>  $fuelBreakdown  same shape as EarningsController's $breakdown
     * @param  array{total_syp: int, items: list<array<string, mixed>>}  $revaluation
     * @param  array{total_revenue_syp: float, total_cogs_syp: float, net_profit_syp: float, items: list<array<string, mixed>>}  $shopProfit
     * @param  array<string, string>  $labels
     */
    public function download(
        string $filename,
        string $title,
        string $subtitle,
        array $priceReference,
        array $fuelBreakdown,
        array $revaluation,
        array $shopProfit,
        float $otherExpenseSyp,
        float $totalEarningsSyp,
        float $sypRate,
        array $labels,
        string $direction = 'ltr',
    ): Response {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setSize(12);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->safeSheetTitle($title));
        $sheet->setRightToLeft($direction === 'rtl');

        $row = 1;
        $sheet->setCellValue([1, $row], $title);
        $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(16);
        $row++;

        $sheet->setCellValue([1, $row], $subtitle);
        $sheet->getStyle([1, $row])->getFont()->setItalic(true);
        $row += 2;

        $maxCol = 1;

        // --- Price reference ---
        if ($priceReference !== []) {
            $row = $this->writeBlock($sheet, $row, 1, self::YELLOW, $labels['price_reference'], [$labels['item'], $labels['price']], array_map(
                fn (array $p) => [$p['label'], $p['price_syp']],
                $priceReference,
            ), columnFormats: [1 => '#,##0.00']);
            $maxCol = max($maxCol, 2);
            $row++;
        }

        // --- Fuel type blocks, side by side ---
        $fuelBlockStartRow = $row;
        $fuelBlockEndRow = $row;

        foreach ($fuelBreakdown as $i => $fuelRow) {
            $startCol = 1 + $i * (self::FUEL_BLOCK_WIDTH + self::FUEL_BLOCK_GAP);

            $rows = [[$labels['liters_sold'], $fuelRow['liters_sold'], null]];

            foreach ($fuelRow['margin_tiers'] as $tier) {
                $rows[] = [$tier['margin_rate_syp'], $tier['liters'], $tier['earnings_syp']];
            }
            $rows[] = [$labels['margin_earnings'], null, $fuelRow['margin_earnings_syp']];

            foreach ($fuelRow['topup_tiers'] as $tier) {
                $rows[] = [$tier['price_per_liter_syp'], $tier['liters'], $tier['earnings_syp']];
            }
            $rows[] = [$labels['topup_earnings'], null, $fuelRow['topup_earnings_syp']];
            $rows[] = [$labels['subtotal'], null, $fuelRow['subtotal_syp']];

            $endRow = $this->writeBlock(
                $sheet, $fuelBlockStartRow, $startCol, self::BLUE, $fuelRow['fuel_type']['name'],
                [$labels['rate'], $labels['liters'], $labels['earnings']], $rows,
                columnFormats: [0 => '#,##0.000', 1 => '#,##0.000', 2 => '#,##0'],
            );

            $fuelBlockEndRow = max($fuelBlockEndRow, $endRow);
            $maxCol = max($maxCol, $startCol + self::FUEL_BLOCK_WIDTH - 1);
        }

        $row = $fuelBlockEndRow + 1;

        // --- Revaluation ---
        if ($revaluation['items'] !== []) {
            $rows = [];

            foreach ($revaluation['items'] as $item) {
                foreach ($item['tiers'] as $tier) {
                    $rows[] = [
                        $item['fuel_type']['name'], $tier['liters'],
                        $tier['old_price_syp'], $tier['new_price_syp'], $tier['price_diff_syp'], $tier['profit_syp'],
                    ];
                }
            }
            $rows[] = [$labels['total'], null, null, null, null, $revaluation['total_syp']];

            $row = $this->writeBlock(
                $sheet, $row, 1, self::GREEN, $labels['revaluation'],
                [$labels['fuel_type'], $labels['liters'], $labels['old_price'], $labels['new_price'], $labels['price_diff'], $labels['profit']],
                $rows,
                columnFormats: [1 => '#,##0.000', 2 => '#,##0.00', 3 => '#,##0.00', 4 => '#,##0.00', 5 => '#,##0'],
            );
            $maxCol = max($maxCol, 6);
            $row++;
        }

        // --- Shop profit ---
        $shopSummaryRows = [
            [$labels['shop_revenue'], $shopProfit['total_revenue_syp']],
            [$labels['shop_cogs'], $shopProfit['total_cogs_syp']],
            [$labels['net_profit'], $shopProfit['net_profit_syp']],
        ];
        $row = $this->writeBlock($sheet, $row, 1, self::PEACH, $labels['shop_profit'], [$labels['item'], $labels['earnings']], $shopSummaryRows, columnFormats: [1 => '#,##0']);
        $row++;

        if ($shopProfit['items'] !== []) {
            $row = $this->writeBlock(
                $sheet, $row, 1, self::PEACH, null,
                [$labels['item'], $labels['qty_sold'], $labels['cost_per_unit'], $labels['profit_per_unit'], $labels['total_profit']],
                array_map(fn (array $item) => [
                    $item['name'], $item['quantity_sold'], $item['cost_per_unit_syp'], $item['profit_per_unit_syp'], $item['total_profit_syp'],
                ], $shopProfit['items']),
                columnFormats: [1 => '#,##0', 2 => '#,##0.00', 3 => '#,##0.00', 4 => '#,##0'],
            );
            $maxCol = max($maxCol, 5);
            $row++;
        }

        // --- Grand total ---
        $usdTotal = $sypRate > 0 ? $totalEarningsSyp / $sypRate : 0.0;

        $row++;
        $sheet->setCellValue([1, $row], $labels['total_earnings']);
        $sheet->setCellValue([2, $row], $totalEarningsSyp);
        $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $sheet->setCellValue([1, $row], $labels['other_expenses']);
        $sheet->setCellValue([2, $row], -$otherExpenseSyp);
        $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $grandTotalRow = $row;
        $sheet->setCellValue([1, $row], $labels['grand_total']);
        $sheet->setCellValue([2, $row], $totalEarningsSyp);
        $this->fillRange($sheet, [1, $row, 2, $row], self::RED);
        $sheet->getStyle([1, $grandTotalRow, 2, $grandTotalRow])->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $sheet->setCellValue([1, $row], $labels['exchange_rate']);
        $sheet->setCellValue([2, $row], $sypRate);
        $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode('#,##0.00');
        $row++;

        $sheet->setCellValue([1, $row], $labels['grand_total_usd']);
        $sheet->setCellValue([2, $row], $usdTotal);
        $this->fillRange($sheet, [1, $row, 2, $row], self::GREEN);
        $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $sheet->getStyle([1, $row, 2, $row])->getFont()->setBold(true);

        $this->autoSizeColumns($sheet, $maxCol);

        $resource = fopen('php://temp', 'r+');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($resource);
        rewind($resource);
        $contents = stream_get_contents($resource);
        fclose($resource);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Writes one colored heading+table block starting at ($startRow, $startCol) and returns the
     * row just after its last data row (so callers can stack the next block beneath it).
     *
     * @param  string[]  $headers
     * @param  list<array<int, string|int|float|null>>  $rows
     * @param  array<int, string>  $columnFormats  0-indexed column (relative to $startCol) => format code
     */
    private function writeBlock(
        Worksheet $sheet,
        int $startRow,
        int $startCol,
        string $color,
        ?string $heading,
        array $headers,
        array $rows,
        array $columnFormats = [],
    ): int {
        $row = $startRow;
        $lastCol = $startCol + count($headers) - 1;

        if ($heading !== null) {
            $sheet->setCellValue([$startCol, $row], $heading);
            $sheet->mergeCells([$startCol, $row, $lastCol, $row]);
            $sheet->getStyle([$startCol, $row])->getFont()->setBold(true)->setSize(13);
            $sheet->getStyle([$startCol, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->fillRange($sheet, [$startCol, $row, $lastCol, $row], $color);
            $row++;
        }

        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$startCol + $i, $row], $header);
        }
        $sheet->getStyle([$startCol, $row, $lastCol, $row])->getFont()->setBold(true);
        $this->fillRange($sheet, [$startCol, $row, $lastCol, $row], $color);
        $row++;

        $firstDataRow = $row;

        foreach ($rows as $rowData) {
            foreach ($rowData as $i => $value) {
                $sheet->setCellValue([$startCol + $i, $row], $value);
            }
            $row++;
        }

        $lastDataRow = $row - 1;

        if ($lastDataRow >= $firstDataRow) {
            foreach ($columnFormats as $i => $format) {
                $sheet->getStyle([$startCol + $i, $firstDataRow, $startCol + $i, $lastDataRow])
                    ->getNumberFormat()->setFormatCode($format);
            }

            $this->fillRange($sheet, [$startCol, $firstDataRow, $lastCol, $lastDataRow], $color, light: true);
        }

        $sheet->getStyle([$startCol, $startRow, $lastCol, $lastDataRow])
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFBFBF');

        return $row;
    }

    private function fillRange(Worksheet $sheet, array $range, string $rgb, bool $light = false): void
    {
        $color = $light ? $this->lighten($rgb) : $rgb;
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($color);
    }

    /**
     * A block's header keeps its full color; its data rows get a lighter tint of the same color
     * so the section still reads as one visual group without the data looking as heavy as the
     * heading.
     */
    private function lighten(string $rgb): string
    {
        [$r, $g, $b] = array_map(fn (string $hex) => hexdec($hex), str_split($rgb, 2));
        $blend = fn (int $channel) => (int) round($channel + (255 - $channel) * 0.55);

        return sprintf('%02X%02X%02X', $blend($r), $blend($g), $blend($b));
    }

    private function autoSizeColumns(Worksheet $sheet, int $maxCol): void
    {
        $highestRow = $sheet->getHighestRow();
        $widths = array_fill(1, $maxCol, 0);

        for ($r = 1; $r <= $highestRow; $r++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $cell = $sheet->getCell([$col, $r]);
                $value = $cell->getCalculatedValue();

                if ($value === null || $value === '') {
                    continue;
                }

                $display = is_numeric($value)
                    ? NumberFormat::toFormattedString($value, $cell->getStyle()->getNumberFormat()->getFormatCode())
                    : (string) $value;

                $widths[$col] = max($widths[$col], mb_strlen($display));
            }
        }

        foreach ($widths as $col => $longest) {
            $sheet->getColumnDimensionByColumn($col)->setWidth($longest + 3);
        }
    }

    private function safeSheetTitle(string $title): string
    {
        return mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $title), 0, 31);
    }
}
