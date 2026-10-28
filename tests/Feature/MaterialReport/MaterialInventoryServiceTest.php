<?php

/*
 * MATERIAL_INVENTORY bookkeeping used by MAT-002 (receipts) and MAT-006 (balance setting).
 * Oracle is faked: selects answer from an in-memory MATERIAL_INVENTORY, writes are recorded.
 */

use App\Models\MaterialWithdrawn;
use App\Models\SysUser;
use App\Services\MaterialInventoryService;
use Illuminate\Support\Facades\DB;
use Yajra\Oci8\Oci8Connection;

afterEach(fn () => DB::purge('oracle'));

/**
 * @param array<int, array{id:int, org_id:int, mat_id:int, inv_amt:float, inv_price:float}> $inventory
 * @param array<int, int> $parents   org_id => parent org_id
 * @param array<int, float> $reserved mat_id => approved quantity not yet received
 */
function inventoryDb(array $inventory = [], ?array $receiptPrice = null, array $parents = [], array $reserved = []): Oci8Connection
{
    $connection = new class(
        fn () => throw new RuntimeException('Oracle must not be contacted in tests'),
        '',
        '',
        ['username' => 'GUJAJOB'],
    ) extends Oci8Connection {
        public array $rows = [];

        public array $writes = [];

        public ?array $receiptPrice = null;

        public array $parents = [];

        public array $reserved = [];

        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
        {
            $bindings = array_values($bindings);
            if (str_contains($query, 'MAX(id)')) {
                return [(object) ['next_id' => max([0, ...array_column($this->rows, 'id')]) + 1]];
            }
            if (str_contains($query, 'FROM MATERIAL_WITHDRAWN w')) {
                return array_map(fn ($mat, $qty) => (object) ['mat_id' => $mat, 'qty' => $qty], array_keys($this->reserved), $this->reserved);
            }
            if (str_contains($query, 'from "GLB_ORGANIZATION"')) {
                return isset($this->parents[(int) $bindings[0]]) ? [(object) ['org_org_id' => $this->parents[(int) $bindings[0]]]] : [];
            }
            if (str_contains($query, 'SUM(inv_amt)')) {
                $totals = [];
                foreach ($this->rows as $row) {
                    if ($row['org_id'] === (int) $bindings[0] && in_array($row['mat_id'], array_map('intval', array_slice($bindings, 1)), true)) {
                        $totals[$row['mat_id']] = ($totals[$row['mat_id']] ?? 0) + $row['inv_amt'];
                    }
                }

                return array_map(fn ($mat, $amount) => (object) ['mat_id' => $mat, 'amount' => $amount], array_keys($totals), $totals);
            }
            if (str_contains($query, 'FROM MATERIAL_PROCUREMENT_LIST')) {
                return [(object) ($this->receiptPrice ?? ['org_price' => null, 'all_price' => null])];
            }
            if (str_contains($query, 'from "MATERIAL_INVENTORY"')) {
                return array_values(array_map(fn (array $row) => (object) $row, array_filter(
                    $this->rows,
                    fn (array $row) => $row['org_id'] === (int) $bindings[0] && $row['mat_id'] === (int) $bindings[1],
                )));
            }

            return [];
        }

        public function insert($query, $bindings = [])
        {
            $this->writes[] = ['insert', $query, array_values($bindings)];

            return true;
        }

        public function update($query, $bindings = [])
        {
            $this->writes[] = ['update', $query, array_values($bindings)];

            return 1;
        }

        public function transaction(Closure $callback, $attempts = 1)
        {
            return $callback($this);
        }
    };
    $connection->rows = $inventory;
    $connection->receiptPrice = $receiptPrice;
    $connection->parents = $parents;
    $connection->reserved = $reserved;

    DB::purge('oracle');
    DB::extend('oracle', fn () => $connection);

    return $connection;
}

function line(int $id, int $mat, float $amount, float $price): object
{
    return (object) ['id' => $id, 'mat_id' => $mat, 'mat_amt' => $amount, 'mat_price' => $price];
}

/** [type, org, mat, inv_amt, inv_price] per write, read from the recorded bindings. */
function inventoryWrites(Oci8Connection $db): array
{
    return array_map(function (array $write) {
        [$type, $sql, $bindings] = $write;
        if ($type === 'insert') {
            // columns: id, org_id, mat_id, inv_amt, inv_price, created_by, updated_by, created_at, updated_at
            return ['insert', $bindings[1], $bindings[2], $bindings[3], $bindings[4]];
        }
        // update ... set inv_amt, [inv_price,] updated_by, updated_at where id
        return str_contains($sql, '"INV_PRICE"')
            ? ['update', 'id ' . end($bindings), null, $bindings[0], $bindings[1]]
            : ['update', 'id ' . end($bindings), null, $bindings[0], null];
    }, $db->writes);
}

it('adds a new receipt to the receiving organization', function () {
    $db = inventoryDb();

    app(MaterialInventoryService::class)->applyReceiptLines(null, [], 65, [line(1, 7, 10, 20)], 1);

    expect(inventoryWrites($db))->toBe([['insert', 65, 7, 10.0, 20.0]]);
});

it('averages the price when more stock is received', function () {
    $db = inventoryDb([['id' => 3, 'org_id' => 65, 'mat_id' => 7, 'inv_amt' => 10.0, 'inv_price' => 20.0]]);

    app(MaterialInventoryService::class)->applyReceiptLines(null, [], 65, [line(1, 7, 30, 40)], 1);

    // (10 x 20 + 30 x 40) / 40 = 35
    expect(inventoryWrites($db))->toBe([['update', 'id 3', null, 40.0, 35.0]]);
});

it('adjusts by the difference when a receipt quantity is edited, and removes deleted lines', function () {
    $db = inventoryDb([['id' => 3, 'org_id' => 65, 'mat_id' => 7, 'inv_amt' => 10.0, 'inv_price' => 20.0]]);
    $service = app(MaterialInventoryService::class);

    $service->applyReceiptLines(65, [line(1, 7, 10, 20)], 65, [line(1, 7, 4, 20)], 1);
    $service->applyReceiptLines(65, [line(1, 7, 10, 20)], 65, [], 1);

    expect(inventoryWrites($db))->toBe([
        ['update', 'id 3', null, 4.0, 20.0],
        ['update', 'id 3', null, 0.0, 20.0],
    ]);
});

it('moves the stock when a receipt is changed to another organization', function () {
    $db = inventoryDb([['id' => 3, 'org_id' => 65, 'mat_id' => 7, 'inv_amt' => 10.0, 'inv_price' => 20.0]]);

    app(MaterialInventoryService::class)->applyReceiptLines(65, [line(1, 7, 10, 20)], 66, [line(1, 7, 10, 20)], 1);

    expect(inventoryWrites($db))->toBe([
        ['update', 'id 3', null, 0.0, 20.0],
        ['insert', 66, 7, 10.0, 20.0],
    ]);
});

it('sets a balance for one organization only, keeping or starting its price', function () {
    $db = inventoryDb(
        [['id' => 3, 'org_id' => 65, 'mat_id' => 7, 'inv_amt' => 10.0, 'inv_price' => 20.0]],
        ['org_price' => null, 'all_price' => 12.5],
    );
    $service = app(MaterialInventoryService::class);

    $service->setBalance(65, 7, 25, 1);
    $service->setBalance(66, 7, 8, 1);

    expect(inventoryWrites($db))->toBe([
        ['update', 'id 3', null, 25.0, null],
        ['insert', 66, 7, 8.0, 12.5],
    ]);
});

it('refuses to adjust MAT-006 balances without an organization', function () {
    $this->actingAs((new SysUser())->forceFill(['id' => 900, 'org_id' => 1512, 'user_name' => 'mat tester']));
    $db = inventoryDb();

    $this->postJson('/material/MAT-006-record-balance-setting/bulk-update', [
        'fiscal_year' => App\Services\FiscalYearService::current(),
        'items' => [['material_id' => 7, 'balance' => 5]],
    ])->assertStatus(422)->assertJsonPath('error', 'กรุณาเลือกหน่วยงานก่อนปรับปรุงยอดคงเหลือ');

    expect($db->writes)->toBe([]);
});

it('counts approved withdrawals that are not received yet as reserved', function () {
    inventoryDb([['id' => 3, 'org_id' => 1310, 'mat_id' => 7, 'inv_amt' => 10.0, 'inv_price' => 20.0]], reserved: [7 => 4.0]);

    expect(app(MaterialInventoryService::class)->available(1310, [7, 8]))->toBe([7 => 6.0, 8 => 0.0]);
});

it('moves a received withdrawal from the source organization to the requesting child', function () {
    $db = inventoryDb([['id' => 3, 'org_id' => 1310, 'mat_id' => 7, 'inv_amt' => 10.0, 'inv_price' => 20.0]], parents: [382 => 1310]);
    $withdrawal = (new MaterialWithdrawn())->forceFill(['id' => 1, 'org_id' => 382, 'withdraw_type' => 'LARGE_LOT']);

    app(MaterialInventoryService::class)->applyReceivedWithdrawal($withdrawal, [(object) ['mat_id' => 7, 'isp_amount' => 2]], 1);

    expect(inventoryWrites($db))->toBe([
        ['update', 'id 3', null, 8.0, 20.0],
        ['insert', 382, 7, 2.0, 20.0],
    ]);
});

it('takes a received internal-use withdrawal out of the organization itself', function () {
    $db = inventoryDb([['id' => 5, 'org_id' => 382, 'mat_id' => 7, 'inv_amt' => 5.0, 'inv_price' => 30.0]], parents: [382 => 1310]);
    $withdrawal = (new MaterialWithdrawn())->forceFill(['id' => 2, 'org_id' => 382, 'withdraw_type' => 'INTERNAL_USE']);

    app(MaterialInventoryService::class)->applyReceivedWithdrawal($withdrawal, [(object) ['mat_id' => 7, 'isp_amount' => 2]], 1);

    expect(inventoryWrites($db))->toBe([['update', 'id 5', null, 3.0, 30.0]]);
});
