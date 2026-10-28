<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rebuilds MATERIAL_INVENTORY (stock per organization and material) from the three stock movements:
 *
 *   + MAT-002 receipts (MATERIAL_PROCUREMENT_LIST) into the receiving organization — parent or child.
 *   ± MAT-003 withdrawals, counted only once the transfer has been received in MAT-004
 *     (MATERIAL_INSPECTION_LIST rows exist), using the received quantity:
 *       LARGE_LOT    (from the source organization): the child withdraws from its parent organization,
 *                    so the parent loses the quantity and the requesting child gains it;
 *       INTERNAL_USE (within the organization): staff withdraw from their own organization's stock,
 *                    so only the requesting organization loses the quantity.
 *
 * INV_PRICE is the weighted average price of what came in: receipts at their price, transfers at the
 * source organization's average receipt price (or the material's overall average when it has none).
 */
class RebuildMaterialInventory extends Command
{
    protected $signature = 'material:rebuild-inventory
                            {--apply : Write the rebuilt stock (without it nothing is changed)}
                            {--user=1 : SYS_USER id recorded in CREATED_BY / UPDATED_BY}';

    protected $description = 'Rebuild MATERIAL_INVENTORY from receipts, withdrawals and received transfers (dry run unless --apply)';

    public function handle(): int
    {
        $db = DB::connection('oracle');

        /** @var array<string, array{org:int, mat:int, receipts:float, transfer_in:float, transfer_out:float, internal_use:float, in_value:float, in_priced:float}> $stock */
        $stock = [];
        $slot = function (int $org, int $mat) use (&$stock): string {
            $key = "{$org}:{$mat}";
            $stock[$key] ??= ['org' => $org, 'mat' => $mat, 'receipts' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0, 'internal_use' => 0.0, 'in_value' => 0.0, 'in_priced' => 0.0];

            return $key;
        };

        $receipts = $db->select('
            SELECT mp.org_id, l.mat_id, l.mat_amt, l.mat_price
            FROM MATERIAL_PROCUREMENT_LIST l
            JOIN MATERIAL_PROCUREMENT mp ON mp.id = l.mat_pro_id
            WHERE l.mat_amt IS NOT NULL');

        $materialValue = [];
        foreach ($receipts as $row) {
            $key = $slot((int) $row->org_id, (int) $row->mat_id);
            $qty = (float) $row->mat_amt;
            $stock[$key]['receipts'] += $qty;
            if ($row->mat_price !== null) {
                $stock[$key]['in_value'] += $qty * (float) $row->mat_price;
                $stock[$key]['in_priced'] += $qty;
                $materialValue[(int) $row->mat_id][0] = ($materialValue[(int) $row->mat_id][0] ?? 0) + $qty * (float) $row->mat_price;
                $materialValue[(int) $row->mat_id][1] = ($materialValue[(int) $row->mat_id][1] ?? 0) + $qty;
            }
        }
        $receiptPrice = function (int $org, int $mat) use (&$stock, $materialValue): float {
            $own = $stock["{$org}:{$mat}"] ?? null;
            if ($own !== null && $own['in_priced'] > 0) {
                return $own['in_value'] / $own['in_priced'];
            }

            return isset($materialValue[$mat]) && $materialValue[$mat][1] > 0 ? $materialValue[$mat][0] / $materialValue[$mat][1] : 0.0;
        };

        $withdrawals = $db->select('
            SELECT w.mat_wd_code, w.org_id, o.org_org_id AS parent_org_id, w.withdraw_type, il.mat_id, il.isp_amount
            FROM MATERIAL_WITHDRAWN w
            JOIN GLB_ORGANIZATION o ON o.org_id = w.org_id
            JOIN MATERIAL_INSPECTION i ON i.mat_wd_id = w.id
            JOIN MATERIAL_INSPECTION_LIST il ON il.mat_isp_id = i.id
            WHERE w.status = 2 AND il.isp_amount IS NOT NULL
            ORDER BY w.id, il.id');

        $skipped = [];
        foreach ($withdrawals as $row) {
            $org = (int) $row->org_id;
            $mat = (int) $row->mat_id;
            $qty = (float) $row->isp_amount;

            if ($row->withdraw_type === 'INTERNAL_USE') {
                $stock[$slot($org, $mat)]['internal_use'] += $qty;
            } elseif ($row->withdraw_type === 'LARGE_LOT' && $row->parent_org_id !== null) {
                $parent = (int) $row->parent_org_id;
                $price = $receiptPrice($parent, $mat);
                $stock[$slot($parent, $mat)]['transfer_out'] += $qty;
                $in = $slot($org, $mat);
                $stock[$in]['transfer_in'] += $qty;
                $stock[$in]['in_value'] += $qty * $price;
                $stock[$in]['in_priced'] += $qty;
            } else {
                $skipped[] = "{$row->mat_wd_code} (withdraw_type=" . ($row->withdraw_type ?? 'NULL') . ", org {$org} has " . ($row->parent_org_id === null ? 'no parent' : 'parent') . ')';
            }
        }

        $stored = collect($db->select('SELECT id, org_id, mat_id, inv_amt, inv_price FROM MATERIAL_INVENTORY ORDER BY id'))
            ->groupBy(fn ($row) => "{$row->org_id}:{$row->mat_id}");
        foreach ($stored->keys() as $key) {
            [$org, $mat] = array_map('intval', explode(':', $key));
            $slot($org, $mat); // a stored row without any movement is rebuilt to 0
        }

        $names = collect($db->select('SELECT org_id, org_name FROM GLB_ORGANIZATION'))->pluck('org_name', 'org_id');
        $codes = collect($db->select('SELECT id, mat_code FROM MATERIALS'))->pluck('mat_code', 'id');

        $changes = [];
        $negative = [];
        foreach ($stock as $key => $s) {
            $amount = $s['receipts'] + $s['transfer_in'] - $s['transfer_out'] - $s['internal_use'];
            $price = $s['in_priced'] > 0 ? round($s['in_value'] / $s['in_priced'], 2) : 0.0;
            $current = $stored->get($key)?->first();
            if ($amount < 0) {
                $negative[] = $s + ['amount' => $amount];
            }
            if ($current !== null && abs((float) $current->inv_amt - $amount) < 0.0001 && abs((float) $current->inv_price - $price) < 0.005) {
                continue;
            }
            $changes[] = $s + ['amount' => $amount, 'price' => $price, 'id' => $current?->id, 'stored' => $current?->inv_amt];
        }
        usort($changes, fn (array $a, array $b) => [$a['org'], $a['mat']] <=> [$b['org'], $b['mat']]);

        $this->line(count($receipts) . ' receipt line(s), ' . count($withdrawals) . ' received withdrawal line(s); ' . count($changes) . ' MATERIAL_INVENTORY row(s) to insert or update.');
        foreach ($stored->filter(fn ($rows) => $rows->count() > 1) as $key => $rows) {
            $this->warn("Duplicate MATERIAL_INVENTORY rows for org:mat {$key} (ids " . $rows->pluck('id')->implode(', ') . '); only the first is updated.');
        }
        foreach ($skipped as $line) {
            $this->warn("Skipped withdrawal {$line}.");
        }

        if ($changes !== []) {
            $this->table(
                ['Org', 'Organization', 'Material', 'Receipts', 'Transfer in', 'Transfer out', 'Internal use', 'Stored', 'Rebuilt', 'Price'],
                array_map(fn (array $c) => [$c['org'], $names[$c['org']] ?? '?', $codes[$c['mat']] ?? $c['mat'], $c['receipts'], $c['transfer_in'], $c['transfer_out'], $c['internal_use'], $c['stored'] ?? '-', $c['amount'], $c['price']], $changes),
            );
        }
        foreach ($negative as $n) {
            $this->warn(sprintf('Negative stock: org %d %s, %s = %s (withdrawn by a child organization but never received here).', $n['org'], $names[$n['org']] ?? '?', $codes[$n['mat']] ?? $n['mat'], $n['amount']));
        }

        if ($changes === []) {
            return self::SUCCESS;
        }
        if (!$this->option('apply')) {
            $this->warn('Dry run — nothing was written. Re-run with --apply to update MATERIAL_INVENTORY.');
            return self::SUCCESS;
        }

        $user = (int) $this->option('user');
        try {
            $db->transaction(function () use ($db, $changes, $user): void {
                // Same id scheme as MAT-006, which also writes this table.
                $nextId = (int) $db->selectOne('SELECT NVL(MAX(id), 0) + 1 AS next_id FROM MATERIAL_INVENTORY')->next_id;
                foreach ($changes as $c) {
                    $values = ['inv_amt' => $c['amount'], 'inv_price' => $c['price'], 'updated_by' => $user, 'updated_at' => now()];
                    if ($c['id'] !== null) {
                        $db->table('MATERIAL_INVENTORY')->where('id', $c['id'])->update($values);
                    } else {
                        $db->table('MATERIAL_INVENTORY')->insert($values + ['id' => $nextId++, 'org_id' => $c['org'], 'mat_id' => $c['mat'], 'created_by' => $user, 'created_at' => now()]);
                    }
                }
            });
        } catch (Throwable $e) {
            $this->error('Update failed and was rolled back: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('MATERIAL_INVENTORY rebuilt: ' . count($changes) . ' row(s) written.');
        return self::SUCCESS;
    }
}
