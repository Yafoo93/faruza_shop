<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity_added',
        'old_stock',
        'new_stock',
        'restocked_by',
    ];

    // Cast data types if needed
    protected $casts = [
        'quantity_added' => 'integer',
        'old_stock' => 'integer',
        'new_stock' => 'integer',
    ];

    // Define relationship to Product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Define relationship to User (who restocked)
    public function user()
    {
        return $this->belongsTo(User::class, 'restocked_by');
    }
}