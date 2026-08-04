<?php

namespace App\Services;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Hạ tầng xuất Excel dùng chung, cùng pattern với PdfService (response($content, 200, [headers]),
 * không dùng Storage disk). Dùng phpoffice/phpspreadsheet đã cài sẵn (dùng cho mẫu import tuyển sinh).
 */
class ExcelService
{
    /**
     * @param array<int, array{title: string, headers: string[], rows: array[]}> $sheets
     */
    public function download(string $filename, array $sheets): Response
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $sheetDef) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetDef['title']);

            $headers = $sheetDef['headers'];
            $rows = $sheetDef['rows'];
            $columnCount = count($headers);

            foreach ($headers as $colIndex => $header) {
                $sheet->setCellValue([$colIndex + 1, 1], $header);
            }

            if ($columnCount > 0) {
                $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
                $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '244CB8']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
            }

            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $colIndex => $value) {
                    $sheet->setCellValue([$colIndex + 1, $rowIndex + 2], $value);
                }
            }

            $lastRow = count($rows) + 1;
            if ($columnCount > 0 && $lastRow >= 2) {
                $sheet->getStyle("A2:{$lastColumn}{$lastRow}")->applyFromArray([
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
            }

            foreach (range(1, $columnCount) as $colIndex) {
                $sheet->getColumnDimensionByColumn($colIndex)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
