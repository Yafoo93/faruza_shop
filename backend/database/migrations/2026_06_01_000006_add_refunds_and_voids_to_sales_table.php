<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('status')->default('completed')->after('payment_method');
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('status');
            $table->decimal('refunded_profit', 10, 2)->default(0)->after('refunded_amount');
            $table->timestamp('refunded_at')->nullable()->after('refunded_profit');
            $table->foreignId('refunded_by')->nullable()->after('refunded_at')->constrained('users')->nullOnDelete();
            $table->text('refund_reason')->nullable()->after('refunded_by');
            $table->timestamp('voided_at')->nullable()->after('refund_reason');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->integer('refunded_quantity')->default(0)->after('quantity');
            $table->decimal('refunded_total', 10, 2)->default(0)->after('line_total');
            $table->decimal('refunded_profit', 10, 2)->default(0)->after('line_profit');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['refunded_quantity', 'refunded_total', 'refunded_profit']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['refunded_by']);
            $table->dropForeign(['voided_by']);
            $table->dropColumn([
                'status',
                'refunded_amount',
                'refunded_profit',
                'refunded_at',
                'refunded_by',
                'refund_reason',
                'voided_at',
                'voided_by',
                'void_reason',
            ]);
        });
    }
};
