<?php

namespace App\Services;

use App\Models\MaterialWithdrawn;
use Illuminate\Support\Facades\DB;

/**
 * Keeps MATERIAL_INVENTORY (stock per organization and material) in step with the screens that change it.
 * Call inside the caller's Oracle transaction: the row is locked while it is adjusted.
 *
 *   MAT-002 receipts add to the receiving organization           — applyReceiptLines().
 *   MAT-003 withdrawals move nothing until the transfer is received; approval only checks available().
 *   MAT-004 a received transfer moves the stock                    — applyReceivedWithdrawal().
 *   MAT-006 sets an organization's balance directly                — setBalance().
 *
 * INV_PRICE is the weighted average price of what came in; taking stock out keeps the price.
 */
class MaterialInventoryService
{
    /**
     * The organization whose store a withdrawal draws on: a child withdrawing from its source (LARGE_LOT)
     * takes from its parent organization; internal use (INTERNAL_USE) takes from the organization itself.
     */
    public function sourceOrgId(?int $requesterOrgId, ?string $withdrawType): ?int
    {
        if ($requesterOrgId === null) {
            return null;
        }
        if ($withdrawType === 'INTERNAL_USE') {
            return $requesterOrgId;
        }

        $parent = DB::connection('oracle')->table('GLB_ORGANIZATION')->where('org_id', $requesterOrgId)->value('org_org_id');

        return $parent !== null ? (int) $parent : null;
    }

    /** Stock on hand per material (MATERIAL_INVENTORY), 0 where the organization has none. @return array<int, float> */
    public function onHand(int $orgId, array $matIds): array
    {
        $matIds = array_values(array_unique(array_map('intval', $matIds)));
        if ($matIds === []) {
            return [];
        }

        $rows = DB::connection('oracle')->table('MATERIAL_INVENTORY')
            ->where('org_id', $orgId)
            ->whereIn('mat_id', $matIds)
            ->groupBy('mat_id')
            ->selectRaw('mat_id, SUM(inv_amt) AS amount')
            ->get();

        return array_replace(array_fill_keys($matIds, 0.0), $rows->mapWithKeys(fn ($row) => [(int) $row->mat_id => (float) $row->amount])->all());
    }

    /**
     * Stock the organization can still hand out: on hand minus approved withdrawals from its store whose
     * transfer has not been received yet (those are only deducted on receipt). Pass the withdrawal being
     * approved as $exceptWithdrawalId so it does not reserve against itself.
     *
     * @return array<int, float> mat_id => available
     */
    public function available(int $orgId, array $matIds, ?int $exceptWithdrawalId = null): array
    {
        $available = $this->onHand($orgId, $matIds);
        if ($available === []) {
            return [];
        }

        $inClause = implode(',', array_fill(0, count($available), '?'));
        $reserved = DB::connection('oracle')->select("
            SELECT l.mat_id, SUM(l.wd_amount) AS qty
            FROM MATERIAL_WITHDRAWN w
            JOIN GLB_ORGANIZATION o ON o.org_id = w.org_id
            JOIN MATERIAL_WITHDRAWN_LIST l ON l.mat_wd_id = w.id
            WHERE w.status = ?
              AND ((w.withdraw_type = 'LARGE_LOT' AND o.org_org_id = ?) OR (w.withdraw_type = 'INTERNAL_USE' AND w.org_id = ?))
              AND NOT EXISTS (SELECT 1 FROM MATERIAL_INSPECTION i JOIN MATERIAL_INSPECTION_LIST il ON il.mat_isp_id = i.id WHERE i.mat_wd_id = w.id)
              AND w.id <> ?
              AND l.mat_id IN ({$inClause})
            GROUP BY l.mat_id", [MaterialWithdrawn::STATUS_APPROVED, $orgId, $orgId, $exceptWithdrawalId ?? -1, ...array_keys($available)]);

        foreach ($reserved as $row) {
            $available[(int) $row->mat_id] -= (float) $row->qty;
        }

        // A store that handed out more than it received (negative stock) has nothing left to give, not less than nothing.
        return array_map(fn (float $amount) => max(0.0, $amount), $available);
    }

    /**
     * MAT-004: once a withdrawal's transfer is received, LARGE_LOT stock moves from the parent organization to
     * the requesting child (at the parent's price); INTERNAL_USE stock leaves the requesting organization.
     *
     * @param iterable<object> $receivedLines MATERIAL_INSPECTION_LIST rows (mat_id, isp_amount)
     */
    public function applyReceivedWithdrawal(MaterialWithdrawn $withdrawal, iterable $receivedLines, int $userId): void
    {
        $requester = (int) $withdrawal->org_id;
        $type = $withdrawal->withdraw_type;
        $source = $this->sourceOrgId($requester, $type);

        foreach ($receivedLines as $line) {
            $mat = (int) $line->mat_id;
            $qty = (float) $line->isp_amount;
            if ($qty <= 0) {
                continue;
            }

            if ($type === 'INTERNAL_USE') {
                $this->adjust($requester, $mat, -$qty, null, $userId);
            } elseif ($type === 'LARGE_LOT' && $source !== null) {
                $sourceRow = $this->lockedRow($source, $mat);
                $price = $sourceRow !== null && (float) $sourceRow->inv_price > 0 ? (float) $sourceRow->inv_price : $this->receiptPrice($source, $mat);
                $this->adjust($source, $mat, -$qty, null, $userId);
                $this->adjust($requester, $mat, $qty, $price, $userId);
            }
        }
    }

    /**
     * Apply a change to one MAT-002 receipt's lines. $before and $after are the receipt's lines
     * (id, mat_id, mat_amt, mat_price) before and after the change, received by $orgBefore / $orgAfter;
     * pass an empty list for a new or deleted receipt. Moving a receipt to another organization moves its stock.
     *
     * @param iterable<object> $before
     * @param iterable<object> $after
     */
    public function applyReceiptLines(?int $orgBefore, iterable $before, ?int $orgAfter, iterable $after, int $userId): void
    {
        $old = collect($before)->keyBy(fn ($line) => (int) $line->id);
        $new = collect($after)->keyBy(fn ($line) => (int) $line->id);

        foreach ($old->keys()->merge($new->keys())->unique() as $lineId) {
            $was = $old->get($lineId);
            $now = $new->get($lineId);

            if ($was !== null && $now !== null && $orgBefore === $orgAfter && (int) $was->mat_id === (int) $now->mat_id) {
                $delta = (float) $now->mat_amt - (float) $was->mat_amt;
                if ($delta != 0.0) {
                    $this->adjust($orgAfter, (int) $now->mat_id, $delta, (float) $now->mat_price, $userId);
                }
                continue;
            }
            if ($was !== null && $orgBefore !== null) {
                $this->adjust($orgBefore, (int) $was->mat_id, -(float) $was->mat_amt, null, $userId);
            }
            if ($now !== null && $orgAfter !== null) {
                $this->adjust($orgAfter, (int) $now->mat_id, (float) $now->mat_amt, (float) $now->mat_price, $userId);
            }
        }
    }

    /** Set an organization's balance of a material (MAT-006); the price is kept, or started from its receipts. */
    public function setBalance(int $orgId, int $matId, float $amount, int $userId): void
    {
        $row = $this->lockedRow($orgId, $matId);

        if ($row !== null) {
            DB::connection('oracle')->table('MATERIAL_INVENTORY')->where('id', $row->id)
                ->update(['inv_amt' => $amount, 'updated_by' => $userId, 'updated_at' => now()]);

            return;
        }

        $this->insert($orgId, $matId, $amount, $this->receiptPrice($orgId, $matId), $userId);
    }

    private function adjust(int $orgId, int $matId, float $delta, ?float $unitPrice, int $userId): void
    {
        $row = $this->lockedRow($orgId, $matId);

        if ($row === null) {
            $this->insert($orgId, $matId, $delta, $delta > 0 ? (float) $unitPrice : 0.0, $userId);

            return;
        }

        $amount = (float) $row->inv_amt;
        $price = (float) $row->inv_price;
        if ($delta > 0 && $unitPrice !== null) {
            $held = max($amount, 0.0);
            $price = round(($held * $price + $delta * $unitPrice) / ($held + $delta), 2);
        }

        DB::connection('oracle')->table('MATERIAL_INVENTORY')->where('id', $row->id)
            ->update(['inv_amt' => $amount + $delta, 'inv_price' => $price, 'updated_by' => $userId, 'updated_at' => now()]);
    }

    private function lockedRow(int $orgId, int $matId): ?object
    {
        return DB::connection('oracle')->table('MATERIAL_INVENTORY')
            ->where('org_id', $orgId)
            ->where('mat_id', $matId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id', 'inv_amt', 'inv_price']);
    }

    private function insert(int $orgId, int $matId, float $amount, float $price, int $userId): void
    {
        $db = DB::connection('oracle');
        // Same id scheme as the rest of the material module.
        $nextId = (int) $db->selectOne('SELECT NVL(MAX(id), 0) + 1 AS next_id FROM MATERIAL_INVENTORY')->next_id;

        $db->table('MATERIAL_INVENTORY')->insert([
            'id'         => $nextId,
            'org_id'     => $orgId,
            'mat_id'     => $matId,
            'inv_amt'    => $amount,
            'inv_price'  => round($price, 2),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Weighted average receipt price of the material at the organization, else across all organizations. */
    private function receiptPrice(int $orgId, int $matId): float
    {
        $row = DB::connection('oracle')->selectOne('
            SELECT SUM(CASE WHEN mp.org_id = ? THEN l.mat_amt * l.mat_price END) / NULLIF(SUM(CASE WHEN mp.org_id = ? THEN l.mat_amt END), 0) AS org_price,
                   SUM(l.mat_amt * l.mat_price) / NULLIF(SUM(l.mat_amt), 0) AS all_price
            FROM MATERIAL_PROCUREMENT_LIST l
            JOIN MATERIAL_PROCUREMENT mp ON mp.id = l.mat_pro_id
            WHERE l.mat_id = ?', [$orgId, $orgId, $matId]);

        return round((float) ($row->org_price ?? $row->all_price ?? 0), 2);
    }
}
