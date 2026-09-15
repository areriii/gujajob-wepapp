<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Yajra\Oci8\Oci8Connection;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Replace the 'oracle' connection with one that compiles real Oracle SQL (yajra OracleGrammar),
 * records every select and answers with canned rows — it never opens a database session.
 *
 * @param  array|\Closure(string, array): array  $rows  rows for every select, or a resolver per query
 */
function recordingOracle(array|Closure $rows = []): Oci8Connection
{
    $connection = new class(
        fn () => throw new RuntimeException('Oracle must not be contacted in tests'),
        '',
        '',
        ['username' => 'GUJAJOB'],
    ) extends Oci8Connection {
        public array $recorded = [];

        public array|Closure $cannedRows = [];

        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
        {
            $bindings = array_values($bindings);
            $this->recorded[] = ['sql' => $query, 'bindings' => $bindings];

            return $this->cannedRows instanceof Closure ? ($this->cannedRows)($query, $bindings) : $this->cannedRows;
        }
    };
    $connection->cannedRows = $rows;

    DB::purge('oracle');
    DB::extend('oracle', fn () => $connection);

    return $connection;
}

function something()
{
    // ..
}
