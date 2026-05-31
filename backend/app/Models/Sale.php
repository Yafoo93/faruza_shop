<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_id',
        'subtotal',
        'total',
        'amount_paid',
        'change_due',
        'payment_method',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_due' => 'decimal:2',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }
}
