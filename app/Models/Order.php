<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer', 'tax' => 'integer', 'amount' => 'integer',
            'period_starts_at' => 'datetime', 'period_ends_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Amounts are stored in Rial; the UI shows Toman. */
    public static function toman(int $rial): string
    {
        return number_format(intdiv($rial, 10));
    }
}
