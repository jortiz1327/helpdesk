<?php

namespace App\Services;

use App\Models\TicketPriority;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsxDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Genera el Excel de la bandeja de tickets con el DISEÑO DE MARCA del helpdesk:
 * banda azul de cabecera, «pills» de estado en color, prioridad coloreada, filas
 * cebra, fechas reales, autofiltro y cabecera congelada. Profesional y legible.
 */
class TicketXlsx
{
    // Paleta de la casa.
    private const AZUL      = 'FF2563EB';   // primary
    private const AZUL_OSC  = 'FF1D4ED8';   // primary-dark (cabecera de la tabla)
    private const AZUL_MED  = 'FF3B82F6';
    private const TXT       = 'FF0E1E33';   // ink
    private const TXT_SUAVE = 'FF5F7488';   // ink-3
    private const CEBRA     = 'FFF3F7FE';   // fila alterna
    private const LINEA     = 'FFE3EAF3';   // borde suave

    /** Estado → [fondo, texto] para el «pill». */
    private const ESTADO = [
        'nuevo'               => ['FFEEF0FF', 'FF3730A3'],
        'abierto'             => ['FFE6F1FB', 'FF0C447C'],
        'en_progreso'         => ['FFFEF3E2', 'FF854F0B'],
        'esperando_respuesta' => ['FFFBEAF0', 'FF8A2B4E'],
        'resuelto'            => ['FFE2F5EC', 'FF0F6E56'],
        'cerrado'             => ['FFEFEDE7', 'FF44413C'],
    ];

    private const CANAL = ['email' => 'Correo', 'whatsapp' => 'WhatsApp', 'web' => 'Web', 'cron' => 'Cron'];

    public function build($rows, array $meta): string
    {
        $canTimes = (bool) ($meta['can_times'] ?? false);
        $slaOn    = (bool) ($meta['sla_on'] ?? false);
        $prio     = TicketPriority::activas();   // key => ['name','color']

        // Columnas (las de tiempos/SLA solo si el usuario puede verlas).
        $cols = [
            ['h' => 'Código',    'w' => 15, 'a' => 'c', 'get' => fn ($r) => $r->code],
            ['h' => 'Asunto',    'w' => 44,             'get' => fn ($r) => (string) $r->subject],
            ['h' => 'Estado',    'w' => 17, 'a' => 'c', 'kind' => 'estado', 'get' => fn ($r) => TicketService::STATUSES[$r->status] ?? $r->status],
            ['h' => 'Prioridad', 'w' => 13, 'a' => 'c', 'kind' => 'prio',   'get' => fn ($r) => $prio[$r->priority]['name'] ?? $r->priority],
            ['h' => 'Categoría', 'w' => 18,             'get' => fn ($r) => $r->category_name ?: '—'],
            ['h' => 'Canal',     'w' => 12, 'a' => 'c', 'get' => fn ($r) => self::CANAL[$r->channel] ?? $r->channel],
            ['h' => 'Asignado',  'w' => 20,             'get' => fn ($r) => $r->agent_name ?: 'Sin asignar'],
            ['h' => 'Cliente',   'w' => 26,             'get' => fn ($r) => $r->contact_name ?: '—'],
            ['h' => 'Correo / teléfono', 'w' => 42,     'get' => fn ($r) => $r->contact_email ?: ($r->contact_wa ? '+' . $r->contact_wa : '—')],
            ['h' => 'Etiquetas', 'w' => 24,             'get' => fn ($r) => $r->labels_txt ?: '—'],
            ['h' => 'Creado',    'w' => 17, 'a' => 'c', 'kind' => 'fecha', 'get' => fn ($r) => $r->created_at],
        ];
        if ($canTimes) {
            $cols[] = ['h' => '1ª respuesta', 'w' => 17, 'a' => 'c', 'kind' => 'fecha', 'get' => fn ($r) => $r->first_response_at];
            $cols[] = ['h' => 'Resuelto',     'w' => 17, 'a' => 'c', 'kind' => 'fecha', 'get' => fn ($r) => $r->resolved_at];
            if ($slaOn) $cols[] = ['h' => 'SLA', 'w' => 12, 'a' => 'c', 'kind' => 'sla', 'get' => fn ($r) => $r->sla_txt];
        }

        $nCols   = count($cols);
        $ultimaC = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nCols);   // 'A'..'N'

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Tickets');
        $ss->getProperties()->setTitle('Tickets de soporte')->setCompany('AEME');

        // --- Cabecera del documento: fondo blanco con el LOGO de AEME y el título en
        // azul de marca (el logo es de color, no se leería sobre azul), y una regla
        // azul debajo como acento. ---
        $sheet->mergeCells("A1:{$ultimaC}1");
        $sheet->setCellValue('A1', 'Tickets de soporte');
        $sheet->mergeCells("A2:{$ultimaC}2");
        $shown = count($rows);
        $total = (int) ($meta['total'] ?? $shown);
        $sub = number_format($shown, 0, ',', '.') . ($total > $shown ? ' de ' . number_format($total, 0, ',', '.') : '')
            . ' incidencias · Exportado ' . now()->format('d/m/Y H:i')
            . (!empty($meta['filtros']) ? '   |   Filtro: ' . $meta['filtros'] : '');
        $sheet->setCellValue('A2', $sub);

        // Título: azul, con hueco a la izquierda (indent) para dejar sitio al logo.
        $s1 = $sheet->getStyle("A1:{$ultimaC}1");
        $s1->getFont()->setBold(true)->setSize(17)->getColor()->setARGB(self::AZUL_OSC);
        $s1->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(19);
        $sheet->getRowDimension(1)->setRowHeight(46);

        // Subtítulo: gris, alineado con el título.
        $s2 = $sheet->getStyle("A2:{$ultimaC}2");
        $s2->getFont()->setSize(10.5)->getColor()->setARGB(self::TXT_SUAVE);
        $s2->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(19);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // Regla azul de marca bajo la cabecera.
        $sheet->getStyle("A2:{$ultimaC}2")->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::AZUL);
        $sheet->getRowDimension(3)->setRowHeight(8);   // respiro

        // Logo AEME arriba a la izquierda (PNG 185x45 → alto 30, ancho ~123).
        $logo = resource_path('brand/aeme-logo.png');
        if (is_file($logo)) {
            $draw = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $draw->setName('AEME')->setPath($logo);
            $draw->setHeight(30);
            $draw->setCoordinates('A1')->setOffsetX(12)->setOffsetY(9);
            $draw->setWorksheet($sheet);
        }

        // --- Cabecera de la tabla (fila 4) ---
        $hFila = 4;
        foreach ($cols as $i => $c) {
            $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($L)->setWidth($c['w']);
            $sheet->setCellValue("{$L}{$hFila}", $c['h']);
        }
        $rango = "A{$hFila}:{$ultimaC}{$hFila}";
        $st = $sheet->getStyle($rango);
        $st->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AZUL_OSC);
        $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);
        $sheet->getRowDimension($hFila)->setRowHeight(24);

        // --- Datos ---
        $fila = $hFila + 1;
        foreach ($rows as $idx => $r) {
            foreach ($cols as $i => $c) {
                $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $cell = "{$L}{$fila}";
                $val = ($c['get'])($r);
                $kind = $c['kind'] ?? 'text';

                if ($kind === 'fecha') {
                    if ($val) {
                        $sheet->setCellValue($cell, XlsxDate::PHPToExcel(new \DateTime((string) $val)));
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
                    } else {
                        $sheet->setCellValue($cell, '—');
                    }
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }

                $cs = $sheet->getStyle($cell);
                $cs->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(($c['a'] ?? 'l') === 'c' ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT)
                    ->setIndent(($c['a'] ?? 'l') === 'c' ? 0 : 1);
                $cs->getFont()->setSize(10.5)->getColor()->setARGB(self::TXT);

                if ($kind === 'estado') {
                    [$bg, $fg] = self::ESTADO[$r->status] ?? ['FFF1EFE8', 'FF444441'];
                    $cs->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
                    $cs->getFont()->setBold(true)->getColor()->setARGB($fg);
                } elseif ($kind === 'prio') {
                    $hex = ltrim($prio[$r->priority]['color'] ?? '#64748b', '#');
                    $cs->getFont()->setBold(true)->getColor()->setARGB('FF' . strtoupper($hex));
                } elseif ($kind === 'sla') {
                    $fg = $val === 'Vencido' ? 'FFC0392B' : ($val === 'En plazo' ? 'FF0F6E56' : self::TXT_SUAVE);
                    $cs->getFont()->setBold($val !== '—')->getColor()->setARGB($fg);
                } elseif ($c['h'] === 'Código') {
                    $cs->getFont()->setBold(true)->getColor()->setARGB(self::AZUL_OSC);
                }
            }

            // Cebra + borde inferior a toda la fila.
            $filaRango = "A{$fila}:{$ultimaC}{$fila}";
            if ($idx % 2 === 1) {
                // No pisar los fondos de estado: la cebra va como borde/relleno solo donde no hay pill.
                foreach ($cols as $i => $c) {
                    if (($c['kind'] ?? '') === 'estado') continue;
                    $L = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getStyle("{$L}{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::CEBRA);
                }
            }
            $sheet->getStyle($filaRango)->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::LINEA);
            $sheet->getRowDimension($fila)->setRowHeight(20);
            $fila++;
        }

        // Autofiltro + panel congelado (cabecera siempre visible al hacer scroll).
        $sheet->setAutoFilter("A{$hFila}:{$ultimaC}" . ($fila - 1));
        $sheet->freezePane('A' . ($hFila + 1));

        // Nota de pie si se alcanzó el tope.
        if ($total > $shown) {
            $sheet->mergeCells("A{$fila}:{$ultimaC}{$fila}");
            $sheet->setCellValue("A{$fila}", "Se muestran las {$shown} incidencias más recientes de {$total} (tope de exportación). Afina los filtros para acotar.");
            $sheet->getStyle("A{$fila}")->getFont()->setItalic(true)->setSize(10)->getColor()->setARGB(self::TXT_SUAVE);
            $sheet->getRowDimension($fila)->setRowHeight(22);
        }

        // A la vista.
        $sheet->setSelectedCell('A' . ($hFila + 1));

        // Serializar a binario (sin tocar disco).
        $writer = new XlsxWriter($ss);
        ob_start();
        $writer->save('php://output');
        $bin = ob_get_clean();
        $ss->disconnectWorksheets();

        return $bin;
    }

    /** Banda coloreada de ancho completo (cabecera del documento). */
    private function banda($sheet, string $rango, int $row, string $bg, string $fg, float $size, float $alto): void
    {
        $s = $sheet->getStyle($rango);
        $s->getFont()->setBold(true)->setSize($size)->getColor()->setARGB($fg);
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);
        $sheet->getRowDimension($row)->setRowHeight($alto);
    }
}
