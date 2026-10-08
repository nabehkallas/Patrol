<?php

namespace Tests\Feature;

use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use App\Support\ExportTable;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** One export table, two formats: the spreadsheet keeps real numbers, the PDF writes them out. */
class ExportTableTest extends TestCase
{
    private function table(): ExportTable
    {
        return new ExportTable(
            name: 'transactions',
            title: 'Transactions',
            subtitle: null,
            headers: ['Date', 'Liters', 'Amount', 'Currency'],
            rows: [
                ['2026-10-08', 200.0, 300000.0, 'SYP'],
                ['2026-10-08', null, 12.5, 'USD'],
            ],
            decimals: [1 => 3, 2 => fn (array $row) => $row[3] === 'SYP' ? 0 : 2],
        );
    }

    public function test_the_spreadsheet_keeps_amounts_as_numbers(): void
    {
        $response = $this->table()->xlsx(new XlsxTableExporter);

        $path = tempnam(sys_get_temp_dir(), 'export-test-');
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        // Title, a blank row, the header row, then the data.
        $this->assertSame('Amount', $sheet->getCell('C3')->getValue());
        $this->assertSame(300000.0, (float) $sheet->getCell('C4')->getValue());
        $this->assertIsNumeric($sheet->getCell('C4')->getValue());
        $this->assertSame('SYP', $sheet->getCell('D4')->getValue());
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_writes_each_amount_with_its_currency_decimals(): void
    {
        $exporter = new class extends PdfTableExporter
        {
            /** @var array<int, array<int, string>> */
            public array $rows = [];

            public function download(string $filename, string $title, ?string $subtitle, array $headers, array $rows, string $direction = 'ltr'): Response
            {
                $this->rows = $rows;

                return new Response($filename);
            }
        };

        $response = $this->table()->pdf($exporter);

        $this->assertSame(['2026-10-08', '200.000', '300,000', 'SYP'], $exporter->rows[0]);
        $this->assertSame(['2026-10-08', '—', '12.50', 'USD'], $exporter->rows[1]);
        $this->assertStringEndsWith('.pdf', (string) $response->getContent());
    }
}
