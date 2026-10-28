<?php

/*
 * MAT-007 material balance report: filtered by organization only. A parent organization reports on
 * itself and its children; a child organization only on itself.
 * Oracle is replaced by recordingOracle() (tests/Pest.php); Vite assets are not loaded.
 */

use App\Models\SysUser;
use Illuminate\Support\Facades\DB;

// Province office 1512 with two child units.
const MAT_TREE = [1512 => [1512, 1588, 1590], 1588 => [1588], 1590 => [1590]];

beforeEach(fn () => $this->withoutVite());

afterEach(fn () => DB::purge('oracle'));

function signInAtOrganization(int $orgId): void
{
    test()->actingAs((new SysUser())->forceFill(['id' => 900 + $orgId, 'org_id' => $orgId, 'user_name' => 'mat tester']));
}

function materialOracle(): \Yajra\Oci8\Oci8Connection
{
    return recordingOracle(function (string $sql, array $bindings) {
        if (str_contains($sql, 'START WITH org_id = ?')) {
            return array_map(fn (int $id) => (object) ['org_id' => $id], MAT_TREE[$bindings[0]] ?? []);
        }
        if (str_contains($sql, 'FROM MATERIAL_INVENTORY inv')) {
            return [(object) ['id' => 1, 'mat_code' => 'M-0001', 'mat_name' => 'กระดาษ A4', 'unit' => 'รีม', 'balance' => 1234, 'unit_price' => 125]];
        }
        if (str_contains($sql, 'UPPER(org_name) LIKE')) {
            return [(object) ['org_id' => 1588, 'org_name' => 'งานธุรการ']];
        }

        return [(object) ['org_name' => 'สำนักงานสหกรณ์จังหวัดสระบุรี']];
    });
}

/** Organization ids the MATERIAL_INVENTORY query was scoped to. */
function reportedOrgIds(\Yajra\Oci8\Oci8Connection $oracle): array
{
    return collect($oracle->recorded)->first(fn (array $q) => str_contains($q['sql'], 'FROM MATERIAL_INVENTORY inv'))['bindings'];
}

it('asks only for the organization, without a sub-organization field', function () {
    signInAtOrganization(1512);
    materialOracle();

    $this->get('/material/MAT-007-print-material-report')
        ->assertOk()
        ->assertSee('id="mainDepartment"', false)
        ->assertDontSee('หน่วยงานย่อย')
        ->assertDontSee('id="subDepartment"', false);

    $this->get('/material/MAT-007-print-material-report/sub-organizations/search?q=x')->assertNotFound();
});

it('suggests only the signed-in organization and its children', function () {
    signInAtOrganization(1512);
    $oracle = materialOracle();

    $this->getJson('/material/MAT-007-print-material-report/organizations/search?q=งาน')
        ->assertOk()
        ->assertJsonPath('data.0.id', 1588);

    $search = collect($oracle->recorded)->first(fn (array $q) => str_contains($q['sql'], 'UPPER(org_name) LIKE'));
    expect(array_slice($search['bindings'], 0, 3))->toBe([1512, 1588, 1590]);
});

it('reports a parent organization together with its children', function () {
    signInAtOrganization(1512);
    $oracle = materialOracle();

    $this->get('/material/MAT-007-print-material-report/report-stock?org_id=1512')
        ->assertOk()
        ->assertSee('สำนักงานสหกรณ์จังหวัดสระบุรี')
        ->assertSee('M-0001')
        // Remaining quantity is a whole number; only the price keeps decimals.
        ->assertSee('<td class="number-cell">1,234</td>', false)
        ->assertSee('<td class="number-cell">125.00</td>', false);

    expect(reportedOrgIds($oracle))->toBe([1512, 1588, 1590]);
});

it('reports only the chosen child when a parent picks one of its children', function () {
    signInAtOrganization(1512);
    $oracle = materialOracle();

    $this->get('/material/MAT-007-print-material-report/report-stock?org_id=1588')->assertOk();

    expect(reportedOrgIds($oracle))->toBe([1588]);
});

it('lets a child organization report only on itself', function () {
    signInAtOrganization(1588);
    $oracle = materialOracle();

    $this->get('/material/MAT-007-print-material-report/report-stock?org_id=1588')->assertOk();
    expect(reportedOrgIds($oracle))->toBe([1588]);

    $this->get('/material/MAT-007-print-material-report/report-stock?org_id=1512')->assertForbidden();
    $this->get('/material/MAT-007-print-material-report/report-stock?org_id=1590')->assertForbidden();
    $this->get('/material/MAT-007-print-material-report/report-stock/export?org_id=1512')->assertForbidden();
});

it('requires an organization', function () {
    signInAtOrganization(1512);
    materialOracle();

    $this->get('/material/MAT-007-print-material-report/report-stock')->assertSessionHasErrors(['org_id' => 'กรุณากรอกข้อมูล']);
});
