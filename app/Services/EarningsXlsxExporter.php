<?php

namespace App\Services;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A colored, dashboard-style, LIVE .xlsx for the Earnings report -- built to a specific visual
 * reference the station requested (color-blocked sections, fuel types placed side by side,
 * clear sub-headers, and real Excel formulas instead of pre-computed numbers, so editing a rate
 * or a liters figure recalculates every total that depends on it). Doesn't reuse
 * XlsxTableExporter's plain stacked title+table sections: every section here needs its own fill
 * color, the fuel-type sections need side-by-side column placement, and most cells here are
 * formulas, not literal values, none of which that shared exporter supports.
 *
 * Every "total" cell in this sheet is a formula referencing the specific cells that produce it
 * (=A15*B15, =SUM(C10:C14), a summary cell referencing another summary cell, ...) rather than a
 * value computed once in PHP and pasted in -- the one exception is a handful of true leaves that
 * have nothing further to compute from within this sheet (a tier's own rate/price, a shop item's
 * cost price, the liters actually sold, other expenses, the exchange rate): those are real
 * numbers read live from the database, just not formulas, because there's no cell-level
 * breakdown of them on this sheet to reference.
 */
class EarningsXlsxExporter
{
    private const YELLOW = 'FFF2CC';

    private const BLUE = 'D9E2F3';

    private const GREEN = 'D9EAD3';

    private const PEACH = 'FCE5CD';

    private const RED = 'E06666';

    private const GREY = 'E5E7EB';

    private const FUEL_BLOCK_WIDTH = 3; // rate/price, liters, total (SYP)

    private const FUEL_BLOCK_GAP = 1;

    private const FMT_MONEY = '#,##0';

    private const FMT_RATE = '#,##0.00';

    private const FMT_USD = '"$"#,##0.00';

    private Worksheet $sheet;

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
        $this->sheet = $spreadsheet->getActiveSheet();
        $this->sheet->setTitle($this->safeSheetTitle($title));
        // Always LTR regardless of locale -- same convention as every other export in this app
        // (see XlsxTableExporter): columns/numbers stay in a predictable left-to-right layout in
        // Excel even when the labels themselves are Arabic.
        $this->sheet->setRightToLeft($direction === 'rtl');

        $row = 1;
        $this->setValue(1, $row, $title)->getFont()->setBold(true)->setSize(16);
        $row++;
        $this->setValue(1, $row, $subtitle)->getFont()->setItalic(true);
        $row += 2;

        $maxCol = 1;

        if ($priceReference !== []) {
            [$row, $endCol] = $this->writePriceReference($row, $priceReference, $labels);
            $maxCol = max($maxCol, $endCol);
            $row++;
        }

        [$fuelBlockEndRow, $fuelSubtotalRefs, $maxCol] = $this->writeFuelBlocks($row, $fuelBreakdown, $labels, $maxCol);
        $row = $fuelBlockEndRow + 1;

        $revaluationTotalRef = null;

        if ($revaluation['items'] !== []) {
            [$row, $revaluationTotalRef, $endCol] = $this->writeRevaluation($row, $revaluation, $labels);
            $maxCol = max($maxCol, $endCol);
            $row++;
        }

        [$row, $shopNetProfitRef, $endCol] = $this->writeShopProfit($row, $shopProfit, $labels);
        $maxCol = max($maxCol, $endCol);
        $row++;

        $this->writeGrandTotal($row, $fuelSubtotalRefs, $revaluationTotalRef, $shopNetProfitRef, $otherExpenseSyp, $sypRate, $labels);

        $this->autoSizeColumns($maxCol);

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
     * @return array{0: int, 1: int} next free row, last used column
     */
    private function writePriceReference(int $row, array $priceReference, array $labels): array
    {
        $startRow = $row;
        $row = $this->writeHeading($row, 1, 2, $labels['price_reference'], self::YELLOW);
        $row = $this->writeColumnHeaders($row, 1, [$labels['item'], $labels['price']], self::YELLOW);
        $firstDataRow = $row;

        foreach ($priceReference as $p) {
            $this->setValue(1, $row, $p['label']);
            $this->setClean(2, $row, $p['price_syp']);
            $row++;
        }

        $this->shadeAndBorder($startRow, 1, $row - 1, 2, self::YELLOW, $firstDataRow);

        return [$row, 2];
    }

    /**
     * Fuel types are placed side by side, each in its own FUEL_BLOCK_WIDTH-column block, with a
     * clear sub-heading + its own column headers above the margin tiers, and another above the
     * top-up tiers -- the exact "clear sub-headers directly above these numbers" the station
     * asked for, instead of one shared header row covering two differently-shaped tables.
     *
     * @return array{0: int, 1: array<int, string>, 2: int} last row used by any block, [fuel_type_id => subtotal cell ref], last used column
     */
    private function writeFuelBlocks(int $startRow, array $fuelBreakdown, array $labels, int $maxCol): array
    {
        $blockEndRow = $startRow;
        $subtotalRefs = [];

        foreach ($fuelBreakdown as $i => $fuelRow) {
            $startCol = 1 + $i * (self::FUEL_BLOCK_WIDTH + self::FUEL_BLOCK_GAP);
            $lastCol = $startCol + self::FUEL_BLOCK_WIDTH - 1;
            $row = $startRow;

            $row = $this->writeHeading($row, $startCol, $lastCol, $fuelRow['fuel_type']['name'], self::BLUE);

            $this->setValue($startCol, $row, $labels['liters_sold']);
            $this->setClean($startCol + 1, $row, $fuelRow['liters_sold']);
            $litersSoldRow = $row;
            $row++;
            $this->shadeAndBorder($litersSoldRow, $startCol, $litersSoldRow, $lastCol, self::BLUE, $litersSoldRow);
            $row++;

            // --- Margin Profits sub-block ---
            $row = $this->writeSubHeading($row, $startCol, $lastCol, $labels['margin_profits'], self::BLUE);
            $row = $this->writeColumnHeaders($row, $startCol, [$labels['rate_margin'], $labels['sold_liters'], $labels['total_syp']], self::BLUE);
            $marginFirstRow = $row;

            foreach ($fuelRow['margin_tiers'] as $tier) {
                $this->writeTierRow($row, $startCol, $tier['margin_rate_syp'], $tier['liters']);
                $row++;
            }

            $marginLastRow = $row - 1;
            $marginEarningsRow = $row;

            if ($marginLastRow >= $marginFirstRow) {
                $this->setValue($startCol, $row, $labels['margin_earnings']);
                $this->setFormula($startCol + 2, $row, 'SUM('.$this->range($startCol + 2, $marginFirstRow, $startCol + 2, $marginLastRow).')')
                    ->getNumberFormat()->setFormatCode(self::FMT_MONEY);
            } else {
                $this->setValue($startCol, $row, $labels['margin_earnings']);
                $this->setValue($startCol + 2, $row, 0)->getNumberFormat()->setFormatCode(self::FMT_MONEY);
            }
            $this->shadeAndBorder($marginFirstRow - 1, $startCol, $row, $lastCol, self::BLUE, $marginFirstRow);
            $row++;
            $row++;

            // --- Free Liters (top-up) sub-block ---
            $row = $this->writeSubHeading($row, $startCol, $lastCol, $labels['free_liters'], self::BLUE);
            $row = $this->writeColumnHeaders($row, $startCol, [$labels['price'], $labels['sold_liters'], $labels['total_syp']], self::BLUE);
            $topupFirstRow = $row;

            foreach ($fuelRow['topup_tiers'] as $tier) {
                $this->writeTierRow($row, $startCol, $tier['price_per_liter_syp'], $tier['liters']);
                $row++;
            }

            $topupLastRow = $row - 1;
            $topupEarningsRow = $row;

            if ($topupLastRow >= $topupFirstRow) {
                $this->setValue($startCol, $row, $labels['topup_earnings']);
                $this->setFormula($startCol + 2, $row, 'SUM('.$this->range($startCol + 2, $topupFirstRow, $startCol + 2, $topupLastRow).')')
                    ->getNumberFormat()->setFormatCode(self::FMT_MONEY);
            } else {
                $this->setValue($startCol, $row, $labels['topup_earnings']);
                $this->setValue($startCol + 2, $row, 0)->getNumberFormat()->setFormatCode(self::FMT_MONEY);
            }
            $this->shadeAndBorder($topupFirstRow - 1, $startCol, $row, $lastCol, self::BLUE, $topupFirstRow);
            $row++;

            // --- Subtotal: margin earnings + top-up earnings, a live formula referencing both
            // subtotal cells just written above ---
            $this->setValue($startCol, $row, $labels['subtotal']);
            $subtotalCell = $this->setFormula(
                $startCol + 2, $row,
                $this->ref($startCol + 2, $marginEarningsRow).'+'.$this->ref($startCol + 2, $topupEarningsRow),
            );
            $subtotalCell->getNumberFormat()->setFormatCode(self::FMT_MONEY);
            $subtotalCell->getFont()->setBold(true);
            $this->shadeAndBorder($row, $startCol, $row, $lastCol, self::BLUE, $row);

            $subtotalRefs[] = $this->ref($startCol + 2, $row);

            $blockEndRow = max($blockEndRow, $row);
            $maxCol = max($maxCol, $lastCol);
        }

        return [$blockEndRow, $subtotalRefs, $maxCol];
    }

    /**
     * One tier row: [rate/price] | [liters] | =RateCell*LitersCell -- a real formula, so editing
     * either input cell in Excel recalculates this row's total live.
     */
    private function writeTierRow(int $row, int $startCol, float $rate, float $liters): void
    {
        $this->setClean($startCol, $row, $rate);
        $this->setClean($startCol + 1, $row, $liters);
        $this->setFormula($startCol + 2, $row, $this->ref($startCol, $row).'*'.$this->ref($startCol + 1, $row))
            ->getNumberFormat()->setFormatCode(self::FMT_MONEY);
    }

    /**
     * @return array{0: int, 1: string, 2: int} next free row, the Total cell's reference, last used column
     */
    private function writeRevaluation(int $row, array $revaluation, array $labels): array
    {
        $startRow = $row;
        $row = $this->writeHeading($row, 1, 6, $labels['revaluation'], self::GREEN);
        $row = $this->writeColumnHeaders($row, 1, [
            $labels['fuel_type'], $labels['liters'], $labels['old_price'], $labels['new_price'], $labels['price_diff'], $labels['profit'],
        ], self::GREEN);
        $firstDataRow = $row;

        foreach ($revaluation['items'] as $item) {
            foreach ($item['tiers'] as $tier) {
                $this->setValue(1, $row, $item['fuel_type']['name']);
                $this->setClean(2, $row, $tier['liters']);
                $this->setValue(3, $row, $tier['old_price_syp'])->getNumberFormat()->setFormatCode(self::FMT_RATE);
                $this->setValue(4, $row, $tier['new_price_syp'])->getNumberFormat()->setFormatCode(self::FMT_RATE);
                $this->setFormula(5, $row, $this->ref(4, $row).'-'.$this->ref(3, $row))->getNumberFormat()->setFormatCode(self::FMT_RATE);
                $this->setFormula(6, $row, $this->ref(2, $row).'*'.$this->ref(5, $row))->getNumberFormat()->setFormatCode(self::FMT_MONEY);
                $row++;
            }
        }

        $lastDataRow = $row - 1;
        $totalRow = $row;
        $this->setValue(1, $row, $labels['total'])->getFont()->setBold(true);
        $totalCell = $this->setFormula(6, $row, 'SUM('.$this->range(6, $firstDataRow, 6, $lastDataRow).')');
        $totalCell->getNumberFormat()->setFormatCode(self::FMT_MONEY);
        $totalCell->getFont()->setBold(true);

        $this->shadeAndBorder($startRow, 1, $row, 6, self::GREEN, $firstDataRow);

        return [$row + 1, $this->ref(6, $totalRow), 6];
    }

    /**
     * Item table first (Qty Sold, Cost/Unit, and Revenue read live from the database -- real
     * recorded sales, not invented numbers -- with COGS and Profit as formulas), then a summary
     * block whose own totals are SUM formulas over that table, so nothing here is a number
     * computed once in PHP and pasted in.
     *
     * @return array{0: int, 1: string, 2: int} next free row, the Net Profit cell's reference, last used column
     */
    private function writeShopProfit(int $row, array $shopProfit, array $labels): array
    {
        $revenueRef = null;
        $cogsRef = null;
        $maxCol = 2;

        if ($shopProfit['items'] !== []) {
            $startRow = $row;
            $row = $this->writeHeading($row, 1, 6, $labels['shop_profit'], self::PEACH);
            $row = $this->writeColumnHeaders($row, 1, [
                $labels['item'], $labels['qty_sold'], $labels['cost_per_unit'], $labels['revenue'], $labels['cogs'], $labels['profit'],
            ], self::PEACH);
            $firstDataRow = $row;

            foreach ($shopProfit['items'] as $item) {
                $this->setValue(1, $row, $item['name']);
                $this->setValue(2, $row, $item['quantity_sold'])->getNumberFormat()->setFormatCode(self::FMT_MONEY);
                $this->setValue(3, $row, $item['cost_per_unit_syp'])->getNumberFormat()->setFormatCode(self::FMT_RATE);
                $this->setValue(4, $row, $item['revenue_syp'])->getNumberFormat()->setFormatCode(self::FMT_MONEY);
                $this->setFormula(5, $row, $this->ref(2, $row).'*'.$this->ref(3, $row))->getNumberFormat()->setFormatCode(self::FMT_MONEY);
                $this->setFormula(6, $row, $this->ref(4, $row).'-'.$this->ref(5, $row))->getNumberFormat()->setFormatCode(self::FMT_MONEY);
                $row++;
            }

            $lastDataRow = $row - 1;
            $this->shadeAndBorder($startRow, 1, $lastDataRow, 6, self::PEACH, $firstDataRow);
            $revenueRef = $this->range(4, $firstDataRow, 4, $lastDataRow);
            $cogsRef = $this->range(5, $firstDataRow, 5, $lastDataRow);
            $maxCol = 6;
            $row++;
        }

        $summaryStartRow = $row;
        $row = $this->writeHeading($row, 1, 2, $labels['shop_profit_summary'], self::PEACH);

        $this->setValue(1, $row, $labels['shop_revenue']);
        $revenueCell = $revenueRef
            ? $this->setFormula(2, $row, 'SUM('.$revenueRef.')')
            : $this->setValue(2, $row, 0);
        $revenueCell->getNumberFormat()->setFormatCode(self::FMT_MONEY);
        $revenueSummaryRow = $row;
        $row++;

        $this->setValue(1, $row, $labels['shop_cogs']);
        $cogsCell = $cogsRef
            ? $this->setFormula(2, $row, 'SUM('.$cogsRef.')')
            : $this->setValue(2, $row, 0);
        $cogsCell->getNumberFormat()->setFormatCode(self::FMT_MONEY);
        $cogsSummaryRow = $row;
        $row++;

        $this->setValue(1, $row, $labels['net_profit'])->getFont()->setBold(true);
        $netProfitCell = $this->setFormula(2, $row, $this->ref(2, $revenueSummaryRow).'-'.$this->ref(2, $cogsSummaryRow));
        $netProfitCell->getNumberFormat()->setFormatCode(self::FMT_MONEY);
        $netProfitCell->getFont()->setBold(true);
        $netProfitRow = $row;

        $this->shadeAndBorder($summaryStartRow, 1, $row, 2, self::PEACH, $revenueSummaryRow);

        return [$row + 1, $this->ref(2, $netProfitRow), $maxCol];
    }

    /**
     * @param  array<int, string>  $fuelSubtotalRefs
     */
    private function writeGrandTotal(int $row, array $fuelSubtotalRefs, ?string $revaluationTotalRef, string $shopNetProfitRef, float $otherExpenseSyp, float $sypRate, array $labels): void
    {
        $terms = $fuelSubtotalRefs;

        if ($revaluationTotalRef) {
            $terms[] = $revaluationTotalRef;
        }

        $terms[] = $shopNetProfitRef;

        $this->setValue(1, $row, $labels['total_earnings']);
        $totalEarningsCell = $this->setFormula(2, $row, implode('+', $terms));
        $totalEarningsCell->getNumberFormat()->setFormatCode(self::FMT_MONEY);
        $totalEarningsRow = $row;
        $row++;

        // A real number read live from expense records, not a formula -- this sheet has no
        // itemized expense breakdown to sum, so there's nothing further to compute it from here.
        $this->setValue(1, $row, $labels['other_expenses']);
        $this->setValue(2, $row, -$otherExpenseSyp)->getNumberFormat()->setFormatCode(self::FMT_MONEY);
        $otherExpensesRow = $row;
        $row++;

        $this->setValue(1, $row, $labels['grand_total'])->getFont()->setBold(true)->setSize(13);
        $grandTotalCell = $this->setFormula(2, $row, $this->ref(2, $totalEarningsRow).'+'.$this->ref(2, $otherExpensesRow));
        $grandTotalCell->getNumberFormat()->setFormatCode(self::FMT_MONEY);
        $grandTotalCell->getFont()->setBold(true)->setSize(13);
        $this->fillRange(1, $row, 2, $row, self::RED);
        $grandTotalRow = $row;
        $row++;

        // Read live from Admin > Exchange Rates -- not hardcoded. If no rate has ever been set
        // for this station, ExchangeRate::currentRateFor() falls back to 1.0 (so SYP and USD
        // read the same until a real rate is entered); that fallback is the app's own documented
        // behavior, not something this export invents.
        $this->setValue(1, $row, $labels['exchange_rate']);
        $this->setValue(2, $row, $sypRate)->getNumberFormat()->setFormatCode(self::FMT_RATE);
        $exchangeRateRow = $row;
        $row++;

        $this->setValue(1, $row, $labels['grand_total_usd'])->getFont()->setBold(true);
        // Live formula: Grand Total (SYP) / Exchange Rate -- editing either cell in Excel
        // recalculates this automatically.
        $usdCell = $this->setFormula(2, $row, $this->ref(2, $grandTotalRow).'/'.$this->ref(2, $exchangeRateRow));
        $usdCell->getNumberFormat()->setFormatCode(self::FMT_USD);
        $usdCell->getFont()->setBold(true);
        $this->fillRange(1, $row, 2, $row, self::GREEN);
    }

    private function writeHeading(int $row, int $startCol, int $endCol, string $text, string $color): int
    {
        $this->setValue($startCol, $row, $text);
        $this->sheet->mergeCells($this->range($startCol, $row, $endCol, $row));
        $this->sheet->getStyle($this->range($startCol, $row, $endCol, $row))->getFont()->setBold(true)->setSize(13);
        $this->sheet->getStyle([$startCol, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->fillRange($startCol, $row, $endCol, $row, $color);

        return $row + 1;
    }

    private function writeSubHeading(int $row, int $startCol, int $endCol, string $text, string $color): int
    {
        $this->setValue($startCol, $row, $text);
        $this->sheet->mergeCells($this->range($startCol, $row, $endCol, $row));
        $this->sheet->getStyle($this->range($startCol, $row, $endCol, $row))->getFont()->setBold(true)->setItalic(true);
        $this->fillRange($startCol, $row, $endCol, $row, $this->lighten($color));

        return $row + 1;
    }

    /**
     * @param  string[]  $headers
     */
    private function writeColumnHeaders(int $row, int $startCol, array $headers, string $color): int
    {
        foreach ($headers as $i => $header) {
            $this->setValue($startCol + $i, $row, $header);
        }
        $range = $this->range($startCol, $row, $startCol + count($headers) - 1, $row);
        $this->sheet->getStyle($range)->getFont()->setBold(true);
        $this->fillRange($startCol, $row, $startCol + count($headers) - 1, $row, self::GREY);

        return $row + 1;
    }

    private function shadeAndBorder(int $startRow, int $startCol, int $endRow, int $endCol, string $color, int $dataStartRow): void
    {
        if ($endRow >= $dataStartRow) {
            $this->fillRange($startCol, $dataStartRow, $endCol, $endRow, $color, light: true);
        }

        $this->sheet->getStyle($this->range($startCol, $startRow, $endCol, $endRow))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFBFBF');
    }

    private function fillRange(int $startCol, int $startRow, int $endCol, int $endRow, string $rgb, bool $light = false): void
    {
        $color = $light ? $this->lighten($rgb) : $rgb;
        $this->sheet->getStyle($this->range($startCol, $startRow, $endCol, $endRow))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($color);
    }

    private function lighten(string $rgb): string
    {
        [$r, $g, $b] = array_map(fn (string $hex) => hexdec($hex), str_split($rgb, 2));
        $blend = fn (int $channel) => (int) round($channel + (255 - $channel) * 0.55);

        return sprintf('%02X%02X%02X', $blend($r), $blend($g), $blend($b));
    }

    private function setValue(int $col, int $row, mixed $value): Style
    {
        $this->sheet->setCellValue([$col, $row], $value);

        return $this->sheet->getStyle([$col, $row]);
    }

    /**
     * A liters/rate/price figure: up to 3 decimals when the value actually has a fraction, none
     * when it doesn't -- chosen per-value in PHP rather than relying on a single "#,##0.###"
     * format code's optional-digit placeholders, since PhpSpreadsheet's own (simplified) string
     * formatter doesn't suppress those trailing zeros the way real Excel is supposed to, and this
     * way the file is guaranteed to render correctly everywhere regardless of that ambiguity.
     */
    private function setClean(int $col, int $row, float $value): Style
    {
        $this->sheet->setCellValue([$col, $row], $value);
        $isWhole = abs($value - round($value)) < 0.0005;
        $this->sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode($isWhole ? self::FMT_MONEY : '#,##0.000');

        return $this->sheet->getStyle([$col, $row]);
    }

    private function setFormula(int $col, int $row, string $formula): Style
    {
        $this->sheet->setCellValue([$col, $row], '='.$formula);

        return $this->sheet->getStyle([$col, $row]);
    }

    private function ref(int $col, int $row): string
    {
        return Coordinate::stringFromColumnIndex($col).$row;
    }

    private function range(int $startCol, int $startRow, int $endCol, int $endRow): string
    {
        return $this->ref($startCol, $startRow).':'.$this->ref($endCol, $endRow);
    }

    private function autoSizeColumns(int $maxCol): void
    {
        $highestRow = $this->sheet->getHighestRow();
        $widths = array_fill(1, $maxCol, 0);

        for ($r = 1; $r <= $highestRow; $r++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $cell = $this->sheet->getCell([$col, $r]);
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
            $this->sheet->getColumnDimensionByColumn($col)->setWidth($longest + 3);
        }
    }

    private function safeSheetTitle(string $title): string
    {
        return mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $title), 0, 31);
    }
}
