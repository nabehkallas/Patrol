<?php

namespace App\Services;

use Illuminate\Http\Response;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class PdfTableExporter
{
    /**
     * Renders a title + table of already-formatted string cells to a downloadable PDF.
     * Uses mPDF (rather than dompdf) specifically for its automatic Arabic
     * shaping/RTL support, since this app's reports may be generated in either language.
     *
     * @param  string[]  $headers
     * @param  list<string[]>  $rows
     */
    public function download(string $filename, string $title, ?string $subtitle, array $headers, array $rows, string $direction = 'ltr'): Response
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            // Without this, mPDF defaults to a tempDir inside vendor/mpdf/mpdf/tmp — but
            // vendor/ isn't writable at runtime (only storage/ and bootstrap/cache are
            // chown'd to www-data in the Dockerfile), so every PDF export 500s with
            // "mkdir(): Permission denied" in production.
            'tempDir' => storage_path('app/mpdf'),
        ]);

        $mpdf->SetDirectionality($direction);

        $html = view('pdf.table', [
            'title' => $title,
            'subtitle' => $subtitle,
            'headers' => $headers,
            'rows' => $rows,
            'direction' => $direction,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $mpdf->WriteHTML($html);

        return response($mpdf->Output($filename, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
