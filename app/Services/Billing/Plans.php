<?php

namespace App\Services\Billing;

/** Price calculation for self-service plans (all amounts returned in Rial). */
class Plans
{
    public const PERIODS = ['monthly' => 1, 'yearly' => 12];

    /** Plans that can be bought online (have a positive price). */
    public static function purchasable(): array
    {
        return array_filter(config('watchrex.plans'), fn ($p) => ($p['price'] ?? null) > 0);
    }

    /** @return array{subtotal:int, tax:int, amount:int, months:int} */
    public static function quote(string $plan, string $period): array
    {
        $price = config("watchrex.plans.{$plan}.price");
        if (! $price || ! isset(self::PERIODS[$period])) {
            throw new \InvalidArgumentException('Plan is not purchasable');
        }

        $billedMonths = $period === 'yearly' ? (int) config('watchrex.billing.yearly_months', 10) : 1;
        $subtotal = (int) $price * $billedMonths * 10; // Toman → Rial
        $tax = (int) round($subtotal * (float) config('watchrex.billing.vat_percent') / 100);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'amount' => $subtotal + $tax, 'months' => self::PERIODS[$period]];
    }

    public static function rank(string $plan): int
    {
        return (int) array_search($plan, array_keys(config('watchrex.plans')), true);
    }
}
