<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'product_name',
        'product_sku',
        'quantity',
        'refunded_quantity',
        'unit_price',
        'unit_cost',
        'line_total',
        'line_profit',
        'refunded_total',
        'refunded_profit',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'refunded_quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
        'line_profit' => 'decimal:2',
        'refunded_total' => 'decimal:2',
        'refunded_profit' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withDefault([
            'name' => $this->product_name,
            'sku' => $this->product_sku,
        ]);
    }
}
