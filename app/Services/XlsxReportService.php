<?php

namespace App\Services;

use ZipArchive;

class XlsxReportService
{
    /** @param array<int, array<int, scalar|null>> $rows */
    public function download(string $filename, array $headers, array $rows, string $title = '', bool $inline = false)
    {
        $temp = tempnam(sys_get_temp_dir(), 'report_xlsx_');
        $zip = new ZipArchive();
        $zip->open($temp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

        $allRows = [];
        if ($title !== '') $allRows[] = [$title];
        $allRows[] = $headers;
        array_push($allRows, ...$rows);

        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
        $sheet .= '<col min="1" max="1" width="18" customWidth="1"/>';
        $sheet .= '<col min="2" max="2" width="18" customWidth="1"/>';
        $sheet .= '<col min="3" max="3" width="26" customWidth="1"/>';
        $sheet .= '<col min="4" max="4" width="10" customWidth="1"/>';
        $sheet .= '<col min="5" max="5" width="14" customWidth="1"/>';
        $sheet .= '<col min="6" max="6" width="14" customWidth="1"/>';
        $sheet .= '<col min="7" max="7" width="12" customWidth="1"/>';
        $sheet .= '<col min="8" max="8" width="18" customWidth="1"/>';
        $sheet .= '<col min="9" max="9" width="18" customWidth="1"/>';
        $sheet .= '<col min="10" max="10" width="18" customWidth="1"/>';
        $sheet .= '<col min="11" max="11" width="18" customWidth="1"/>';
        $sheet .= '<col min="12" max="12" width="18" customWidth="1"/>';
        $sheet .= '</cols><sheetData>';
        foreach ($allRows as $rowIndex => $row) {
            $sheet .= '<row r="' . ($rowIndex + 1) . '">';
            foreach (array_values($row) as $colIndex => $value) {
                $ref = $this->column($colIndex + 1) . ($rowIndex + 1);
                $escaped = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $style = $title !== '' && $rowIndex === 0 ? 1 : 2;
                $sheet .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t>' . $escaped . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return response()->download($temp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"',
        ])->deleteFileAfterSend(true);
    }

    /**
     * ASS-003 forecast asset list (Excel icon on the list page): title, the calculation's conditions, the
     * asset table and a total row. Amounts are numbers, so the sheet can be summed and filtered.
     *
     * @param list<array<string, mixed>> $assets
     */
    public function structuredForecastAssets(string $filename, string $title, string $conditions, string $percent, array $assets, float $totalBudget)
    {
        $headers = ['ลำดับ', 'รหัสครุภัณฑ์', 'ชื่อครุภัณฑ์', 'หมวดครุภัณฑ์', 'หน่วยงาน', 'วันที่ตรวจรับ', 'ปีที่ครบกำหนดทดแทน',
            'มูลค่า', 'ค่าเสื่อมราคาสะสม', 'มูลค่าคงเหลือ', "งบประมาณทดแทน (+{$percent}%)"];
        $last = $this->column(count($headers));
        $money = fn ($value) => is_numeric($value) ? ['value' => (float) $value, 'style' => 'money'] : ['value' => '-', 'style' => 'header'];

        $rows = [
            [['value' => $title, 'style' => 'title']],
            [['value' => $conditions, 'style' => 'label']],
            array_map(fn (string $header) => ['value' => $header, 'style' => 'header'], $headers),
        ];
        foreach (array_values($assets) as $index => $asset) {
            $rows[] = [
                ['value' => $index + 1, 'style' => 'header'],
                ['value' => $asset['display_code'] ?? $asset['asset_code'] ?? '-', 'style' => 'value'],
                ['value' => $asset['category_name'] ?? '-', 'style' => 'value'],
                ['value' => $asset['category_group'] ?? '-', 'style' => 'value'],
                ['value' => $asset['organization_name'] ?? '-', 'style' => 'value'],
                ['value' => $asset['acceptance_date'] ?? '-', 'style' => 'date'],
                ['value' => 'พ.ศ. ' . ((int) ($asset['forecast_year'] ?? 0) + 543), 'style' => 'date'],
                $money($asset['acquisition_value'] ?? null),
                $money($asset['accumulated_depreciation'] ?? null),
                $money($asset['current_value'] ?? null),
                $money($asset['replacement_budget'] ?? null),
            ];
        }
        $totalRow = count($rows) + 1;
        $rows[] = [
            ['value' => 'รวม ' . number_format(count($assets)) . ' รายการ', 'style' => 'label'],
            ...array_fill(0, count($headers) - 2, ['value' => '', 'style' => 'blank']),
            ['value' => round($totalBudget, 2), 'style' => 'money'],
        ];

        return $this->buildStructuredWorkbook($filename, [
            'cols'    => array_map(
                fn (int $width, int $index) => ['min' => $index + 1, 'max' => $index + 1, 'width' => $width, 'customWidth' => true],
                [8, 18, 30, 26, 30, 13, 14, 14, 17, 14, 20],
                array_keys($headers),
            ),
            'rows'    => $rows,
            'merges'  => ["A1:{$last}1", "A2:{$last}2", "A{$totalRow}:J{$totalRow}"],
            'heights' => [1 => 30],
        ]);
    }

    /** @param array<int, array<int, scalar|null>> $rows */
    public function structuredAssetLedger(
        string $filename,
        string $title,
        string $organizationLine,
        array $headers,
        array $rows,
        bool $inline = false,
    ) {
        $blankRow = array_fill(0, count($headers), ['value' => '', 'style' => 'blank']);
        $titleRow = $blankRow;
        $titlePrefix = 'รายงานทะเบียนครุภัณฑ์';
        $titleSuffix = preg_replace('/^รายงานทะเบียนครุภัณฑ์\s*/u', '', $title) ?: $title;
        $titleRow[4] = ['value' => $titlePrefix, 'style' => 'title-left'];
        $titleRow[7] = ['value' => $titleSuffix, 'style' => 'title-right'];
        $organizationRow = $blankRow;
        $organizationRow[7] = ['value' => $organizationLine, 'style' => 'value'];

        $sheetRows = [$titleRow, $organizationRow, array_map(
            fn (string $header) => ['value' => $header, 'style' => 'header'],
            $headers,
        )];

        foreach ($rows as $row) {
            $values = array_values($row);
            $sheetRows[] = array_map(
                function ($value, int $index) {
                    $style = match ($index) {
                        0, 1, 2, 9, 10, 14 => 'center',
                        11 => 'money',
                        default => 'value',
                    };

                    return ['value' => $value ?? '-', 'style' => $style];
                },
                $values,
                array_keys($values),
            );
        }

        $columns = [11, 12, 14, 16, 18, 18, 28, 18, 18, 10, 12, 18, 18, 18, 14];

        return $this->buildStructuredWorkbook($filename, [
            'cols' => array_map(
                fn (int $width, int $index) => [
                    'min' => $index + 1,
                    'max' => $index + 1,
                    'width' => $width,
                    'customWidth' => true,
                ],
                $columns,
                array_keys($columns),
            ),
            'rows' => $sheetRows,
            'merges' => ['E1:G1', 'H1:O1', 'H2:O2'],
            'heights' => [1 => 30],
        ], $inline);
    }

    /**
     * Build a report layout that mirrors the preview report structure, including grouped title,
     * static labels, merged cells, and the asset/depreciation table rows.
     *
     * @param array<int, array<string, mixed>> $assets
     * @return array{cols: array<int, array{min:int,max:int,width:float,customWidth:bool}>, rows: array<int, array<int, array{value:scalar,style?:string,merge?:string}|scalar>>, merges: array<int, string>}
     */
    public function structuredAssetRegister(string $filename, array $assets, bool $inline = false): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return $this->buildStructuredWorkbook($filename, $this->assetRegisterLayoutSpec($assets), $inline);

        $first = $assets[0] ?? [];
        $rows = [
            [
                ['value' => 'รายงานทะเบียนคุมทรัพย์สิน', 'style' => 'title', 'merge' => 'A1:L1'],
            ],
            [
                ['value' => 'ส่วนราชการ', 'style' => 'label'],
                ['value' => $first['government_department'] ?? 'กรมส่งเสริมสหกรณ์', 'style' => 'value'],
                ['value' => 'หน่วยงาน', 'style' => 'label'],
                ['value' => $first['org_name'] ?? '-', 'style' => 'value'],
                ['value' => 'ประเภท', 'style' => 'label'],
                ['value' => $first['asscat_name'] ?? '-', 'style' => 'value'],
                ['value' => 'รหัสครุภัณฑ์', 'style' => 'label'],
                ['value' => $first['asset_code'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'สถานที่ตั้ง/หน่วยงานผู้รับผิดชอบ', 'style' => 'label'],
                ['value' => $first['sub_org_name'] ?? '-', 'style' => 'value'],
                ['value' => 'ที่อยู่', 'style' => 'label'],
                ['value' => $first['dealer_name'] ?? '-', 'style' => 'value'],
                ['value' => 'ชื่อผู้ขาย/ผู้รับจ้าง', 'style' => 'label'],
                ['value' => $first['dealer_name'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'วัน เดือน ปี', 'style' => 'header'],
                ['value' => 'เลขที่เอกสาร', 'style' => 'header'],
                ['value' => 'รายละเอียดครุภัณฑ์/สิ่งก่อสร้าง', 'style' => 'header', 'merge' => 'C4:H4'],
                ['value' => 'ค่าเสื่อมราคา\nประจำปี', 'style' => 'header'],
                ['value' => 'ค่าเสื่อมราคา\nสะสม', 'style' => 'header'],
                ['value' => 'มูลค่าสุทธิ', 'style' => 'header'],
                ['value' => 'หมายเหตุ', 'style' => 'header'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => 'ชื่อสินทรัพย์ คำอธิบายและรายละเอียด\nลักษณะ/คุณสมบัติ/ขนาด/ยี่ห้อ/รุ่น/\nแบบ/หมายเลขเครื่อง/', 'style' => 'header'],
                ['value' => 'จำนวน\n(หน่วย)', 'style' => 'header'],
                ['value' => 'ราคาต่อหน่วย/\nชุด/กลุ่ม', 'style' => 'header'],
                ['value' => 'มูลค่ารวม', 'style' => 'header'],
                ['value' => 'อายุการ\nใช้งาน', 'style' => 'header'],
                ['value' => 'อัตรา\nค่าเสื่อมราคา', 'style' => 'header'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'],
            ],
        ];

        $merges = ['A1:L1', 'A4:A5', 'B4:B5', 'C4:H4', 'I4:I5', 'J4:J5', 'K4:K5', 'L4:L5'];

        foreach ($assets as $asset) {
            $rows[] = [
                ['value' => $asset['inspect_date_th'] ?? '-', 'style' => 'date'],
                ['value' => $asset['document'] ?? '-', 'style' => 'value'],
                ['value' => trim((string) ($asset['description'] ?? '-')), 'style' => 'description'],
                ['value' => '1', 'style' => 'number'],
                ['value' => $asset['ass_price'] ?? '0.00', 'style' => 'money'],
                ['value' => $asset['ass_price'] ?? '0.00', 'style' => 'money'],
                ['value' => $asset['ass_lifetime'] ?? '0', 'style' => 'number'],
                ['value' => ($asset['depreciation_rate'] ?? '0') . '%', 'style' => 'percent'],
                ['value' => $asset['annual_depreciation'] ?? '0.00', 'style' => 'money'],
                ['value' => '0.00', 'style' => 'money'],
                ['value' => $asset['ass_price'] ?? '0.00', 'style' => 'money'],
                ['value' => $asset['remarks'] ?? '-', 'style' => 'value'],
            ];

            foreach ($asset['depreciation_periods'] ?? [] as $period) {
                $rows[] = [
                    ['value' => '', 'style' => 'blank'],
                    ['value' => '', 'style' => 'blank'],
                    ['value' => $period['label'] ?? '-', 'style' => 'description', 'merge' => 'C' . (count($rows) + 1) . ':H' . (count($rows) + 1)],
                    ['value' => $period['depreciation'] ?? '0.00', 'style' => 'money'],
                    ['value' => $period['accumulated'] ?? '0.00', 'style' => 'money'],
                    ['value' => $period['net_value'] ?? '0.00', 'style' => 'money'],
                    ['value' => '', 'style' => 'blank'],
                    ['value' => '', 'style' => 'blank'],
                    ['value' => '', 'style' => 'blank'],
                    ['value' => '', 'style' => 'blank'],
                    ['value' => '', 'style' => 'blank'],
                    ['value' => '', 'style' => 'blank'],
                ];
                $merges[] = 'C' . count($rows) . ':H' . count($rows);
            }
        }

        $sheet = [
            'cols' => [
                ['min' => 1, 'max' => 1, 'width' => 11, 'customWidth' => true],
                ['min' => 2, 'max' => 2, 'width' => 12, 'customWidth' => true],
                ['min' => 3, 'max' => 3, 'width' => 30, 'customWidth' => true],
                ['min' => 4, 'max' => 4, 'width' => 8, 'customWidth' => true],
                ['min' => 5, 'max' => 5, 'width' => 12, 'customWidth' => true],
                ['min' => 6, 'max' => 6, 'width' => 12, 'customWidth' => true],
                ['min' => 7, 'max' => 7, 'width' => 8, 'customWidth' => true],
                ['min' => 8, 'max' => 8, 'width' => 10, 'customWidth' => true],
                ['min' => 9, 'max' => 9, 'width' => 14, 'customWidth' => true],
                ['min' => 10, 'max' => 10, 'width' => 14, 'customWidth' => true],
                ['min' => 11, 'max' => 11, 'width' => 14, 'customWidth' => true],
                ['min' => 12, 'max' => 12, 'width' => 12, 'customWidth' => true],
            ],
            'rows' => $rows,
            'merges' => array_values(array_unique($merges)),
        ];

        return $this->buildStructuredWorkbook($filename, $sheet, $inline);
    }

    /** @param array<int, array<string, mixed>> $assets */
    public function assetRegisterLayoutSpec(array $assets): array
    {
        $first = $assets[0] ?? [];
        $rows = [
            [['value' => 'รายงานทะเบียนคุมทรัพย์สิน', 'style' => 'title']],
            [
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => 'ส่วนราชการ', 'style' => 'label'], ['value' => $first['government_department'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => 'หน่วยงาน', 'style' => 'label'], ['value' => $first['org_name'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'ประเภท', 'style' => 'label'], ['value' => $first['asscat_name'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'รหัสครุภัณฑ์', 'style' => 'label'], ['value' => $first['asset_code'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'สถานที่ตั้ง/หน่วยงานผู้รับผิดชอบ', 'style' => 'label'], ['value' => $first['sub_org_name'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'ที่อยู่', 'style' => 'label'], ['value' => $first['dealer_name'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'ชื่อผู้ขาย/ผู้รับจ้าง', 'style' => 'label'], ['value' => $first['dealer_name'] ?? '-', 'style' => 'value'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
            [
                ['value' => 'วัน เดือน ปี', 'style' => 'header'], ['value' => 'เลขที่เอกสาร', 'style' => 'header'],
                ['value' => 'รายละเอียดครุภัณฑ์/สิ่งก่อสร้าง', 'style' => 'header'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => "ค่าเสื่อมราคา\nประจำปี", 'style' => 'header'], ['value' => "ค่าเสื่อมราคา\nสะสม", 'style' => 'header'],
                ['value' => 'มูลค่าสุทธิ', 'style' => 'header'], ['value' => 'หมายเหตุ', 'style' => 'header'],
            ],
            [
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ['value' => "ชื่อสินทรัพย์ คำอธิบายและรายละเอียด\nลักษณะ/คุณสมบัติ/ขนาด/ยี่ห้อ/รุ่น/\nแบบ/หมายเลขเครื่อง/", 'style' => 'header'],
                ['value' => "จำนวน\n(หน่วย)", 'style' => 'header'], ['value' => "ราคาต่อหน่วย/\nชุด/กลุ่ม", 'style' => 'header'],
                ['value' => 'มูลค่ารวม', 'style' => 'header'], ['value' => "อายุการ\nใช้งาน", 'style' => 'header'],
                ['value' => "อัตรา\nค่าเสื่อมราคา", 'style' => 'header'],
                ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
            ],
        ];

        $merges = ['A1:L1', 'H2:L2', 'H3:L3', 'B4:F4', 'B5:F5', 'B6:F6', 'B7:F7', 'B8:F8', 'A9:A10', 'B9:B10', 'C9:H9', 'I9:I10', 'J9:J10', 'K9:K10', 'L9:L10'];
        foreach ($assets as $asset) {
            $rows[] = [
                ['value' => $asset['inspect_date_th'] ?? '-', 'style' => 'date'], ['value' => $asset['document'] ?? '-', 'style' => 'value'],
                ['value' => $asset['description'] ?? '-', 'style' => 'description'], ['value' => '1', 'style' => 'number'],
                ['value' => $asset['ass_price'] ?? '0.00', 'style' => 'money'], ['value' => $asset['ass_price'] ?? '0.00', 'style' => 'money'],
                ['value' => $asset['ass_lifetime'] ?? '0', 'style' => 'number'], ['value' => ($asset['depreciation_rate'] ?? '0') . '%', 'style' => 'percent'],
                ['value' => $asset['annual_depreciation'] ?? '0.00', 'style' => 'money'], ['value' => '0.00', 'style' => 'money'],
                ['value' => $asset['ass_price'] ?? '0.00', 'style' => 'money'], ['value' => $asset['remarks'] ?? '-', 'style' => 'value'],
            ];
            foreach ($asset['depreciation_periods'] ?? [] as $period) {
                $row = count($rows) + 1;
                $rows[] = [
                    ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                    ['value' => $period['label'] ?? '-', 'style' => 'description'],
                    ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                    ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                    ['value' => $period['depreciation'] ?? '0.00', 'style' => 'money'],
                    ['value' => $period['accumulated'] ?? '0.00', 'style' => 'money'],
                    ['value' => $period['net_value'] ?? '0.00', 'style' => 'money'],
                    ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'], ['value' => '', 'style' => 'blank'],
                ];
                $merges[] = 'C' . $row . ':H' . $row;
            }
        }

        return [
            'cols' => [
                ['min' => 1, 'max' => 1, 'width' => 11, 'customWidth' => true], ['min' => 2, 'max' => 2, 'width' => 20, 'customWidth' => true],
                ['min' => 3, 'max' => 3, 'width' => 40, 'customWidth' => true], ['min' => 4, 'max' => 4, 'width' => 6, 'customWidth' => true],
                ['min' => 5, 'max' => 5, 'width' => 12, 'customWidth' => true], ['min' => 6, 'max' => 6, 'width' => 12, 'customWidth' => true],
                ['min' => 7, 'max' => 7, 'width' => 6, 'customWidth' => true], ['min' => 8, 'max' => 8, 'width' => 10, 'customWidth' => true],
                ['min' => 9, 'max' => 9, 'width' => 14, 'customWidth' => true], ['min' => 10, 'max' => 10, 'width' => 14, 'customWidth' => true],
                ['min' => 11, 'max' => 11, 'width' => 14, 'customWidth' => true], ['min' => 12, 'max' => 12, 'width' => 12, 'customWidth' => true],
            ],
            'rows' => $rows,
            'merges' => array_values(array_unique($merges)),
            // 18pt title; the second header row carries three lines of text.
            'heights' => [1 => 30, 10 => 72],
        ];
    }

    public function buildStructuredWorkbook(string $filename, array $sheet, bool $inline = false)
    {
        $temp = tempnam(sys_get_temp_dir(), 'report_xlsx_');
        $zip = new ZipArchive();
        $zip->open($temp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/styles.xml', $this->buildStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildSheetXml($sheet));
        $zip->close();

        return response()->download($temp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"',
        ])->deleteFileAfterSend(true);
    }

    private function buildSheetXml(array $sheet): string
    {
        $cols = '';
        foreach ($sheet['cols'] ?? [] as $col) {
            $cols .= '<col min="' . $col['min'] . '" max="' . $col['max'] . '" width="' . $col['width'] . '" customWidth="1"/>';
        }

        $rowsXml = '';
        foreach ($sheet['rows'] ?? [] as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            // Rows without a fixed height auto-fit their wrapped text when the file is opened.
            $height = $sheet['heights'][$rowNumber] ?? null;
            $rowsXml .= '<row r="' . $rowNumber . '"' . ($height !== null ? ' ht="' . $height . '" customHeight="1"' : '') . '>';
            foreach ($row as $colIndex => $cell) {
                $cellData = is_array($cell) ? ($cell['value'] ?? '') : $cell;
                $styleName = is_array($cell) ? ($cell['style'] ?? '') : '';
                $style = $this->styleIndex($styleName);
                $cellRef = $this->column($colIndex + 1) . ($rowIndex + 1);
                if (in_array($styleName, ['money', 'number', 'percent'], true) && is_numeric(str_replace('%', '', (string) $cellData))) {
                    $numericValue = (float) str_replace('%', '', (string) $cellData);
                    if ($styleName === 'percent') {
                        $numericValue /= 100;
                    }
                    $rowsXml .= '<c r="' . $cellRef . '" s="' . $style . '"><v>' . $numericValue . '</v></c>';
                } else {
                    $escaped = htmlspecialchars((string) $cellData, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $rowsXml .= '<c r="' . $cellRef . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
                }
            }
            $rowsXml .= '</row>';
        }

        $mergeXml = '';
        $merges = $sheet['merges'] ?? [];
        if (!empty($merges)) {
            $mergeXml = '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $range) {
                $mergeXml .= '<mergeCell ref="' . $range . '"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        return '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>' . $cols . '</cols><sheetData>' . $rowsXml . '</sheetData>' . $mergeXml . '</worksheet>';
    }

    private function buildStylesXml(): string
    {
        return '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="16"/><name val="TH Sarabun New"/><charset val="222"/></font><font><b/><sz val="18"/><name val="TH Sarabun New"/><charset val="222"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="10"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf><xf numFmtId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function styleIndex(string $style): int
    {
        return match ($style) {
            'title' => 1,
            'header' => 2,
            'label' => 5,
            'value' => 4,
            'date' => 2,
            'description' => 4,
            'number' => 6,
            'money' => 3,
            'percent' => 7,
            'title-left' => 8,
            'title-right' => 9,
            'blank' => 0,
            default => 0,
        };
    }

    private function column(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }
}
