<?php

namespace App\Services;

use App\Models\MaterialWithdrawn;
use Illuminate\Support\Facades\DB;

/**
 * MAT-003 "แนะนำจากประวัติการเบิก": materials an organization usually withdraws together with the ones
 * already on the new withdrawal (association rules from its own withdrawal history).
 *
 * A withdrawal counts once it has been submitted (pending or approved); drafts and rejected ones do not,
 * nor lines approved with a quantity of 0. For a material A on the withdrawal and another material B:
 *
 *   confidence(A → B) = withdrawals with both A and B ÷ withdrawals with A
 *
 * B is recommended when the confidence is above 50% and A and B were withdrawn together at least twice
 * (one shared withdrawal alone would always give 100%). Both withdrawal types count: the history is the
 * organization's own, whichever store it drew on.
 */
class MaterialWithdrawRecommendationService
{
    public const MIN_CONFIDENCE = 0.5;

    public const MIN_TOGETHER = 2;

    public const LIMIT = 5;

    /**
     * @param array<int, int> $matIds materials already on the withdrawal
     * @return array<int, array{mat_id: int, code: string, name: string, unit: string, base_code: string, base_name: string, together: int, base_count: int, confidence: float}>
     *               strongest first; each recommended material once, with the selected material that recommends it most
     */
    public function recommend(int $orgId, array $matIds): array
    {
        $matIds = array_values(array_unique(array_filter(array_map('intval', $matIds), fn (int $id) => $id > 0)));
        if ($matIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($matIds), '?'));
        $counted = [MaterialWithdrawn::STATUS_PENDING, MaterialWithdrawn::STATUS_APPROVED];

        $baseCounts = collect(DB::connection('oracle')->select("
            SELECT l.mat_id, COUNT(DISTINCT l.mat_wd_id) AS cnt
            FROM MATERIAL_WITHDRAWN_LIST l
            JOIN MATERIAL_WITHDRAWN w ON w.id = l.mat_wd_id
            WHERE w.org_id = ? AND w.status IN (?, ?) AND l.wd_amount > 0
              AND l.mat_id IN ({$in})
            GROUP BY l.mat_id", [$orgId, ...$counted, ...$matIds]))
            ->mapWithKeys(fn ($row) => [(int) $row->mat_id => (int) $row->cnt]);

        if ($baseCounts->isEmpty()) {
            return [];
        }

        $pairs = DB::connection('oracle')->select("
            SELECT a.mat_id AS base_id, b.mat_id AS rec_id, COUNT(DISTINCT a.mat_wd_id) AS together
            FROM MATERIAL_WITHDRAWN_LIST a
            JOIN MATERIAL_WITHDRAWN w ON w.id = a.mat_wd_id
            JOIN MATERIAL_WITHDRAWN_LIST b ON b.mat_wd_id = a.mat_wd_id AND b.mat_id <> a.mat_id AND b.wd_amount > 0
            WHERE w.org_id = ? AND w.status IN (?, ?) AND a.wd_amount > 0
              AND a.mat_id IN ({$in})
              AND b.mat_id NOT IN ({$in})
            GROUP BY a.mat_id, b.mat_id", [$orgId, ...$counted, ...$matIds, ...$matIds]);

        $best = [];
        foreach ($pairs as $pair) {
            $baseId = (int) $pair->base_id;
            $recId = (int) $pair->rec_id;
            $together = (int) $pair->together;
            $baseCount = $baseCounts[$baseId] ?? 0;
            if ($baseCount === 0 || $together < self::MIN_TOGETHER) {
                continue;
            }

            $confidence = $together / $baseCount;
            if ($confidence <= self::MIN_CONFIDENCE) {
                continue;
            }

            $current = $best[$recId] ?? null;
            if ($current === null || [$confidence, $together] > [$current['confidence'], $current['together']]) {
                $best[$recId] = ['mat_id' => $recId, 'base_id' => $baseId, 'together' => $together, 'base_count' => $baseCount, 'confidence' => $confidence];
            }
        }

        if ($best === []) {
            return [];
        }

        usort($best, fn (array $x, array $y) => [$y['confidence'], $y['together']] <=> [$x['confidence'], $x['together']]);
        $best = array_slice($best, 0, self::LIMIT);

        $materials = DB::connection('oracle')->table('MATERIALS')
            ->whereIn('id', array_merge(array_column($best, 'mat_id'), array_column($best, 'base_id')))
            ->get(['id', 'mat_code', 'mat_name', 'unit'])
            ->keyBy(fn ($m) => (int) $m->id);

        return array_values(array_filter(array_map(function (array $rec) use ($materials) {
            $material = $materials[$rec['mat_id']] ?? null;
            $base = $materials[$rec['base_id']] ?? null;
            if ($material === null || $base === null) {
                return null;
            }

            return [
                'mat_id'     => $rec['mat_id'],
                'code'       => (string) $material->mat_code,
                'name'       => (string) $material->mat_name,
                'unit'       => (string) ($material->unit ?? ''),
                'base_code'  => (string) $base->mat_code,
                'base_name'  => (string) $base->mat_name,
                'together'   => $rec['together'],
                'base_count' => $rec['base_count'],
                'confidence' => round($rec['confidence'], 4),
            ];
        }, $best)));
    }
}
