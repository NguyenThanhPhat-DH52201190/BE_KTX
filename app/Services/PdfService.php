<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/**
 * Hạ tầng xuất PDF dùng chung cho toàn hệ thống. Dùng dompdf/dompdf trực tiếp
 * (không dùng wrapper barryvdh/laravel-dompdf) để tránh ràng buộc version với
 * Laravel framework. Ép defaultFont về DejaVu Sans vì font mặc định của dompdf
 * (Helvetica) không có glyph tiếng Việt có dấu.
 */
class PdfService
{
    public function render(string $view, array $data = [], array $options = []): string
    {
        $html = view($view, $data)->render();

        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'DejaVu Sans');
        $pdfOptions->set('isRemoteEnabled', false);
        $pdfOptions->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($pdfOptions);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $options['orientation'] ?? 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function download(string $view, array $data, string $filename, array $options = []): Response
    {
        $content = $this->render($view, $data, $options);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
