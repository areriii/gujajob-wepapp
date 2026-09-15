<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel client for the FastAPI replacement budget forecast service.
 *
 * Every outcome is normalised to ['status' => HTTP status for the browser, 'body' => JSON body],
 * so AI-service internals (Python exceptions, validation details) never reach the user.
 */
class ReplacementBudgetForecastService
{
    /** AI error codes whose message is written for end users and can be shown as-is. */
    private const USER_FACING_CODES = ['INSUFFICIENT_TRAINING_DATA', 'INSUFFICIENT_YEAR_DIVERSITY'];

    private const MESSAGES = [
        'INSUFFICIENT_TRAINING_DATA'  => 'ข้อมูลราคาครุภัณฑ์ย้อนหลังไม่เพียงพอสำหรับการพยากรณ์',
        'INSUFFICIENT_YEAR_DIVERSITY' => 'ข้อมูลราคาครุภัณฑ์ย้อนหลังต้องมีมากกว่า 1 ปี จึงจะพยากรณ์แนวโน้มราคาได้',
        'AI_SERVICE_UNAVAILABLE'      => 'ไม่สามารถเชื่อมต่อบริการพยากรณ์ (AI Service) ได้ กรุณาลองใหม่ภายหลัง',
        'AI_SERVICE_TIMEOUT'          => 'บริการพยากรณ์ใช้เวลาตอบกลับนานเกินกำหนด กรุณาลองใหม่อีกครั้ง',
        'AI_SERVICE_ERROR'            => 'บริการพยากรณ์ขัดข้อง ไม่สามารถคำนวณผลได้ กรุณาติดต่อผู้ดูแลระบบ',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int    $timeout,
    ) {}

    /**
     * Call the FastAPI forecast endpoint.
     *
     * @param  array{forecast_years: int, base_year: int, training_records: list<array>, candidate_assets: list<array>}  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function forecast(array $payload): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post("{$this->baseUrl}/forecast/replacement-budget", $payload);
        } catch (ConnectionException $e) {
            Log::error('AI forecast service unreachable', ['url' => $this->baseUrl, 'error' => $e->getMessage()]);

            return $this->isTimeout($e)
                ? $this->failure('AI_SERVICE_TIMEOUT', 504)
                : $this->failure('AI_SERVICE_UNAVAILABLE', 503);
        } catch (Throwable $e) {
            Log::error('AI forecast request failed', ['url' => $this->baseUrl, 'error' => $e->getMessage()]);
            return $this->failure('AI_SERVICE_UNAVAILABLE', 503);
        }

        $body = $response->json();

        if ($response->successful() && $this->isValidResult($body)) {
            return ['status' => 200, 'body' => $body];
        }

        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : null;

        if ($response->status() === 422 && in_array($code, self::USER_FACING_CODES, true)) {
            $message = is_string($body['message'] ?? null) && $body['message'] !== ''
                ? $body['message']
                : self::MESSAGES[$code];

            return ['status' => 422, 'body' => ['success' => false, 'code' => $code, 'message' => $message]];
        }

        Log::error('AI forecast service returned an unusable response', [
            'status' => $response->status(),
            'code'   => $code,
        ]);

        return $this->failure('AI_SERVICE_ERROR', 502);
    }

    /**
     * Zero-budget result in the same shape the AI service returns, one row per forecast year.
     *
     * @return array<string, mixed>
     */
    public function emptyResult(int $forecastYears, int $baseYear): array
    {
        $period = $this->forecastPeriod($baseYear, $forecastYears);

        return [
            'success'               => true,
            'forecast_years'        => $forecastYears,
            'base_year'             => $baseYear,
            'start_year'            => $period[0],
            'end_year'              => $period[count($period) - 1],
            'total_assets'          => 0,
            'total_forecast_budget' => 0.0,
            'years'                 => array_map(
                static fn (int $year) => ['year' => $year, 'asset_count' => 0, 'forecast_budget' => 0.0],
                $period,
            ),
            'assets'                => [],
            'warnings'              => [],
            'model'                 => null,
        ];
    }

    /**
     * Forecast years: base year + 1 .. base year + forecast years.
     *
     * @return list<int>
     */
    public function forecastPeriod(int $baseYear, int $forecastYears): array
    {
        return range($baseYear + 1, $baseYear + max(1, $forecastYears));
    }

    /**
     * Quick health check — returns true when the service responds OK.
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");
            return $response->ok();
        } catch (Throwable) {
            return false;
        }
    }

    private function isValidResult(mixed $body): bool
    {
        return is_array($body)
            && ($body['success'] ?? null) === true
            && is_array($body['years'] ?? null)
            && is_array($body['assets'] ?? null)
            && is_int($body['total_assets'] ?? null)
            && is_numeric($body['total_forecast_budget'] ?? null);
    }

    private function isTimeout(ConnectionException $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 28')
            || stripos($e->getMessage(), 'timed out') !== false;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function failure(string $code, int $status): array
    {
        return [
            'status' => $status,
            'body'   => ['success' => false, 'code' => $code, 'message' => self::MESSAGES[$code]],
        ];
    }
}
