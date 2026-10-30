<?php

namespace App\Services;

use App\Models\ZkongBatch;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Excel del RMA de una remesa con las columnas que pide ZKONG:
 * No | Model Name | Qty | Issue Description | SN Info | Remark.
 * Una fila por modelo y fallo (Qty = cuántas), con los números de serie (código de la
 * etiqueta o MAC de la antena) uno por línea y los expedientes en Remark.
 * Misma cabecera de marca que el Excel de tickets.
 */
class ZkongRmaXlsx
{
    private const AZUL     = 'FF2563EB';
    private const AZUL_OSC = 'FF1D4ED8';
    private const TXT      = 'FF0E1E33';
    private const TXT_SUAVE = 'FF5F7488';
    private const CEBRA    = 'FFF3F7FE';
    private const LINEA    = 'FFE3EAF3';

    public function build(ZkongBatch $b, array $items): string
    {
        // Agrupar por modelo + fallo, en el orden en que aparecen.
        $filas = [];
        foreach ($items as $i) {
            $model = (string) ($i['model'] ?: 'Unknown');
            $issue = trim((string) $i['issue']);
            $k = mb_strtolower($model . '|' . $issue);
            $filas[$k] ??= ['model' => $model, 'issue' => $issue, 'sn' => [], 'remark' => []];
            $filas[$k]['sn'][] = $i['code'];
            $filas[$k]['remark'][$i['warranty_code']] = true;
        }

        $cols = [['No', 7], ['Model Name', 22], ['Qty', 8], ['Issue Description', 44], ['SN Info', 26], ['Remark', 24]];
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('RMA');
        $ss->getProperties()->setTitle('RMA ' . $b->code)->setCompany('AEME');

        // Cabecera del documento con el logo.
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'RMA ZKONG · ' . $b->code);
        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', count($items) . ' devices · ' . now()->format('d/m/Y') . ' · AEME');
        $s1 = $sheet->getStyle('A1:F1');
        $s1->getFont()->setBold(true)->setSize(17)->getColor()->setARGB(self::AZUL_OSC);
        $s1->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(19);
        $sheet->getRowDimension(1)->setRowHeight(46);
        $s2 = $sheet->getStyle('A2:F2');
        $s2->getFont()->setSize(10.5)->getColor()->setARGB(self::TXT_SUAVE);
        $s2->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(19);
        $s2->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::AZUL);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(8);
        $logo = resource_path('brand/aeme-logo.png');
        if (is_file($logo)) {
            $draw = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $draw->setName('AEME')->setPath($logo)->setHeight(30);
            $draw->setCoordinates('A1')->setOffsetX(12)->setOffsetY(9);
            $draw->setWorksheet($sheet);
        }

        // Cabecera de la tabla.
        foreach ($cols as $n => [$h, $ancho]) {
            $L = chr(65 + $n);
            $sheet->getColumnDimension($L)->setWidth($ancho);
            $sheet->setCellValue("{$L}4", $h);
        }
        $st = $sheet->getStyle('A4:F4');
        $st->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AZUL_OSC);
        $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        $sheet->getRowDimension(4)->setRowHeight(24);

        $fila = 5;
        $n = 0;
        foreach ($filas as $f) {
            $n++;
            $vals = [$n, $f['model'], count($f['sn']), $f['issue'], implode("\n", $f['sn']), implode(', ', array_keys($f['remark']))];
            foreach ($vals as $c => $v) {
                $cell = chr(65 + $c) . $fila;
                is_int($v) ? $sheet->setCellValue($cell, $v) : $sheet->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
            }
            $r = $sheet->getStyle("A{$fila}:F{$fila}");
            $r->getFont()->setSize(10.5)->getColor()->setARGB(self::TXT);
            $r->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true)->setIndent(1);
            $sheet->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setIndent(0);
            $sheet->getStyle("C{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setIndent(0);
            $sheet->getStyle("C{$fila}")->getFont()->setBold(true);
            if ($n % 2 === 0) $r->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::CEBRA);
            $r->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::LINEA);
            $lineas = max(count($f['sn']), (int) ceil(mb_strlen($f['issue']) / 48), 1);
            $sheet->getRowDimension($fila)->setRowHeight(max(20, 15 * $lineas + 5));
            $fila++;
        }

        // Total.
        $sheet->setCellValue("B{$fila}", 'Total');
        $sheet->setCellValue("C{$fila}", count($items));
        $t = $sheet->getStyle("A{$fila}:F{$fila}");
        $t->getFont()->setBold(true)->getColor()->setARGB(self::AZUL_OSC);
        $t->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::AZUL);
        $sheet->getStyle("B{$fila}")->getAlignment()->setIndent(1);
        $sheet->getStyle("C{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($fila)->setRowHeight(22);

        $sheet->freezePane('A5');
        $sheet->setSelectedCell('A5');

        $writer = new XlsxWriter($ss);
        ob_start();
        $writer->save('php://output');
        $bin = ob_get_clean();
        $ss->disconnectWorksheets();
        return $bin;
    }
}
