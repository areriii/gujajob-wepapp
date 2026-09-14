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
                $sheet .= '<c r="' . $ref . '" t="inlineStr" s="2"><is><t>' . $escaped . '</t></is></c>';
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
                ['value' => 'ทะเบียนคุมครุภัณฑ์', 'style' => 'title', 'merge' => 'A1:L1'],
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
            [['value' => 'ทะเบียนคุมครุภัณฑ์', 'style' => 'title']],
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
                ['min' => 1, 'max' => 1, 'width' => 11, 'customWidth' => true], ['min' => 2, 'max' => 2, 'width' => 12, 'customWidth' => true],
                ['min' => 3, 'max' => 3, 'width' => 30, 'customWidth' => true], ['min' => 4, 'max' => 4, 'width' => 8, 'customWidth' => true],
                ['min' => 5, 'max' => 5, 'width' => 12, 'customWidth' => true], ['min' => 6, 'max' => 6, 'width' => 12, 'customWidth' => true],
                ['min' => 7, 'max' => 7, 'width' => 8, 'customWidth' => true], ['min' => 8, 'max' => 8, 'width' => 10, 'customWidth' => true],
                ['min' => 9, 'max' => 9, 'width' => 14, 'customWidth' => true], ['min' => 10, 'max' => 10, 'width' => 14, 'customWidth' => true],
                ['min' => 11, 'max' => 11, 'width' => 14, 'customWidth' => true], ['min' => 12, 'max' => 12, 'width' => 12, 'customWidth' => true],
            ],
            'rows' => $rows,
            'merges' => array_values(array_unique($merges)),
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
            $height = $rowNumber === 9 || $rowNumber === 10 ? '42' : ($rowNumber >= 11 ? '30' : '24');
            $rowsXml .= '<row r="' . $rowNumber . '" ht="' . $height . '" customHeight="1">';
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
        return '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="12"/><name val="Tahoma"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
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
