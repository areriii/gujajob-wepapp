<?php

namespace App\Console\Commands;

use App\Services\AssetDepreciationCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecalculateAssetRemainPrice extends Command
{
    protected $signature = 'asset:recalculate-remain-price
                            {--apply : Write the recalculated values (without it nothing is changed)}
                            {--as-of= : Calculation date Y-m-d (default: today)}
                            {--id=* : Only these ASSET ids}';

    protected $description = 'Recalculate ASSET.REMAIN_PRICE with AssetDepreciationCalculator from INSPECT_DATE (dry run unless --apply)';

    public function handle(AssetDepreciationCalculator $depreciation): int
    {
        $asOf = $this->option('as-of') ?: now()->toDateString();

        $query = DB::connection('oracle')->table('ASSET')
            ->select(['id', 'ass_code', 'ass_price', 'ass_lifetime', 'remain_price', 'ass_status'])
            ->selectRaw("TO_CHAR(inspect_date, 'YYYY-MM-DD') AS inspect_date_iso")
            ->whereNotNull('ass_price')
            ->orderBy('id');

        if ($ids = array_filter(array_map('intval', (array) $this->option('id')))) {
            $query->whereIn('id', $ids);
        }

        $changes = [];
        foreach ($query->get() as $row) {
            $value = $depreciation->valueAsOf($row->ass_price, $row->ass_lifetime, $row->inspect_date_iso, $asOf);
            if ($value === null) {
                continue;
            }

            $stored = $row->remain_price !== null ? round((float) $row->remain_price, 2) : null;
            if ($stored !== null && abs($stored - $value['remaining_value']) < 0.005) {
                continue;
            }

            $changes[] = [
                'id'           => (int) $row->id,
                'code'         => $row->ass_code,
                'price'        => $value['original_value'],
                'inspect_date' => $row->inspect_date_iso,
                'life'         => $value['useful_life_years'],
                'start'        => $value['depreciation_start_date'],
                'end_of_life'  => $value['useful_life_end_date'],
                'accumulated'  => $value['accumulated_depreciation'],
                'stored'       => $stored,
                'calculated'   => $value['remaining_value'],
                'status'       => $row->ass_status,
            ];
        }

        $this->line("Depreciation as of {$asOf}: " . count($changes) . ' asset(s) with a different remain_price.');

        if ($changes === []) {
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Code', 'Price', 'Inspect date', 'Life', 'Depreciation start', 'End of life', 'Accumulated', 'Stored remain_price', 'Calculated', 'Status'],
            array_map(fn (array $c) => array_values($c), $changes),
        );

        if (!$this->option('apply')) {
            $this->warn('Dry run — nothing was written. Re-run with --apply to update ASSET.REMAIN_PRICE.');
            return self::SUCCESS;
        }

        try {
            DB::connection('oracle')->transaction(function () use ($changes): void {
                foreach ($changes as $change) {
                    DB::connection('oracle')->table('ASSET')
                        ->where('id', $change['id'])
                        ->update(['remain_price' => $change['calculated'], 'updated_at' => DB::raw('SYSTIMESTAMP')]);
                }
            });
        } catch (Throwable $e) {
            $this->error('Update failed and was rolled back: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Updated remain_price for ' . count($changes) . ' asset(s). ASS_STATUS was not changed.');
        return self::SUCCESS;
    }
}
