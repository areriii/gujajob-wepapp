<?php

/**
 * แนบรูปภาพให้ครุภัณฑ์ชุดเดโม (DEMO-ASSET-001 ถึง 013) ของ ASS-003
 *
 * ทำอะไร
 *   1) คัดลอกไฟล์จาก docs/demo-asset-photos ไปไว้ที่ storage/app/public/asset-images/<ass_id>/
 *      ซึ่งเป็นที่เดียวกับที่ฟอร์มอัปโหลดเก็บไฟล์
 *   2) เพิ่มแถวใน ASSET_IMAGE ให้ชี้ไปที่ไฟล์นั้น โดยใช้ ASSET_IMAGE_SEQ เหมือนที่ AssetController ใช้
 *
 * ต้องมี public/storage ก่อน ไม่งั้นรูปจะไม่ขึ้นบนหน้าเว็บ
 *   php artisan storage:link
 *
 * รันซ้ำได้ ครุภัณฑ์ตัวไหนมีรูปอยู่แล้วจะข้าม ไม่เพิ่มซ้ำ
 *
 * วิธีรัน
 *   php scripts/seed-demo-asset-photos.php
 *   php scripts/seed-demo-asset-photos.php --force   ลบรูปเดิมของชุดเดโมแล้วแนบใหม่
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$force  = in_array('--force', $argv, true);
$srcDir = __DIR__ . '/../docs/demo-asset-photos';

/** ass_code => ไฟล์รูปที่จะแนบ (แนบได้สูงสุด 3 รูปต่อครุภัณฑ์ เท่าที่ฟอร์มรองรับ) */
$plan = [
    'DEMO-ASSET-001' => ['notebook.png', 'new-notebook.png'],   // ตัวนี้ให้ 2 รูป ไว้โชว์ว่าแสดงหลายรูปได้
    'DEMO-ASSET-002' => ['printer.png'],
    'DEMO-ASSET-003' => ['projector.png'],
    'DEMO-ASSET-004' => ['aircon.png'],
    'DEMO-ASSET-005' => ['fridge.png'],
    'DEMO-ASSET-006' => ['cabinet.png'],
    'DEMO-ASSET-007' => ['motorcycle.png'],
    'DEMO-ASSET-008' => ['notebook-2.png'],
    'DEMO-ASSET-009' => ['copier.png'],
    'DEMO-ASSET-010' => ['desk.png'],
    'DEMO-ASSET-011' => ['scanner.png'],
    'DEMO-ASSET-012' => ['notebook-3.png'],
    'DEMO-ASSET-013' => ['aircon-2.png'],
];

$publicRoot = __DIR__ . '/../storage/app/public';
if (!is_dir($publicRoot)) {
    mkdir($publicRoot, 0777, true);
}

// บน Windows ลิงก์ที่ artisan storage:link สร้างให้ is_link() กับ is_dir() มองไม่เห็น
// ต้องเช็คด้วย file_exists() เท่านั้น
$linked = file_exists(__DIR__ . '/../public/storage');
$added   = 0;
$skipped = 0;
$missing = [];

foreach ($plan as $code => $files) {
    $asset = DB::connection('oracle')->table('ASSET')->where('ass_code', $code)->first(['id']);
    if (!$asset) {
        $missing[] = $code;
        continue;
    }
    $assId = (int) $asset->id;

    $existing = DB::connection('oracle')->table('ASSET_IMAGE')->where('ass_id', $assId)->count();
    if ($existing > 0) {
        if (!$force) {
            echo "ข้าม  {$code} (id {$assId}) มีรูปอยู่แล้ว {$existing} รูป\n";
            $skipped++;
            continue;
        }
        DB::connection('oracle')->table('ASSET_IMAGE')->where('ass_id', $assId)->delete();
    }

    $dir = $publicRoot . '/asset-images/' . $assId;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ($files as $file) {
        $src = $srcDir . '/' . $file;
        if (!is_file($src)) {
            echo "!     ไม่พบไฟล์ {$src} — รัน php scripts/make-demo-asset-photos.php ก่อน\n";
            continue;
        }

        // ชื่อไฟล์ปลายทางตั้งแบบเดียวกับที่ Laravel ตั้งให้ตอนอัปโหลด คือสุ่ม 40 ตัว
        $name = bin2hex(random_bytes(20)) . '.png';
        copy($src, $dir . '/' . $name);

        $relative = 'asset-images/' . $assId . '/' . $name;
        $imgId = (int) DB::connection('oracle')
            ->selectOne('SELECT ASSET_IMAGE_SEQ.NEXTVAL AS nv FROM DUAL')->nv;

        DB::connection('oracle')->table('ASSET_IMAGE')->insert([
            'id'         => $imgId,
            'ass_id'     => $assId,
            'ass_image'  => $relative,
            'created_by' => 1,
            'created_at' => DB::raw('SYSTIMESTAMP'),
            'updated_by' => 1,
            'updated_at' => DB::raw('SYSTIMESTAMP'),
        ]);
        $added++;
        echo "+     {$code} (id {$assId})  {$relative}\n";
    }
}

echo "\n";
echo "แนบรูปใหม่ : {$added} รูป\n";
echo "ข้าม       : {$skipped} รายการ (ใช้ --force ถ้าต้องการแนบทับ)\n";
if ($missing) {
    echo "ไม่พบรหัส  : " . implode(', ', $missing) . "\n";
}
if (!$linked) {
    echo "\n!! ยังไม่มี public/storage — รูปจะไม่ขึ้นบนหน้าเว็บ สั่ง php artisan storage:link ก่อน\n";
}
