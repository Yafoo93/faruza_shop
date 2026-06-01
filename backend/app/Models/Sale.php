<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $with = ['items'];

    protected $fillable = [
        'cashier_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'subtotal',
        'discount_amount',
        'total',
        'amount_paid',
        'change_due',
        'credit_amount',
        'payment_method',
        'payment_status',
        'status',
        'refunded_amount',
        'refunded_profit',
        'refunded_at',
        'refunded_by',
        'refund_reason',
        'voided_at',
        'voided_by',
        'void_reason',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_due' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'refunded_profit' => 'decimal:2',
        'refunded_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected $appends = [
        'item_count',
        'total_quantity',
        'net_total',
        'net_quantity',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function refundedBy()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function getItemCountAttribute()
    {
        if ($this->relationLoaded('items')) {
            return $this->items->count();
        }

        return $this->items()->count();
    }

    public function getTotalQuantityAttribute()
    {
        if ($this->relationLoaded('items')) {
            return (int) $this->items->sum('quantity');
        }

        return (int) $this->items()->sum('quantity');
    }

    public function getNetTotalAttribute()
    {
        if ($this->status === 'voided') {
            return 0;
        }

        return max((float) $this->total - (float) $this->refunded_amount, 0);
    }

    public function getNetQuantityAttribute()
    {
        if ($this->status === 'voided') {
            return 0;
        }

        if ($this->relationLoaded('items')) {
            return (int) $this->items->sum(fn ($item) => max((int) $item->quantity - (int) $item->refunded_quantity, 0));
        }

        return (int) $this->items()->get()->sum(fn ($item) => max((int) $item->quantity - (int) $item->refunded_quantity, 0));
    }
}
