<?php

namespace App\Services;

use App\Models\MaterialWithdrawn;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recommended stock ("จำนวนเฉลี่ยในการเบิกต่อเดือน", MAT-006) per organization and material, from withdrawal history.
 *
 * An organization's store is drawn on by its own staff (INTERNAL_USE) and by its child organizations
 * (LARGE_LOT, taken from the parent). As in MATERIAL_INVENTORY, a withdrawal counts once its transfer has
 * been received in MAT-004, with the received quantity, in the calendar month of the withdrawal date.
 *
 * History starts in the month of the first such withdrawal. The average divides by the months of history
 * there are, up to the last 12: with history from July, October averages July-October (4 months), and a
 * year later it settles on the last 12 months.
 *
 * The AI service (POST /forecast/material-stock) forecasts next month's withdrawal once there is enough
 * history to learn from; otherwise, or when the service is unreachable, the recommendation is that
 * average. Both are rounded up.
 */
class MaterialStockRecommendationService
{
    public const MONTHS = 12;

    public const HISTORY_MONTHS = 36;

    public const METHOD_AI = 'ridge_regression';

    public const METHOD_AVERAGE = 'moving_average_12m';

    private const NOTES = [
        self::METHOD_AI        => 'AI (Ridge Regression) พยากรณ์ยอดเบิกเดือนถัดไปจากประวัติการเบิก %d เดือน',
        'INSUFFICIENT_HISTORY' => 'ค่าเฉลี่ยยอดเบิกต่อเดือน %d เดือนล่าสุด (นับจากเดือนที่เริ่มมีประวัติการเบิก สูงสุด 12 เดือน) — ประวัติการเบิกยังน้อยเกินกว่าที่ AI จะเรียนรู้ได้',
        'MODEL_NOT_BETTER'     => 'ค่าเฉลี่ยยอดเบิกต่อเดือน %d เดือนล่าสุด — AI ยังพยากรณ์ได้ไม่ดีกว่าค่าเฉลี่ย',
        'AI_UNAVAILABLE'       => 'ค่าเฉลี่ยยอดเบิกต่อเดือน %d เดือนล่าสุด — ใช้งาน AI Service ไม่ได้',
    ];

    /**
     * @param int|null $orgId  null = every organization (their recommendations added up)
     * @param array<int, int> $matIds
     * @return array{values: array<int, int>, method: string, note: string}
     *               values: mat_id => recommended quantity; materials not withdrawn in the averaged months are absent
     */
    public function recommend(?int $orgId, array $matIds, ?CarbonInterface $asOf = null): array
    {
        $none = ['values' => [], 'method' => self::METHOD_AVERAGE, 'note' => 'ยังไม่มีประวัติการเบิกที่รับโอนแล้ว'];

        $matIds = array_values(array_unique(array_map('intval', $matIds)));
        if ($matIds === []) {
            return $none;
        }

        $today = ($asOf ?? now())->copy()->startOfDay();
        $historyMonths = min(self::HISTORY_MONTHS, $this->historyMonths($today));
        if ($historyMonths === 0) {
            return $none;
        }
        $averagedMonths = min(self::MONTHS, $historyMonths);
        $series = $this->monthlySeries($today, $historyMonths);

        // Store/material pairs shown on the page that were withdrawn in the averaged months.
        $targets = array_keys(array_filter($series, function (array $monthly, string $key) use ($orgId, $matIds, $averagedMonths): bool {
            [$org, $mat] = array_map('intval', explode(':', $key));

            return ($orgId === null || $org === $orgId) && in_array($mat, $matIds, true)
                && array_sum(array_slice($monthly, -$averagedMonths)) > 0;
        }, ARRAY_FILTER_USE_BOTH));

        if ($targets === []) {
            return ['values' => [], 'method' => self::METHOD_AVERAGE, 'note' => sprintf(self::NOTES['INSUFFICIENT_HISTORY'], $averagedMonths)];
        }

        $ai = $this->askAiService($series, $targets);
        $perKey = $ai['recommended'] ?? $this->averages($series, $targets, $averagedMonths);
        $method = $ai['method'] ?? self::METHOD_AVERAGE;
        $noteKey = $method === self::METHOD_AI ? self::METHOD_AI : ($ai === null ? 'AI_UNAVAILABLE' : ($ai['reason'] ?? 'INSUFFICIENT_HISTORY'));
        $note = sprintf(self::NOTES[$noteKey] ?? self::NOTES['INSUFFICIENT_HISTORY'], $method === self::METHOD_AI ? $historyMonths : $averagedMonths);

        $values = [];
        foreach ($perKey as $key => $recommended) {
            $mat = (int) explode(':', $key)[1];
            $values[$mat] = ($values[$mat] ?? 0) + $recommended;
        }

        return ['values' => $values, 'method' => $method, 'note' => $note];
    }

    /** Calendar months from the month of the first counted withdrawal up to and including this month; 0 = none yet. */
    private function historyMonths(CarbonInterface $today): int
    {
        $row = DB::connection('oracle')->selectOne("
            SELECT MONTHS_BETWEEN(TRUNC(TO_DATE(?, 'YYYY-MM-DD'), 'MM'), TRUNC(MIN(w.mat_wd_date), 'MM')) + 1 AS months
            FROM MATERIAL_WITHDRAWN w
            JOIN MATERIAL_INSPECTION i ON i.mat_wd_id = w.id
            JOIN MATERIAL_INSPECTION_LIST il ON il.mat_isp_id = i.id
            WHERE w.status = ?
              AND w.withdraw_type IN ('LARGE_LOT', 'INTERNAL_USE')
              AND il.isp_amount > 0
              AND w.mat_wd_date < TO_DATE(?, 'YYYY-MM-DD') + 1", [
            $today->toDateString(),
            MaterialWithdrawn::STATUS_APPROVED,
            $today->toDateString(),
        ]);

        return max(0, (int) ($row->months ?? 0));
    }

    /**
     * Received withdrawal quantities per store ("org:mat") and calendar month, oldest first, covering the
     * months of history (at most 36); the last month is the current one. Every store/material pair is sent
     * to the AI service for training.
     *
     * @return array<string, list<float>>
     */
    private function monthlySeries(CarbonInterface $today, int $months): array
    {
        $rows = DB::connection('oracle')->select("
            SELECT src_org_id, mat_id, bucket, SUM(qty) AS qty
            FROM (
                SELECT CASE w.withdraw_type WHEN 'LARGE_LOT' THEN o.org_org_id ELSE w.org_id END AS src_org_id,
                       il.mat_id, il.isp_amount AS qty,
                       MONTHS_BETWEEN(TRUNC(TO_DATE(?, 'YYYY-MM-DD'), 'MM'), TRUNC(w.mat_wd_date, 'MM')) AS bucket
                FROM MATERIAL_WITHDRAWN w
                JOIN GLB_ORGANIZATION o ON o.org_id = w.org_id
                JOIN MATERIAL_INSPECTION i ON i.mat_wd_id = w.id
                JOIN MATERIAL_INSPECTION_LIST il ON il.mat_isp_id = i.id
                WHERE w.status = ?
                  AND w.withdraw_type IN ('LARGE_LOT', 'INTERNAL_USE')
                  AND il.isp_amount > 0
                  AND w.mat_wd_date >= ADD_MONTHS(TRUNC(TO_DATE(?, 'YYYY-MM-DD'), 'MM'), ?)
                  AND w.mat_wd_date < TO_DATE(?, 'YYYY-MM-DD') + 1
            ) t
            WHERE src_org_id IS NOT NULL
            GROUP BY src_org_id, mat_id, bucket", [
            $today->toDateString(),
            MaterialWithdrawn::STATUS_APPROVED,
            $today->toDateString(),
            -($months - 1),
            $today->toDateString(),
        ]);

        $series = [];
        foreach ($rows as $row) {
            $bucket = (int) $row->bucket; // 0 = this month, 1 = last month, ...
            if ($bucket < 0 || $bucket >= $months) {
                continue;
            }
            $key = (int) $row->src_org_id . ':' . (int) $row->mat_id;
            $series[$key] ??= array_fill(0, $months, 0.0);
            $series[$key][$months - 1 - $bucket] += (float) $row->qty;
        }

        return $series;
    }

    /** @return array<string, int> key => total of the averaged months / their number, rounded up */
    private function averages(array $series, array $targets, int $averagedMonths): array
    {
        $result = [];
        foreach ($targets as $key) {
            $result[$key] = (int) ceil(round(array_sum(array_slice($series[$key], -$averagedMonths)) / $averagedMonths, 6));
        }

        return $result;
    }

    /** @return array{recommended?: array<string, int>, method?: string, reason?: string}|null null when the service cannot be used */
    private function askAiService(array $series, array $targets): ?array
    {
        $baseUrl = rtrim((string) config('services.ai_forecast.url'), '/');
        if ($baseUrl === '') {
            return null;
        }

        try {
            // A page load waits for this, so allow a few seconds at most.
            $response = Http::timeout(min(5, (int) config('services.ai_forecast.timeout', 5)))
                ->acceptJson()
                ->post("{$baseUrl}/forecast/material-stock", [
                    'series'  => array_map(fn (string $key) => ['key' => $key, 'monthly' => $series[$key]], array_keys($series)),
                    'targets' => array_values($targets),
                ]);
        } catch (Throwable $e) {
            Log::warning('Material stock AI unreachable, using the monthly average', ['error' => $e->getMessage()]);

            return null;
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? false) !== true || ! is_array($body['recommendations'] ?? null)) {
            Log::warning('Material stock AI returned an unusable response, using the monthly average', ['status' => $response->status()]);

            return null;
        }

        $recommended = [];
        foreach ($body['recommendations'] as $row) {
            if (is_array($row) && in_array($row['key'] ?? null, $targets, true) && is_numeric($row['recommended'] ?? null)) {
                $recommended[$row['key']] = max(0, (int) $row['recommended']);
            }
        }

        return [
            'recommended' => $recommended,
            'method'      => $body['method'] === self::METHOD_AI ? self::METHOD_AI : self::METHOD_AVERAGE,
            'reason'      => is_string($body['reason'] ?? null) ? $body['reason'] : null,
        ];
    }
}
