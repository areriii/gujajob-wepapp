<?php

/**
 * สร้างรูปภาพครุภัณฑ์สำหรับเดโม ASS-003
 *
 * ทำไมเขียนเอง
 *   PHP บนเครื่องนี้ไม่มี GD และไม่มี ImageMagick / Pillow ให้ใช้
 *   สคริปต์นี้จึงวาดภาพลงบัฟเฟอร์เองแล้วเข้ารหัสเป็น PNG ด้วย zlib ที่ PHP มีมาให้อยู่แล้ว
 *   ไม่ต้องลงอะไรเพิ่ม รันซ้ำได้ผลเหมือนเดิมทุกครั้ง
 *
 * ภาพที่ได้
 *   ภาพประกอบแบบแบน (flat illustration) ของครุภัณฑ์แต่ละชนิด พื้นหลังไล่เฉดอ่อน
 *   มุมล่างมีรหัสครุภัณฑ์กับรุ่น เป็นตัวอักษรบิตแมปที่ฝังไว้ในไฟล์นี้ (อังกฤษ/ตัวเลขเท่านั้น)
 *   ชื่อครุภัณฑ์ภาษาไทยไม่ได้วาดลงภาพ เพราะไม่มีตัวเรนเดอร์ฟอนต์
 *   แต่หน้าเว็บแสดงชื่อไทยอยู่ข้างรูปอยู่แล้ว
 *
 * วิธีรัน
 *   php scripts/make-demo-asset-photos.php
 *
 * ไฟล์ออกที่ docs/demo-asset-photos/<slug>.png  ขนาด 640x480 ไฟล์ละไม่เกิน ~60 KB
 * (ฟอร์ม ASS-003 จำกัดไฟล์ละ 1 MB และไม่เกิน 3 รูปต่อครุภัณฑ์)
 */

declare(strict_types=1);

const W   = 640;   // ความกว้างภาพที่ได้
const H   = 480;
const SS  = 2;     // วาดใหญ่กว่าจริง 2 เท่าแล้วย่อลง ขอบจะได้ไม่หยัก
const BW  = W * SS;
const BH  = H * SS;

/** บัฟเฟอร์ภาพ หนึ่งช่องต่อหนึ่งพิกเซล เก็บเป็น 0xRRGGBB */
final class Canvas
{
    /** @var array<int,int> */
    public array $px;

    public function __construct(int $top, int $bottom)
    {
        $this->px = array_fill(0, BW * BH, 0xFFFFFF);
        // พื้นหลังไล่เฉดจากบนลงล่าง
        for ($y = 0; $y < BH; $y++) {
            $t = $y / (BH - 1);
            $c = self::mix($top, $bottom, $t);
            $row = $y * BW;
            for ($x = 0; $x < BW; $x++) {
                $this->px[$row + $x] = $c;
            }
        }
    }

    public static function mix(int $a, int $b, float $t): int
    {
        $t = max(0.0, min(1.0, $t));
        $r = (int) round((($a >> 16) & 255) * (1 - $t) + (($b >> 16) & 255) * $t);
        $g = (int) round((($a >> 8) & 255) * (1 - $t) + (($b >> 8) & 255) * $t);
        $bl = (int) round(($a & 255) * (1 - $t) + ($b & 255) * $t);
        return ($r << 16) | ($g << 8) | $bl;
    }

    public function dot(int $x, int $y, int $color): void
    {
        if ($x < 0 || $y < 0 || $x >= BW || $y >= BH) {
            return;
        }
        $this->px[$y * BW + $x] = $color;
    }

    public function rect(float $x, float $y, float $w, float $h, int $color): void
    {
        $x0 = (int) round($x * SS); $y0 = (int) round($y * SS);
        $x1 = (int) round(($x + $w) * SS); $y1 = (int) round(($y + $h) * SS);
        for ($yy = $y0; $yy < $y1; $yy++) {
            if ($yy < 0 || $yy >= BH) { continue; }
            $row = $yy * BW;
            for ($xx = max(0, $x0); $xx < min(BW, $x1); $xx++) {
                $this->px[$row + $xx] = $color;
            }
        }
    }

    /** สี่เหลี่ยมมุมมน */
    public function round(float $x, float $y, float $w, float $h, float $r, int $color): void
    {
        $x0 = $x * SS; $y0 = $y * SS; $x1 = ($x + $w) * SS; $y1 = ($y + $h) * SS; $rr = $r * SS;
        for ($yy = (int) floor($y0); $yy < (int) ceil($y1); $yy++) {
            if ($yy < 0 || $yy >= BH) { continue; }
            $row = $yy * BW;
            for ($xx = (int) floor($x0); $xx < (int) ceil($x1); $xx++) {
                if ($xx < 0 || $xx >= BW) { continue; }
                $cx = $xx + 0.5; $cy = $yy + 0.5;
                $dx = 0.0; $dy = 0.0;
                if ($cx < $x0 + $rr) { $dx = $x0 + $rr - $cx; } elseif ($cx > $x1 - $rr) { $dx = $cx - ($x1 - $rr); }
                if ($cy < $y0 + $rr) { $dy = $y0 + $rr - $cy; } elseif ($cy > $y1 - $rr) { $dy = $cy - ($y1 - $rr); }
                if ($dx * $dx + $dy * $dy <= $rr * $rr) {
                    $this->px[$row + $xx] = $color;
                }
            }
        }
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry, int $color): void
    {
        $cx *= SS; $cy *= SS; $rx *= SS; $ry *= SS;
        for ($yy = (int) floor($cy - $ry); $yy <= (int) ceil($cy + $ry); $yy++) {
            if ($yy < 0 || $yy >= BH) { continue; }
            $row = $yy * BW;
            for ($xx = (int) floor($cx - $rx); $xx <= (int) ceil($cx + $rx); $xx++) {
                if ($xx < 0 || $xx >= BW) { continue; }
                $a = ($xx + 0.5 - $cx) / $rx; $b = ($yy + 0.5 - $cy) / $ry;
                if ($a * $a + $b * $b <= 1.0) {
                    $this->px[$row + $xx] = $color;
                }
            }
        }
    }

    /** วงแหวน ใช้ทำล้อรถและเลนส์ */
    public function ring(float $cx, float $cy, float $rOut, float $rIn, int $color): void
    {
        $cxs = $cx * SS; $cys = $cy * SS; $ro = $rOut * SS; $ri = $rIn * SS;
        for ($yy = (int) floor($cys - $ro); $yy <= (int) ceil($cys + $ro); $yy++) {
            if ($yy < 0 || $yy >= BH) { continue; }
            $row = $yy * BW;
            for ($xx = (int) floor($cxs - $ro); $xx <= (int) ceil($cxs + $ro); $xx++) {
                if ($xx < 0 || $xx >= BW) { continue; }
                $d = sqrt(($xx + 0.5 - $cxs) ** 2 + ($yy + 0.5 - $cys) ** 2);
                if ($d <= $ro && $d >= $ri) {
                    $this->px[$row + $xx] = $color;
                }
            }
        }
    }

    /** สี่เหลี่ยมคางหมู ใช้ทำจอโน้ตบุ๊กและฐานรองรับ */
    public function trapezoid(float $xTopL, float $xTopR, float $yTop, float $xBotL, float $xBotR, float $yBot, int $color): void
    {
        $y0 = (int) round($yTop * SS); $y1 = (int) round($yBot * SS);
        if ($y1 <= $y0) { return; }
        for ($yy = $y0; $yy < $y1; $yy++) {
            if ($yy < 0 || $yy >= BH) { continue; }
            $t = ($yy - $y0) / ($y1 - $y0);
            $l = (int) round(($xTopL + ($xBotL - $xTopL) * $t) * SS);
            $r = (int) round(($xTopR + ($xBotR - $xTopR) * $t) * SS);
            $row = $yy * BW;
            for ($xx = max(0, $l); $xx < min(BW, $r); $xx++) {
                $this->px[$row + $xx] = $color;
            }
        }
    }

    /** ย่อ 2x2 เป็น 1 พิกเซล แล้วคืนเป็นสตริง RGB เรียงทีละแถว */
    public function flatten(): string
    {
        $out = '';
        for ($y = 0; $y < H; $y++) {
            $r0 = ($y * SS) * BW;
            $r1 = ($y * SS + 1) * BW;
            for ($x = 0; $x < W; $x++) {
                $x0 = $x * SS;
                $r = $g = $b = 0;
                foreach ([$r0 + $x0, $r0 + $x0 + 1, $r1 + $x0, $r1 + $x0 + 1] as $i) {
                    $c = $this->px[$i];
                    $r += ($c >> 16) & 255; $g += ($c >> 8) & 255; $b += $c & 255;
                }
                $out .= chr(intdiv($r, 4)) . chr(intdiv($g, 4)) . chr(intdiv($b, 4));
            }
        }
        return $out;
    }
}

/** ตัวอักษรบิตแมป 5x7 เฉพาะตัวที่ใช้จริงในรหัสครุภัณฑ์และชื่อรุ่น */
const FONT = [
    'A' => ['01110','10001','10001','11111','10001','10001','10001'],
    'B' => ['11110','10001','10001','11110','10001','10001','11110'],
    'C' => ['01111','10000','10000','10000','10000','10000','01111'],
    'D' => ['11110','10001','10001','10001','10001','10001','11110'],
    'E' => ['11111','10000','10000','11110','10000','10000','11111'],
    'F' => ['11111','10000','10000','11110','10000','10000','10000'],
    'G' => ['01111','10000','10000','10111','10001','10001','01111'],
    'H' => ['10001','10001','10001','11111','10001','10001','10001'],
    'I' => ['11111','00100','00100','00100','00100','00100','11111'],
    'J' => ['00111','00010','00010','00010','00010','10010','01100'],
    'K' => ['10001','10010','10100','11000','10100','10010','10001'],
    'L' => ['10000','10000','10000','10000','10000','10000','11111'],
    'M' => ['10001','11011','10101','10101','10001','10001','10001'],
    'N' => ['10001','11001','10101','10011','10001','10001','10001'],
    'O' => ['01110','10001','10001','10001','10001','10001','01110'],
    'P' => ['11110','10001','10001','11110','10000','10000','10000'],
    'Q' => ['01110','10001','10001','10001','10101','10010','01101'],
    'R' => ['11110','10001','10001','11110','10100','10010','10001'],
    'S' => ['01111','10000','10000','01110','00001','00001','11110'],
    'T' => ['11111','00100','00100','00100','00100','00100','00100'],
    'U' => ['10001','10001','10001','10001','10001','10001','01110'],
    'V' => ['10001','10001','10001','10001','10001','01010','00100'],
    'W' => ['10001','10001','10001','10101','10101','11011','10001'],
    'X' => ['10001','10001','01010','00100','01010','10001','10001'],
    'Y' => ['10001','10001','01010','00100','00100','00100','00100'],
    'Z' => ['11111','00001','00010','00100','01000','10000','11111'],
    '0' => ['01110','10001','10011','10101','11001','10001','01110'],
    '1' => ['00100','01100','00100','00100','00100','00100','01110'],
    '2' => ['01110','10001','00001','00010','00100','01000','11111'],
    '3' => ['11111','00010','00100','00010','00001','10001','01110'],
    '4' => ['00010','00110','01010','10010','11111','00010','00010'],
    '5' => ['11111','10000','11110','00001','00001','10001','01110'],
    '6' => ['00110','01000','10000','11110','10001','10001','01110'],
    '7' => ['11111','00001','00010','00100','01000','01000','01000'],
    '8' => ['01110','10001','10001','01110','10001','10001','01110'],
    '9' => ['01110','10001','10001','01111','00001','00010','01100'],
    '-' => ['00000','00000','00000','11111','00000','00000','00000'],
    '/' => ['00001','00010','00010','00100','01000','01000','10000'],
    '.' => ['00000','00000','00000','00000','00000','01100','01100'],
    ' ' => ['00000','00000','00000','00000','00000','00000','00000'],
];

function text(Canvas $c, string $s, float $x, float $y, float $scale, int $color): void
{
    $s = strtoupper($s);
    $cx = $x;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $glyph = FONT[$s[$i]] ?? FONT[' '];
        foreach ($glyph as $gy => $rowBits) {
            for ($gx = 0; $gx < 5; $gx++) {
                if ($rowBits[$gx] === '1') {
                    $c->rect($cx + $gx * $scale, $y + $gy * $scale, $scale, $scale, $color);
                }
            }
        }
        $cx += 6 * $scale;
    }
}

function textWidth(string $s, float $scale): float
{
    return strlen($s) * 6 * $scale;
}

// ── ภาพประกอบของครุภัณฑ์แต่ละชนิด ────────────────────────────────────────────
// ทุกตัววาดอยู่ในกรอบกลางภาพ ประมาณ x 120-520, y 90-330

function drawNotebook(Canvas $c, int $body, int $screen): void
{
    $c->trapezoid(200, 440, 110, 170, 470, 285, $body);          // ฝาจอ
    $c->trapezoid(212, 428, 122, 184, 456, 273, $screen);        // หน้าจอ
    $c->trapezoid(150, 490, 288, 120, 520, 318, $body);          // ฐานคีย์บอร์ด
    $c->round(255, 296, 130, 10, 5, $screen);                    // ทัชแพด
}

function drawPrinter(Canvas $c, int $body, int $accent): void
{
    $c->round(170, 150, 300, 70, 10, $body);                     // ส่วนบน
    $c->rect(230, 120, 180, 34, 0xFFFFFF);                       // กระดาษที่ป้อนเข้า
    $c->round(160, 214, 320, 110, 14, $body);                    // ตัวเครื่อง
    $c->rect(215, 250, 210, 44, 0xFFFFFF);                       // กระดาษที่พิมพ์ออก
    $c->round(380, 228, 74, 14, 7, $accent);                     // แถบแผงควบคุม
    $c->round(205, 318, 230, 20, 8, $accent);                    // ถาดกระดาษ
}

function drawProjector(Canvas $c, int $body, int $accent): void
{
    $c->round(170, 170, 300, 130, 16, $body);
    $c->ellipse(430, 232, 44, 44, 0xFFFFFF);                     // เลนส์
    $c->ring(430, 232, 44, 31, $accent);
    $c->round(205, 196, 110, 16, 8, $accent);                    // ช่องระบายอากาศ
    $c->round(205, 222, 70, 12, 6, $accent);
    $c->rect(205, 300, 26, 16, $body);                           // ขาตั้ง
    $c->rect(410, 300, 26, 16, $body);
}

function drawAirCon(Canvas $c, int $body, int $accent): void
{
    $c->round(140, 150, 360, 96, 22, $body);                     // ตัวเครื่องติดผนัง
    $c->round(170, 214, 300, 18, 9, $accent);                    // บานปรับลม
    $c->round(398, 168, 64, 12, 6, $accent);                     // จอแสดงผล
    for ($i = 0; $i < 3; $i++) {                                 // ลมที่เป่าออกมา
        $y = 268 + $i * 26;
        $c->round(200 + $i * 22, $y, 150 - $i * 16, 10, 5, $accent);
    }
}

function drawFridge(Canvas $c, int $body, int $accent): void
{
    $c->round(230, 100, 180, 250, 16, $body);
    $c->rect(230, 184, 180, 7, $accent);                         // รอยต่อประตู
    $c->round(382, 134, 10, 36, 5, $accent);                     // มือจับบน
    $c->round(382, 206, 10, 54, 5, $accent);                     // มือจับล่าง
    $c->rect(248, 350, 16, 14, $body);                           // ขา
    $c->rect(376, 350, 16, 14, $body);
}

function drawCabinet(Canvas $c, int $body, int $accent): void
{
    $c->round(205, 100, 230, 250, 10, $body);
    for ($i = 0; $i < 4; $i++) {                                 // ลิ้นชัก 4 ชั้น
        $y = 116 + $i * 59;
        $c->rect(219, $y, 202, 46, Canvas::mix($body, 0xFFFFFF, 0.12));
        $c->round(292, $y + 19, 56, 9, 4, $accent);              // มือจับ
    }
}

function drawMotorcycle(Canvas $c, int $body, int $accent): void
{
    $c->ring(205, 285, 56, 34, $body);                           // ล้อหน้า
    $c->ring(445, 285, 56, 34, $body);                           // ล้อหลัง
    $c->trapezoid(250, 410, 232, 235, 425, 262, $body);          // ตัวรถ
    $c->round(290, 206, 110, 26, 10, $accent);                   // เบาะ
    $c->rect(236, 200, 12, 46, $body);                           // โช้คหน้า
    $c->round(206, 190, 70, 12, 6, $accent);                     // แฮนด์
    $c->ellipse(226, 232, 20, 14, $accent);                      // ไฟหน้า
}

function drawScanner(Canvas $c, int $body, int $accent): void
{
    $c->round(160, 190, 320, 36, 10, $body);                     // ฝาปิด
    $c->round(160, 222, 320, 96, 12, Canvas::mix($body, 0xFFFFFF, 0.1));
    $c->rect(190, 236, 260, 54, 0xFFFFFF);                       // กระจกสแกน
    $c->round(196, 254, 248, 8, 4, $accent);                     // แถบไฟสแกน
    $c->round(186, 160, 90, 32, 8, 0xFFFFFF);                    // เอกสารที่ป้อน
}

function drawCopier(Canvas $c, int $body, int $accent): void
{
    $c->round(175, 100, 290, 46, 10, $body);                     // ชุดป้อนเอกสาร
    $c->round(165, 142, 310, 70, 10, Canvas::mix($body, 0xFFFFFF, 0.1));
    $c->rect(205, 158, 230, 40, 0xFFFFFF);                       // ถาดรับงาน
    $c->round(165, 208, 310, 142, 14, $body);                    // ตัวเครื่อง
    $c->round(196, 232, 120, 16, 8, $accent);                    // แผงควบคุม
    $c->rect(205, 268, 230, 26, Canvas::mix($body, 0xFFFFFF, 0.2));  // ลิ้นชักกระดาษ
    $c->rect(205, 302, 230, 26, Canvas::mix($body, 0xFFFFFF, 0.2));
}

function drawDesk(Canvas $c, int $body, int $accent): void
{
    $c->round(140, 170, 360, 22, 6, $body);                      // หน้าโต๊ะ
    $c->rect(160, 192, 20, 150, $body);                          // ขาซ้าย
    $c->round(300, 192, 180, 130, 8, Canvas::mix($body, 0xFFFFFF, 0.12));  // ตู้ลิ้นชัก
    for ($i = 0; $i < 3; $i++) {
        $y = 202 + $i * 42;
        $c->rect(312, $y, 156, 32, $body);
        $c->round(366, $y + 13, 48, 7, 3, $accent);
    }
    $c->rect(140, 336, 360, 12, $accent);                        // เงาใต้โต๊ะ
}

/** เข้ารหัสสตริง RGB เป็นไฟล์ PNG */
function encodePng(string $rgb, int $w, int $h): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00" . substr($rgb, $y * $w * 3, $w * 3);   // filter type 0 ทุกแถว
    }

    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NN', $w, $h) . "\x08\x02\x00\x00\x00")   // 8 bit, truecolor
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}

// ── รายการภาพที่จะสร้าง ──────────────────────────────────────────────────────
// bg = สีพื้นหลังไล่เฉด, body/accent = สีของตัวครุภัณฑ์

$items = [
    ['notebook',     'drawNotebook',   [0xEEF4FF, 0xDCE6FA], 0x2F4A7C, 0x9DB6E0, 'DEMO-ASSET-001', 'NOTEBOOK 14 INCH'],
    ['printer',      'drawPrinter',    [0xF1F5F9, 0xE2E8F0], 0x44566B, 0x94A8BD, 'DEMO-ASSET-002', 'LASER PRINTER'],
    ['projector',    'drawProjector',  [0xF4F0FB, 0xE7E0F6], 0x4C3F77, 0xA193C9, 'DEMO-ASSET-003', 'MULTIMEDIA PROJECTOR'],
    ['aircon',       'drawAirCon',     [0xEAF6FB, 0xD6EBF5], 0x2E6E8E, 0x9BC9DD, 'DEMO-ASSET-004', 'SPLIT TYPE AIR 13000 BTU'],
    ['fridge',       'drawFridge',     [0xEDF7F2, 0xD9EDE4], 0x2F6B55, 0x9CCBB8, 'DEMO-ASSET-005', 'REFRIGERATOR 6.4 Q'],
    ['cabinet',      'drawCabinet',    [0xF5F3EE, 0xE8E4DA], 0x5C5346, 0xB8AE9C, 'DEMO-ASSET-006', 'STEEL CABINET 4 TIER'],
    ['motorcycle',   'drawMotorcycle', [0xFBF0EF, 0xF4DEDC, ], 0x7A3B36, 0xD19C96, 'DEMO-ASSET-007', 'MOTORCYCLE 110 CC'],
    ['notebook-2',   'drawNotebook',   [0xEFF3F7, 0xDEE5EC], 0x37506B, 0xA3B6C9, 'DEMO-ASSET-008', 'NOTEBOOK 14 INCH'],
    ['copier',       'drawCopier',     [0xF2F4F7, 0xE3E7EC], 0x3D4A5C, 0x95A2B3, 'DEMO-ASSET-009', 'COPIER 20 PPM'],
    ['desk',         'drawDesk',       [0xF8F4EE, 0xEDE4D7], 0x6B5438, 0xC2AC8C, 'DEMO-ASSET-010', 'OFFICE DESK LEVEL 3-6'],
    ['scanner',      'drawScanner',    [0xEFF6F4, 0xDDEBE8], 0x2F6158, 0x9AC4BB, 'DEMO-ASSET-011', 'DOCUMENT SCANNER'],
    ['notebook-3',   'drawNotebook',   [0xF3F1FA, 0xE4E0F3], 0x463E72, 0xA69FC8, 'DEMO-ASSET-012', 'NOTEBOOK 14 INCH'],
    ['aircon-2',     'drawAirCon',     [0xEFF6FA, 0xDEEBF3], 0x386079, 0xA4C4D5, 'DEMO-ASSET-013', 'SPLIT TYPE AIR 12000 BTU'],

    // สี่ตัวล่างไม่ได้ผูกกับครุภัณฑ์ที่มีอยู่ ใช้แนบตอนกรอกสดบนเวที
    ['new-notebook', 'drawNotebook',   [0xEEF4FF, 0xDBE6F9], 0x27406E, 0x93AEDC, 'NEW ASSET', 'NOTEBOOK 14 INCH I5'],
    ['new-printer',  'drawPrinter',    [0xF1F5F9, 0xE1E7ED], 0x3C4E62, 0x8EA2B8, 'NEW ASSET', 'LASER PRINTER 40 PPM'],
    ['new-aircon',   'drawAirCon',     [0xEAF6FB, 0xD4EAF4], 0x27657F, 0x93C3D8, 'NEW ASSET', 'SPLIT TYPE AIR 18000 BTU'],
    ['new-monitor',  'drawScanner',    [0xF2F2F6, 0xE3E3EA], 0x3A3F52, 0x9699AC, 'NEW ASSET', 'LED MONITOR 24 INCH'],
    ['new-projector','drawProjector',  [0xF5F0FA, 0xE8DFF4], 0x463570, 0x9E8EC6, 'NEW ASSET', 'MULTIMEDIA PROJECTOR'],
    ['new-cabinet',  'drawCabinet',    [0xF6F3ED, 0xEAE4D8], 0x554C3F, 0xB3A795, 'NEW ASSET', 'STEEL CABINET 2 DOOR'],
];

$outDir = __DIR__ . '/../docs/demo-asset-photos';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$total = 0;
foreach ($items as [$slug, $fn, $bg, $body, $accent, $code, $label]) {
    $c = new Canvas($bg[0], $bg[1]);

    // เงาใต้ครุภัณฑ์ ให้ภาพไม่ลอย
    // แอร์เป็นชนิดติดผนัง ไม่ได้วางพื้น จึงไม่มีเงา
    if (!str_starts_with($slug, 'aircon') && $slug !== 'new-aircon') {
        $c->ellipse(320, 368, 190, 20, Canvas::mix($bg[1], 0x000000, 0.10));
    }

    $fn($c, $body, $accent);

    // แถบป้ายกำกับด้านล่าง
    $c->rect(0, 404, W, 76, Canvas::mix($bg[1], 0x000000, 0.06));
    $c->rect(0, 404, W, 3, $body);
    text($c, $code, 28, 420, 2.4, $body);
    text($c, $label, 28, 450, 1.6, Canvas::mix($body, 0xFFFFFF, 0.35));

    $png = encodePng($c->flatten(), W, H);
    $path = $outDir . '/' . $slug . '.png';
    file_put_contents($path, $png);
    $total++;
    printf("%-14s %6.1f KB  %s\n", $slug, strlen($png) / 1024, $code);
}

echo "\nสร้างแล้ว {$total} ไฟล์ ที่ " . realpath($outDir) . "\n";
