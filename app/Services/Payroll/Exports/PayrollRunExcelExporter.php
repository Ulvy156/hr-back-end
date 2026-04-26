<?php

namespace App\Services\Payroll\Exports;

use ZipArchive;

class PayrollRunExcelExporter
{
    /**
     * @param  array{
     *     title: string,
     *     generated_at: string,
     *     payroll_month: string,
     *     status: string,
     *     summary: array<string, string|int>,
     *     records: array<int, array<string, string>>
     * }  $report
     */
    public function store(string $path, array $report): void
    {
        $zip = new ZipArchive;
        $status = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($status !== true) {
            throw new \RuntimeException('Unable to create payroll Excel export archive.');
        }

        $sheetRows = $this->rows($report);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml($report['title']));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($sheetRows));
        $zip->close();
    }

    /**
     * @param  array{
     *     title: string,
     *     generated_at: string,
     *     payroll_month: string,
     *     status: string,
     *     summary: array<string, string|int>,
     *     records: array<int, array<string, string>>
     * }  $report
     * @return array<int, array<int, array{value: string|int|float|null, style: int}>>
     */
    private function rows(array $report): array
    {
        $rows = [
            [
                ['value' => $report['title'], 'style' => 1],
            ],
            [
                ['value' => 'Generated At', 'style' => 1],
                ['value' => $report['generated_at'], 'style' => 0],
            ],
            [
                ['value' => 'Payroll Month', 'style' => 1],
                ['value' => $report['payroll_month'], 'style' => 0],
            ],
            [
                ['value' => 'Status', 'style' => 1],
                ['value' => $report['status'], 'style' => 0],
            ],
            [
                ['value' => 'Employee Count', 'style' => 1],
                ['value' => $report['summary']['employee_count'] ?? 0, 'style' => 0],
            ],
            [
                ['value' => 'Total Base Salary', 'style' => 1],
                ['value' => $report['summary']['total_base_salary'] ?? null, 'style' => 0],
            ],
            [
                ['value' => 'Total Prorated Base Salary', 'style' => 1],
                ['value' => $report['summary']['total_prorated_base_salary'] ?? null, 'style' => 0],
            ],
            [
                ['value' => 'Total Overtime Pay', 'style' => 1],
                ['value' => $report['summary']['total_overtime_pay'] ?? null, 'style' => 0],
            ],
            [
                ['value' => 'Total Unpaid Leave Deduction', 'style' => 1],
                ['value' => $report['summary']['total_unpaid_leave_deduction'] ?? null, 'style' => 0],
            ],
            [
                ['value' => 'Total Tax Amount', 'style' => 1],
                ['value' => $report['summary']['total_tax_amount'] ?? null, 'style' => 0],
            ],
            [
                ['value' => 'Total Net Salary', 'style' => 1],
                ['value' => $report['summary']['total_net_salary'] ?? null, 'style' => 0],
            ],
            [['value' => null, 'style' => 0]],
            array_map(
                fn (string $header): array => ['value' => $header, 'style' => 1],
                [
                    'Payroll Month',
                    'Status',
                    'Employee Code',
                    'Employee Name',
                    'Base Salary',
                    'Prorated Base Salary',
                    'OT Normal Hours',
                    'OT Weekend Hours',
                    'OT Holiday Hours',
                    'Overtime Pay',
                    'Unpaid Leave Units',
                    'Unpaid Leave Deduction',
                    'Tax Amount',
                    'Net Salary',
                ],
            ),
        ];

        foreach ($report['records'] as $record) {
            $rows[] = [
                ['value' => $record['payroll_month'] ?? null, 'style' => 0],
                ['value' => $record['status'] ?? null, 'style' => 0],
                ['value' => $record['employee_code'] ?? null, 'style' => 0],
                ['value' => $record['employee_name'] ?? null, 'style' => 0],
                ['value' => $record['base_salary'] ?? null, 'style' => 0],
                ['value' => $record['prorated_base_salary'] ?? null, 'style' => 0],
                ['value' => $record['overtime_normal_hours'] ?? null, 'style' => 0],
                ['value' => $record['overtime_weekend_hours'] ?? null, 'style' => 0],
                ['value' => $record['overtime_holiday_hours'] ?? null, 'style' => 0],
                ['value' => $record['overtime_pay'] ?? null, 'style' => 0],
                ['value' => $record['unpaid_leave_units'] ?? null, 'style' => 0],
                ['value' => $record['unpaid_leave_deduction'] ?? null, 'style' => 0],
                ['value' => $record['tax_amount'] ?? null, 'style' => 0],
                ['value' => $record['net_salary'] ?? null, 'style' => 0],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, array{value: string|int|float|null, style: int}>>  $rows
     */
    private function worksheetXml(array $rows): string
    {
        $xmlRows = '';
        $maxColumn = 1;

        foreach ($rows as $rowIndex => $cells) {
            $xmlRows .= sprintf('<row r="%d">', $rowIndex + 1);
            $maxColumn = max($maxColumn, count($cells));

            foreach ($cells as $columnIndex => $cell) {
                $xmlRows .= $this->cellXml($rowIndex + 1, $columnIndex + 1, $cell['value'], $cell['style']);
            }

            $xmlRows .= '</row>';
        }

        $dimension = 'A1:'.$this->columnName($maxColumn).count($rows);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <dimension ref="{$dimension}"/>
  <sheetViews><sheetView workbookViewId="0"/></sheetViews>
  <sheetFormatPr defaultRowHeight="15"/>
  <sheetData>{$xmlRows}</sheetData>
</worksheet>
XML;
    }

    private function cellXml(int $row, int $column, string|int|float|null $value, int $style): string
    {
        $reference = $this->columnName($column).$row;

        if ($value === null || $value === '') {
            return sprintf('<c r="%s" s="%d"/>', $reference, $style);
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $style, $value);
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t>%s</t></is></c>',
            $reference,
            $style,
            htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );
    }

    private function columnName(int $index): string
    {
        $columnName = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $columnName = chr(65 + $remainder).$columnName;
            $index = intdiv($index - 1, 26);
        }

        return $columnName;
    }

    private function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;
    }

    private function rootRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function appPropertiesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Codex</Application>
</Properties>
XML;
    }

    private function corePropertiesXml(string $title): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $createdAt = now()->toIso8601ZuluString();

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>{$escapedTitle}</dc:title>
  <dc:creator>Codex</dc:creator>
  <cp:lastModifiedBy>Codex</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$createdAt}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{$createdAt}</dcterms:modified>
</cp:coreProperties>
XML;
    }

    private function workbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Payroll Export" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private function workbookRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font>
      <sz val="11"/>
      <color theme="1"/>
      <name val="Calibri"/>
      <family val="2"/>
    </font>
    <font>
      <b/>
      <sz val="11"/>
      <color theme="1"/>
      <name val="Calibri"/>
      <family val="2"/>
    </font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>
XML;
    }
}
