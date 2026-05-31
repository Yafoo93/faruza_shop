<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_id')->constrained('users');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('total', 10, 2);
            $table->decimal('amount_paid', 10, 2);
            $table->decimal('change_due', 10, 2)->default(0);
            $table->string('payment_method')->default('cash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
