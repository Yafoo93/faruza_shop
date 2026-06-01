<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'category',
        'cost_price',
        'selling_price',
        'stock_qty',
        'min_stock_threshold',
        'expiry_date',
        'image',
        'archived_at',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'expiry_date' => 'date',
        'archived_at' => 'datetime',
    ];

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockHistories()
    {
        return $this->hasMany(StockHistory::class);
    }
}
