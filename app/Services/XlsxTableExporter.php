<?php

namespace App\Services;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class XlsxTableExporter
{
    /**
     * Renders a title + table to a downloadable .xlsx. Unlike PdfTableExporter, cells stay as
     * real scalar values (int/float/string), not pre-formatted strings — a real spreadsheet
     * needs numeric columns the user can actually sum/sort in Excel, not "1,234.56 L" text.
     *
     * @param  string[]  $headers
     * @param  list<array<int, string|int|float|null>>  $rows
     */
    public function download(string $filename, string $title, ?string $subtitle, array $headers, array $rows, string $direction = 'ltr'): Response
    {
        $spreadsheet = new Spreadsheet;
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

        $row++; // blank row before the table

        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, $row], $header);
        }
        $headerRange = [1, $row, count($headers), $row];
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E5E7EB');
        $row++;

        foreach ($rows as $rowData) {
            foreach ($rowData as $col => $value) {
                $sheet->setCellValue([$col + 1, $row], $value);
            }
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
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
