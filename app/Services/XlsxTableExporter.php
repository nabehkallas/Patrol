<?php

namespace App\Services;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class XlsxTableExporter
{
    /**
     * Renders a title + a single table to a downloadable .xlsx. Thin wrapper around
     * downloadMultiSection() for the common case (every other caller so far); see that method's
     * docblock for the shared details (cell types, direction, column sizing).
     *
     * @param  string[]  $headers
     * @param  list<array<int, string|int|float|null>>  $rows
     * @param  array<int, string>  $columnFormats  0-indexed column => Excel number format code
     *                                             (e.g. '#,##0.00'), applied to that column's data rows only. Columns left out keep
     *                                             Excel's default General format.
     */
    public function download(string $filename, string $title, ?string $subtitle, array $headers, array $rows, string $direction = 'ltr', array $columnFormats = []): Response
    {
        return $this->downloadMultiSection($filename, $title, $subtitle, [
            ['heading' => null, 'headers' => $headers, 'rows' => $rows, 'columnFormats' => $columnFormats],
        ], $direction);
    }

    /**
     * Renders a title followed by one or more stacked heading+table sections (a blank row apart)
     * to a downloadable .xlsx -- for a report made of several distinct tables (e.g. Earnings: one
     * table per fuel type, then Revaluation, then Shop Profit, then a Summary), where a single
     * headers/rows pair can't represent the whole page. Cells stay as real scalar values
     * (int/float/string), not pre-formatted strings -- a real spreadsheet needs numeric columns
     * the user can actually sum/sort in Excel, not "1,234.56 L" text.
     *
     * Sheet direction defaults to (and, for exports built for entry/calculation rather than
     * reading, should stay) 'ltr' regardless of the app's locale -- only the header/label text
     * follows the locale, not the column layout.
     *
     * @param  list<array{heading: ?string, headers: string[], rows: list<array<int, string|int|float|null>>, columnFormats?: array<int, string>}>  $sections
     */
    public function downloadMultiSection(string $filename, string $title, ?string $subtitle, array $sections, string $direction = 'ltr'): Response
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setSize(14);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->safeSheetTitle($title));
        $sheet->setRightToLeft($direction === 'rtl');

        $row = 1;

        $sheet->setCellValue([1, $row], $title);
        $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(14);
        $row++;

        if ($subtitle !== null) {
            $sheet->setCellValue([1, $row], $subtitle);
            $sheet->getStyle([1, $row])->getFont()->setItalic(true);
            $row++;
        }

        // column => longest displayed text seen in that column, across every section -- columns
        // are shared down the whole sheet, so a narrow column in one section still needs to fit
        // a wider value from another.
        $columnWidths = [];

        foreach ($sections as $section) {
            $headers = $section['headers'];
            $rows = $section['rows'];
            $columnFormats = $section['columnFormats'] ?? [];

            $row++; // blank row before this section

            if ($section['heading'] !== null) {
                $sheet->setCellValue([1, $row], $section['heading']);
                $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(13);
                $row++;
            }

            foreach ($headers as $col => $header) {
                $sheet->setCellValue([$col + 1, $row], $header);
            }
            $headerRange = [1, $row, max(count($headers), 1), $row];
            $sheet->getStyle($headerRange)->getFont()->setBold(true);
            $sheet->getStyle($headerRange)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E5E7EB');
            $row++;

            $firstDataRow = $row;

            foreach ($rows as $rowData) {
                foreach ($rowData as $col => $value) {
                    $sheet->setCellValue([$col + 1, $row], $value);
                }
                $row++;
            }

            $lastDataRow = $row - 1;

            if ($lastDataRow >= $firstDataRow) {
                foreach ($columnFormats as $col => $format) {
                    $sheet->getStyle([$col + 1, $firstDataRow, $col + 1, $lastDataRow])
                        ->getNumberFormat()->setFormatCode($format);
                }
            }

            // Excel's own autosize consistently over-estimates at this font size/RTL mix, so
            // widths are computed directly from each column's actual displayed text instead --
            // read back from the real cells (their *calculated* value, so a formula cell is
            // measured by what it shows, not its formula text) rather than the raw row data.
            foreach ($headers as $col => $header) {
                $longest = max($columnWidths[$col] ?? 0, mb_strlen((string) $header));

                if ($lastDataRow >= $firstDataRow) {
                    for ($r = $firstDataRow; $r <= $lastDataRow; $r++) {
                        $cell = $sheet->getCell([$col + 1, $r]);
                        $value = $cell->getCalculatedValue();

                        if ($value === null || $value === '') {
                            continue;
                        }

                        $display = is_numeric($value)
                            ? NumberFormat::toFormattedString($value, $cell->getStyle()->getNumberFormat()->getFormatCode())
                            : (string) $value;

                        $longest = max($longest, mb_strlen($display));
                    }
                }

                $columnWidths[$col] = $longest;
            }
        }

        // Title and subtitle (only ever in column 1, and often much longer than any real
        // column-1 value) are deliberately excluded from the width computation above, so a long
        // date-range subtitle doesn't stretch the first column.
        foreach ($columnWidths as $col => $longest) {
            $sheet->getColumnDimensionByColumn($col + 1)->setWidth($longest + 2);
        }

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
     * Excel sheet titles can't exceed 31 characters or contain : \ / ? * [ ].
     */
    private function safeSheetTitle(string $title): string
    {
        return mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $title), 0, 31);
    }
}
