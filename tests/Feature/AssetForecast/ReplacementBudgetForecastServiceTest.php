<?php

use App\Services\ReplacementBudgetForecastService;
use Illuminate\Support\Facades\Http;

function forecastClient(): ReplacementBudgetForecastService
{
    return new ReplacementBudgetForecastService(baseUrl: 'http://ai.test', timeout: 5);
}

function forecastPayload(): array
{
    return [
        'forecast_years'   => 1,
        'base_year'        => 2026,
        'training_records' => [],
        'candidate_assets' => [],
    ];
}

function validAiBody(): array
{
    return [
        'success'               => true,
        'forecast_years'        => 1,
        'base_year'             => 2026,
        'start_year'            => 2027,
        'end_year'              => 2027,
        'total_assets'          => 1,
        'total_forecast_budget' => 30123.45,
        'years'                 => [['year' => 2027, 'asset_count' => 1, 'forecast_budget' => 30123.45]],
        'assets'                => [['asset_code' => 'FX-C001', 'forecast_year' => 2027, 'predicted_replacement_cost' => 30123.45]],
        'warnings'              => [],
        'model'                 => ['name' => 'Ridge Regression', 'training_records' => 40],
    ];
}

it('passes a valid AI result through unchanged', function () {
    Http::fake(['ai.test/forecast/replacement-budget' => Http::response(validAiBody())]);

    $outcome = forecastClient()->forecast(forecastPayload());

    expect($outcome['status'])->toBe(200)
        ->and($outcome['body'])->toBe(validAiBody());

    Http::assertSent(fn ($request) => $request->url() === 'http://ai.test/forecast/replacement-budget'
        && $request['base_year'] === 2026
        && $request['forecast_years'] === 1);
});

it('reports the AI service as unavailable when the connection fails', function () {
    Http::fake(['ai.test/*' => Http::failedConnection('cURL error 7: Failed to connect to 127.0.0.1 port 8001')]);

    $outcome = forecastClient()->forecast(forecastPayload());

    expect($outcome['status'])->toBe(503)
        ->and($outcome['body']['success'])->toBeFalse()
        ->and($outcome['body']['code'])->toBe('AI_SERVICE_UNAVAILABLE')
        ->and($outcome['body']['message'])->not->toContain('cURL');
});

it('reports a timeout separately', function () {
    Http::fake(['ai.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds')]);

    $outcome = forecastClient()->forecast(forecastPayload());

    expect($outcome['status'])->toBe(504)
        ->and($outcome['body']['code'])->toBe('AI_SERVICE_TIMEOUT');
});

it('shows the user-facing message for insufficient training data', function (string $code) {
    Http::fake(['ai.test/*' => Http::response([
        'success' => false,
        'code'    => $code,
        'message' => 'ข้อมูลย้อนหลังไม่เพียงพอ (พบ 3 รายการ)',
    ], 422)]);

    $outcome = forecastClient()->forecast(forecastPayload());

    expect($outcome['status'])->toBe(422)
        ->and($outcome['body'])->toBe([
            'success' => false,
            'code'    => $code,
            'message' => 'ข้อมูลย้อนหลังไม่เพียงพอ (พบ 3 รายการ)',
        ]);
})->with(['INSUFFICIENT_TRAINING_DATA', 'INSUFFICIENT_YEAR_DIVERSITY']);

it('hides AI internals behind a generic error', function (int $status, mixed $body) {
    Http::fake(['ai.test/*' => Http::response($body, $status)]);

    $outcome = forecastClient()->forecast(forecastPayload());

    expect($outcome['status'])->toBe(502)
        ->and($outcome['body']['code'])->toBe('AI_SERVICE_ERROR')
        ->and(json_encode($outcome['body'], JSON_UNESCAPED_UNICODE))
        ->not->toContain('Traceback')
        ->not->toContain('loc');
})->with([
    'python 500 html'        => [500, '<html>Traceback (most recent call last)</html>'],
    'model error'            => [500, ['success' => false, 'code' => 'MODEL_ERROR', 'message' => 'Traceback: ValueError']],
    'pydantic detail'        => [422, ['detail' => [['loc' => ['body', 'base_year'], 'msg' => 'Field required']]]],
    'success without years'  => [200, ['success' => true, 'total_assets' => 1]],
    'non json 200'           => [200, 'OK'],
]);

it('builds an empty result with one zero row per forecast year', function (int $years) {
    $result = forecastClient()->emptyResult($years, 2031);

    expect($result['success'])->toBeTrue()
        ->and($result['start_year'])->toBe(2032)
        ->and($result['end_year'])->toBe(2031 + $years)
        ->and($result['total_assets'])->toBe(0)
        ->and($result['total_forecast_budget'])->toBe(0.0)
        ->and(array_column($result['years'], 'year'))->toBe(range(2032, 2031 + $years))
        ->and(array_sum(array_column($result['years'], 'forecast_budget')))->toBe(0.0);
})->with([1, 2, 3]);
