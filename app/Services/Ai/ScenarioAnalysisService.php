<?php

namespace App\Services\Ai;

use App\Exceptions\PlatformException;
use App\Models\IntelligenceScenario;
use App\Models\User;
use App\Services\Banking\BankingReportingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ScenarioAnalysisService
{
    public function __construct(private readonly BankingReportingService $banking) {}

    /** @param array<string,mixed> $data */
    public function calculate(string $companyId, User $user, array $data, string $idempotencyKey): IntelligenceScenario
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): IntelligenceScenario {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = IntelligenceScenario::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for another scenario.');
                }

                return $existing;
            }
            $baseline = $this->baseline($companyId, $data['scenario_type']);
            [$scenario, $delta] = $this->apply($baseline, $data['scenario_type'], $data['assumptions']);

            return IntelligenceScenario::query()->create(['company_id' => $companyId, 'created_by' => $user->id, 'name' => $data['name'], 'scenario_type' => $data['scenario_type'], 'status' => 'COMPLETED', 'assumptions' => $data['assumptions'], 'baseline' => $baseline, 'scenario' => $scenario, 'delta' => $delta, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'calculated_at' => now()]);
        });
    }

    /** @return array<string,int|string|null> */
    private function baseline(string $companyId, string $type): array
    {
        try {
            $cash = $this->banking->forecast($companyId, 30);
        } catch (ValidationException) {
            throw new PlatformException('INSUFFICIENT_DATA', 'A single-currency cash position is required for this scenario.', 409);
        }

        return ['currency' => $cash['currency'] ?? null, 'current_cash_minor' => (int) $cash['current_cash'], 'expected_inflows_minor' => (int) $cash['expected_inflows'], 'expected_outflows_minor' => (int) $cash['expected_outflows'], 'projected_cash_minor' => (int) $cash['projected_cash'], 'scenario_type' => $type];
    }

    /** @param array<string,int|string|null> $baseline @param array<string,int> $assumptions
     * @return array{array<string,int|string|null>,array<string,int>}
     */
    private function apply(array $baseline, string $type, array $assumptions): array
    {
        $scenario = $baseline;
        $changeBps = (int) ($assumptions['change_bps'] ?? 0);
        $amountMinor = (int) ($assumptions['amount_minor'] ?? 0);
        if ($type === 'REVENUE_CHANGE') {
            $scenario['expected_inflows_minor'] = (int) $baseline['expected_inflows_minor'] + intdiv((int) $baseline['expected_inflows_minor'] * $changeBps, 10000);
        } elseif (in_array($type, ['EXPENSE_CHANGE', 'PAYROLL_CHANGE', 'INVENTORY_PURCHASE_CHANGE'], true)) {
            $scenario['expected_outflows_minor'] = (int) $baseline['expected_outflows_minor'] + intdiv((int) $baseline['expected_outflows_minor'] * $changeBps, 10000);
        } elseif ($type === 'CUSTOMER_NON_PAYMENT') {
            $scenario['expected_inflows_minor'] = max(0, (int) $baseline['expected_inflows_minor'] - $amountMinor);
        } elseif ($type === 'COLLECTION_DELAY') {
            $scenario['expected_inflows_minor'] = 0;
        }
        $scenario['projected_cash_minor'] = (int) $scenario['current_cash_minor'] + (int) $scenario['expected_inflows_minor'] - (int) $scenario['expected_outflows_minor'];
        $delta = [];
        foreach (['expected_inflows_minor', 'expected_outflows_minor', 'projected_cash_minor'] as $key) {
            $delta[$key] = (int) $scenario[$key] - (int) $baseline[$key];
        }

        return [$scenario, $delta];
    }
}
