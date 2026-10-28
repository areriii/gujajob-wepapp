<?php

/*
 * material:rebuild-inventory — stock per organization from receipts and received withdrawals.
 * Oracle is replaced by recordingOracle() (tests/Pest.php); a dry run must only read.
 */

use Illuminate\Support\Facades\DB;

afterEach(fn () => DB::purge('oracle'));

/** Province office 1512 (parent) with child unit 1588. */
function inventoryOracle(array $receipts, array $withdrawals): void
{
    recordingOracle(function (string $sql) use ($receipts, $withdrawals) {
        return match (true) {
            str_contains($sql, 'FROM MATERIAL_WITHDRAWN w') => array_map(fn (array $row) => (object) $row, $withdrawals),
            str_contains($sql, 'FROM MATERIAL_PROCUREMENT_LIST l') => array_map(fn (array $row) => (object) $row, $receipts),
            str_contains($sql, 'FROM MATERIAL_INVENTORY') => [],
            str_contains($sql, 'FROM GLB_ORGANIZATION') => [(object) ['org_id' => 1512, 'org_name' => 'สกจ.สระบุรี'], (object) ['org_id' => 1588, 'org_name' => 'งานธุรการ']],
            str_contains($sql, 'FROM MATERIALS') => [(object) ['id' => 1, 'mat_code' => 'M-1'], (object) ['id' => 2, 'mat_code' => 'M-2'], (object) ['id' => 3, 'mat_code' => 'M-3']],
            default => [],
        };
    });
}

it('moves received transfers from the parent to the child and deducts internal use from the organization itself', function () {
    inventoryOracle(
        receipts: [
            ['org_id' => 1512, 'mat_id' => 1, 'mat_amt' => 10, 'mat_price' => 100],
            ['org_id' => 1588, 'mat_id' => 2, 'mat_amt' => 5, 'mat_price' => 20],
        ],
        withdrawals: [
            ['mat_wd_code' => 'MWD-1', 'org_id' => 1588, 'parent_org_id' => 1512, 'withdraw_type' => 'LARGE_LOT', 'mat_id' => 1, 'isp_amount' => 4],
            ['mat_wd_code' => 'MWD-2', 'org_id' => 1512, 'parent_org_id' => 0, 'withdraw_type' => 'INTERNAL_USE', 'mat_id' => 1, 'isp_amount' => 1],
        ],
    );

    $this->artisan('material:rebuild-inventory')
        ->expectsTable(
            ['Org', 'Organization', 'Material', 'Receipts', 'Transfer in', 'Transfer out', 'Internal use', 'Stored', 'Rebuilt', 'Price'],
            [
                [1512, 'สกจ.สระบุรี', 'M-1', 10, 0, 4, 1, '-', 5, 100],
                [1588, 'งานธุรการ', 'M-1', 0, 4, 0, 0, '-', 4, 100],
                [1588, 'งานธุรการ', 'M-2', 5, 0, 0, 0, '-', 5, 20],
            ],
        )
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
});

it('flags a parent that handed out stock it never received', function () {
    inventoryOracle(
        receipts: [],
        withdrawals: [['mat_wd_code' => 'MWD-3', 'org_id' => 1588, 'parent_org_id' => 1512, 'withdraw_type' => 'LARGE_LOT', 'mat_id' => 3, 'isp_amount' => 2]],
    );

    $this->artisan('material:rebuild-inventory')
        ->expectsOutputToContain('Negative stock: org 1512 สกจ.สระบุรี, M-3 = -2')
        ->assertSuccessful();
});
