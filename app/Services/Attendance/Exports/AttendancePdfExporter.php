<?php

namespace App\Services\Attendance\Exports;

class AttendancePdfExporter
{
    /**
     * @param array{
     *     title: string,
     *     generated_at: string,
     *     scope: string,
     *     period_label: string,
     *     filter_summary: array<int, string>,
     *     summary: array<string, int>,
     *     records: array<int, array<string, string|int|float|null>>
     * } $report
     */
    public function store(string $path, array $report): void
    {
        $pages = $this->paginateLines($this->lines($report));
        file_put_contents($path, $this->pdfDocument($pages));
    }

    /**
     * @param array{
     *     title: string,
     *     generated_at: string,
     *     scope: string,
     *     period_label: string,
     *     filter_summary: array<int, string>,
     *     summary: array<string, int>,
     *     records: array<int, array<string, string|int|float|null>>
     * } $report
     * @return array<int, string>
     */
    private function lines(array $report): array
    {
        $lines = [
            $report['title'],
            'Generated At: '.$report['generated_at'],
            'Scope: '.$report['scope'],
            'Period: '.$report['period_label'],
        ];

        foreach ($report['filter_summary'] as $filterLine) {
            $lines[] = $filterLine;
        }

        $lines[] = '';
        $lines[] = 'Summary';

        foreach ($report['summary'] as $key => $value) {
            $lines[] = str_replace('_', ' ', ucfirst($key)).': '.$value;
        }

        $lines[] = '';
        $lines[] = $this->tableHeader();
        $lines[] = str_repeat('-', strlen($this->tableHeader()));

        foreach ($report['records'] as $record) {
            $lines[] = $this->tableRow($record);
        }

        return $lines;
    }

    /**
     * @param  array<string, string|int|float|null>  $record
     */
    private function tableRow(array $record): string
    {
        return implode(' | ', [
            $this->fit((string) ($record['attendance_date'] ?? '--'), 10),
            $this->fit((string) ($record['employee_name'] ?? '--'), 18),
            $this->fit((string) ($record['department'] ?? '--'), 14),
            $this->fit((string) ($record['check_in_time'] ?? '--'), 8),
            $this->fit((string) ($record['check_out_time'] ?? '--'), 8),
            $this->fit((string) ($record['worked_minutes'] ?? 0), 5, false),
            $this->fit((string) ($record['status'] ?? '--'), 10),
            $this->fit((string) ($record['late_minutes'] ?? 0), 4, false),
            $this->fit((string) ($record['early_leave_minutes'] ?? 0), 5, false),
            $this->fit((string) ($record['overtime_minutes'] ?? 0), 4, false),
            $this->fit((string) ($record['correction_status'] ?? '--'), 10),
        ]);
    }

    private function tableHeader(): string
    {
        return implode(' | ', [
            $this->fit('Date', 10),
            $this->fit('Employee', 18),
            $this->fit('Department', 14),
            $this->fit('Check In', 8),
            $this->fit('Check Out', 8),
            $this->fit('Work', 5, false),
            $this->fit('Status', 10),
            $this->fit('Late', 4, false),
            $this->fit('Early', 5, false),
            $this->fit('OT', 4, false),
            $this->fit('Correction', 10),
        ]);
    }

    private function fit(string $value, int $length, bool $padRight = true): string
    {
        $trimmed = mb_strimwidth($value, 0, $length, '');

        return $padRight
            ? str_pad($trimmed, $length)
            : str_pad($trimmed, $length, ' ', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<int, string>>
     */
    private function paginateLines(array $lines): array
    {
        return array_chunk($lines, 46);
    }

    /**
     * @param  array<int, array<int, string>>  $pages
     */
    private function pdfDocument(array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];

        $kids = [];
        $objectId = 4;

        foreach ($pages as $pageLines) {
            $pageId = $objectId++;
            $contentId = $objectId++;
            $kids[] = $pageId.' 0 R';
            $content = $this->pageContent($pageLines);

            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>',
                $contentId,
            );

            $objects[$contentId] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content,
            );
        }

        $objects[2] = sprintf(
            '<< /Type /Pages /Count %d /Kids [ %s ] >>',
            count($kids),
            implode(' ', $kids),
        );

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
        }

        $xrefOffset = strlen($pdf);
        $objectCount = max(array_keys($objects));
        $pdf .= "xref\n0 ".($objectCount + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $objectCount; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $pdf .= "trailer\n";
        $pdf .= sprintf("<< /Size %d /Root 1 0 R >>\n", $objectCount + 1);
        $pdf .= "startxref\n";
        $pdf .= $xrefOffset."\n";
        $pdf .= '%%EOF';

        return $pdf;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function pageContent(array $lines): string
    {
        $content = "BT\n/F1 9 Tf\n14 TL\n40 800 Td\n";

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -14 Td\n";
            }

            $content .= sprintf("(%s) Tj\n", $this->escapeText($line));
        }

        $content .= 'ET';

        return $content;
    }

    private function escapeText(string $text): string
    {
        return str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\(', '\)'],
            $text,
        );
    }
}
