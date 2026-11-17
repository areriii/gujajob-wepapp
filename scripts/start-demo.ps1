<#
    start-demo.ps1 — เปิดระบบเดโมให้ครบ แล้วเฝ้าไว้ให้ไม่ล่ม

    ใช้ยังไง
        เปิดครั้งเดียวแล้วจบ:
            powershell -ExecutionPolicy Bypass -File scripts\start-demo.ps1

        เปิดแล้วเฝ้าต่อ ถ้าตัวไหนตายให้เปิดใหม่เอง (แนะนำตอนเดโม):
            powershell -ExecutionPolicy Bypass -File scripts\start-demo.ps1 -Watch

    สคริปต์นี้ทำอะไร
        1. เช็ก Oracle (ถ้าหยุดอยู่จะพยายามเปิด ต้อง Run as Administrator)
        2. เปิด AI service   พอร์ต 8001  (ถ้าไม่เปิด คอลัมน์ AI ใน MAT-006 จะเงียบ ๆ ตกไปใช้ค่าเฉลี่ย)
        3. เปิด Laravel      พอร์ต 8000
        4. เปิด Cloudflare Tunnel แล้วอ่าน URL ที่ได้
        5. เขียน APP_URL ใน .env ให้ตรงกับ URL ใหม่ แล้วล้าง config cache
        6. เก็บ URL ปัจจุบันไว้ที่ storage\app\demo-runtime\current-url.txt

    ที่สคริปต์นี้ทำแทนไม่ได้
        ถ้าเครื่องดับหรือ sleep สคริปต์ก็ตายไปด้วย ต้องมากดเปิดใหม่เอง
        และ URL ของ trycloudflare จะเปลี่ยนทุกครั้งที่เปิด tunnel ใหม่เสมอ
#>

param(
    [switch]$Watch,
    [int]$IntervalSeconds = 30
)

$ErrorActionPreference = 'Continue'

$Root       = Split-Path $PSScriptRoot -Parent
$RuntimeDir = Join-Path $Root 'storage\app\demo-runtime'
$UrlFile    = Join-Path $RuntimeDir 'current-url.txt'
$TunnelLog  = Join-Path $RuntimeDir 'cloudflared.log'
$AiLog      = Join-Path $RuntimeDir 'ai-service.log'
$WebLog     = Join-Path $RuntimeDir 'laravel.log'
$Cloudflared = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$Python      = Join-Path $Root 'ai-service\.venv\Scripts\python.exe'

if (-not (Test-Path $RuntimeDir)) { New-Item -ItemType Directory -Force -Path $RuntimeDir | Out-Null }

function Write-Step { param([string]$Text, [string]$Color = 'Gray') Write-Host $Text -ForegroundColor $Color }

# เช็กว่าเว็บตอบจริงไหม ไม่ได้ดูแค่ว่าพอร์ตเปิด เพราะ process อาจค้างอยู่แต่ตอบไม่ได้
function Test-Endpoint {
    param([string]$Url)
    try {
        $r = Invoke-WebRequest -Uri $Url -TimeoutSec 3 -UseBasicParsing -ErrorAction Stop
        return ($r.StatusCode -ge 200 -and $r.StatusCode -lt 500)
    } catch {
        return $false
    }
}

function Ensure-Oracle {
    $names = @('OracleServiceORCL', 'OracleOraDB19Home1TNSListener')
    foreach ($n in $names) {
        $svc = Get-Service $n -ErrorAction SilentlyContinue
        if ($null -eq $svc) { Write-Step "  ! ไม่พบ service $n" 'Yellow'; continue }
        if ($svc.Status -ne 'Running') {
            Write-Step "  - $n หยุดอยู่ กำลังเปิด..." 'Yellow'
            try { Start-Service $n -ErrorAction Stop; Write-Step "  + $n เปิดแล้ว" 'Green' }
            catch { Write-Step "  ! เปิด $n ไม่ได้ ต้อง Run as Administrator" 'Red' }
        }
    }
}

function Ensure-AiService {
    if (Test-Endpoint 'http://127.0.0.1:8001/health') { return $false }
    if (-not (Test-Path $Python)) { Write-Step "  ! ไม่พบ $Python" 'Red'; return $false }

    Write-Step '  - AI service ไม่ตอบ กำลังเปิด...' 'Yellow'
    Start-Process -FilePath $Python `
        -ArgumentList '-m','uvicorn','app.main:app','--host','127.0.0.1','--port','8001' `
        -WorkingDirectory (Join-Path $Root 'ai-service') `
        -WindowStyle Hidden -RedirectStandardError $AiLog | Out-Null
    return $true
}

function Ensure-Laravel {
    if (Test-Endpoint 'http://127.0.0.1:8000/login') { return $false }

    Write-Step '  - Laravel ไม่ตอบ กำลังเปิด...' 'Yellow'
    Start-Process -FilePath 'php' `
        -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8000' `
        -WorkingDirectory $Root `
        -WindowStyle Hidden -RedirectStandardOutput $WebLog | Out-Null
    return $true
}

function Get-TunnelUrl {
    if (Test-Path $TunnelLog) {
        $m = Select-String -Path $TunnelLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches -ErrorAction SilentlyContinue
        if ($null -ne $m) { return $m.Matches[0].Value }
    }

    # tunnel ที่เปิดด้วยวิธีอื่น (เช่นสั่งเองในเทอร์มินัล) จะไม่มี log ของสคริปต์นี้
    # ถ้า process ยังอยู่ ให้ถือว่า APP_URL ใน .env คือ URL ที่ใช้งานอยู่
    if (Get-Process cloudflared -ErrorAction SilentlyContinue) {
        $envPath = Join-Path $Root '.env'
        if (Test-Path $envPath) {
            $line = Get-Content $envPath -Encoding UTF8 | Where-Object { $_ -match '^APP_URL=.*trycloudflare\.com' }
            if ($line) { return ($line -replace '^APP_URL=', '').Trim() }
        }
    }

    return $null
}

function Ensure-Tunnel {
    # ถ้า process ยังอยู่ ถือว่า tunnel ยังทำงาน (ยิงเข้า URL เองจะนับเป็น traffic ทุกรอบ)
    $running = Get-Process cloudflared -ErrorAction SilentlyContinue
    if ($running) { return $false }

    if (-not (Test-Path $Cloudflared)) { Write-Step "  ! ไม่พบ $Cloudflared" 'Red'; return $false }

    Write-Step '  - Tunnel ไม่ทำงาน กำลังเปิดใหม่...' 'Yellow'
    Remove-Item $TunnelLog -ErrorAction SilentlyContinue
    Start-Process -FilePath $Cloudflared `
        -ArgumentList 'tunnel','--url','http://127.0.0.1:8000' `
        -WindowStyle Hidden -RedirectStandardError $TunnelLog | Out-Null

    # cloudflared ใช้เวลาสักพักกว่าจะพิมพ์ URL ออกมา
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Milliseconds 700
        $u = Get-TunnelUrl
        if ($u) { return $u }
    }
    Write-Step '  ! เปิด tunnel แล้วแต่ยังไม่เห็น URL ดูที่ cloudflared.log' 'Red'
    return $false
}

function Update-AppUrl {
    param([string]$Url)
    if ([string]::IsNullOrWhiteSpace($Url)) { return }

    $envPath = Join-Path $Root '.env'
    $lines   = Get-Content $envPath -Encoding UTF8
    $current = ($lines | Where-Object { $_ -match '^APP_URL=' }) -replace '^APP_URL=', ''
    if ($current -eq $Url) { return }

    ($lines -replace '^APP_URL=.*', "APP_URL=$Url") | Set-Content $envPath -Encoding UTF8
    Push-Location $Root
    php artisan config:clear | Out-Null
    Pop-Location
    Write-Step "  + ตั้ง APP_URL เป็น $Url แล้ว" 'Green'
}

function Invoke-Cycle {
    Ensure-Oracle
    $startedAi  = Ensure-AiService
    $startedWeb = Ensure-Laravel

    if ($startedAi -or $startedWeb) {
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Milliseconds 700
            if ((Test-Endpoint 'http://127.0.0.1:8001/health') -and (Test-Endpoint 'http://127.0.0.1:8000/login')) { break }
        }
    }

    $newUrl = Ensure-Tunnel
    if ($newUrl -is [string]) {
        Update-AppUrl -Url $newUrl
        Set-Content -Path $UrlFile -Value $newUrl -Encoding UTF8
    }

    $url = Get-TunnelUrl
    if ($url) { Set-Content -Path $UrlFile -Value $url -Encoding UTF8 }

    $ai  = Test-Endpoint 'http://127.0.0.1:8001/health'
    $web = Test-Endpoint 'http://127.0.0.1:8000/login'
    $tun = [bool](Get-Process cloudflared -ErrorAction SilentlyContinue)

    $stamp = Get-Date -Format 'HH:mm:ss'
    $okAi  = if ($ai)  { 'OK  ' } else { 'ตาย ' }
    $okWeb = if ($web) { 'OK  ' } else { 'ตาย ' }
    $okTun = if ($tun) { 'OK  ' } else { 'ตาย ' }
    Write-Host "[$stamp] AI $okAi| Web $okWeb| Tunnel $okTun| $url"
}

Write-Host ''
Write-Step '=== เปิดระบบเดโม GUJAJOB ===' 'Cyan'
Invoke-Cycle

$finalUrl = Get-TunnelUrl
if ($finalUrl) {
    Write-Host ''
    Write-Step "URL สำหรับเดโม: $finalUrl" 'Green'
    Write-Step "เก็บไว้ที่: $UrlFile" 'DarkGray'
}

if ($Watch) {
    Write-Host ''
    Write-Step "กำลังเฝ้าอยู่ (เช็กทุก $IntervalSeconds วินาที) — กด Ctrl+C เพื่อหยุด" 'Cyan'
    Write-Step 'อย่าปิดหน้าต่างนี้' 'Yellow'
    while ($true) {
        Start-Sleep -Seconds $IntervalSeconds
        Invoke-Cycle
    }
}
