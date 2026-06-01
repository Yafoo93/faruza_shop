<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'cashier_id' => 'nullable|exists:users,id',
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'nullable|string|in:cash,mobile_money,card,credit',
            'payment_status' => 'nullable|string|in:paid,partial,unpaid',
            'status' => 'nullable|string|in:completed,partially_refunded,refunded,voided',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $query = Sale::query()
            ->with('cashier:id,name,email,role', 'customer')
            ->latest();

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->from)->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->to)->endOfDay());
        }

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', $request->cashier_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($saleQuery) use ($search) {
                $saleQuery
                    ->where('id', $search)
                    ->orWhereHas('items', function ($itemQuery) use ($search) {
                        $itemQuery
                            ->where('product_name', 'like', "%{$search}%")
                            ->orWhere('product_sku', 'like', "%{$search}%");
                    })
                    ->orWhereHas('cashier', function ($cashierQuery) use ($search) {
                        $cashierQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $summarySales = (clone $query)->with('items')->get();
        $summary = [
            'sales_count' => $summarySales->count(),
            'subtotal' => (float) $summarySales->where('status', '!=', 'voided')->sum('subtotal'),
            'discount_amount' => (float) $summarySales->where('status', '!=', 'voided')->sum('discount_amount'),
            'total' => (float) $summarySales->sum(fn ($sale) => $sale->net_total),
            'amount_paid' => (float) $summarySales->where('status', '!=', 'voided')->sum('amount_paid'),
            'refunded_amount' => (float) $summarySales->sum('refunded_amount'),
            'credit_amount' => (float) $summarySales->where('status', '!=', 'voided')->sum('credit_amount'),
        ];

        $sales = $query->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'sales' => $sales,
            'summary' => $summary,
        ]);
    }

    public function show(Sale $sale)
    {
        return response()->json([
            'sale' => $sale->load('cashier:id,name,email,role', 'customer', 'refundedBy:id,name,email,role', 'voidedBy:id,name,email,role', 'items.product'),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cashier_id' => 'nullable|exists:users,id',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email|max:255',
            'payment_method' => 'required|string|in:cash,mobile_money,card,credit',
            'amount_paid' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $sale = DB::transaction(function () use ($request) {
                $items = collect($request->items);
                $productIds = $items->pluck('product_id')->unique()->values();
                $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
                $subtotal = 0;

                foreach ($items as $item) {
                    $product = $products->get($item['product_id']);
                    $quantity = (int) $item['quantity'];

                    if (! $product || $product->stock_qty < $quantity) {
                        $productName = $product ? $product->name : 'selected product';

                        throw ValidationException::withMessages([
                            'items' => "Not enough stock for {$productName}.",
                        ]);
                    }

                    $subtotal += $quantity * (float) $product->selling_price;
                }

                $discountAmount = (float) $request->input('discount_amount', 0);

                if ($discountAmount > $subtotal) {
                    throw ValidationException::withMessages([
                        'discount_amount' => 'Discount cannot be more than the sale subtotal.',
                    ]);
                }

                $total = max($subtotal - $discountAmount, 0);
                $amountPaid = round((float) $request->amount_paid, 2);
                $creditAmount = max(round($total - $amountPaid, 2), 0);
                $customer = null;

                if ($request->payment_method !== 'credit' && $amountPaid < $total) {
                    throw ValidationException::withMessages([
                        'amount_paid' => 'Amount paid is less than the sale total.',
                    ]);
                }

                if ($request->payment_method === 'credit') {
                    if ($creditAmount <= 0) {
                        throw ValidationException::withMessages([
                            'amount_paid' => 'Credit sales must leave an unpaid balance.',
                        ]);
                    }

                    $customer = $this->resolveCreditCustomer($request);
                    $projectedBalance = round((float) $customer->outstanding_balance + $creditAmount, 2);

                    if ((float) $customer->credit_limit > 0 && $projectedBalance > (float) $customer->credit_limit) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'This credit sale would exceed the customer credit limit.',
                        ]);
                    }
                } elseif ($request->filled('customer_id')) {
                    $customer = Customer::find($request->customer_id);
                }

                $sale = Sale::create([
                    'cashier_id' => $request->input('cashier_id', $request->user()?->id),
                    'customer_id' => $customer?->id,
                    'customer_name' => $customer?->name,
                    'customer_phone' => $customer?->phone,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                    'amount_paid' => $amountPaid,
                    'change_due' => max($amountPaid - $total, 0),
                    'credit_amount' => $creditAmount,
                    'payment_method' => $request->payment_method,
                    'payment_status' => $creditAmount > 0 ? ($amountPaid > 0 ? 'partial' : 'unpaid') : 'paid',
                    'status' => 'completed',
                    'notes' => $request->notes,
                ]);

                if ($customer && $creditAmount > 0) {
                    $customer->increment('outstanding_balance', $creditAmount);
                }

                foreach ($items as $item) {
                    $product = $products->get($item['product_id']);
                    $quantity = (int) $item['quantity'];
                    $lineTotal = $quantity * (float) $product->selling_price;

                    $sale->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'quantity' => $quantity,
                        'unit_price' => $product->selling_price,
                        'unit_cost' => $product->cost_price,
                        'line_total' => $lineTotal,
                        'line_profit' => ($lineTotal - ($quantity * (float) $product->cost_price)),
                    ]);

                    $product->decrement('stock_qty', $quantity);
                }

                return $sale->load('cashier:id,name,email,role', 'customer', 'items.product');
            });

            ActivityLog::record($request, 'sale_completed', "Completed sale #{$sale->id}", [
                'subject_type' => Sale::class,
                'subject_id' => $sale->id,
                'after' => [
                    'subtotal' => $sale->subtotal,
                    'discount_amount' => $sale->discount_amount,
                    'total' => $sale->total,
                    'amount_paid' => $sale->amount_paid,
                    'credit_amount' => $sale->credit_amount,
                    'payment_method' => $sale->payment_method,
                    'payment_status' => $sale->payment_status,
                    'customer_id' => $sale->customer_id,
                    'customer_name' => $sale->customer_name,
                    'items' => $sale->items->map(fn ($item) => [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'line_total' => $item->line_total,
                    ])->values(),
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Sale completed successfully',
            'sale' => $sale,
        ], 201);
    }

    public function refund(Request $request, Sale $sale)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can process refunds.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.sale_item_id' => 'required|exists:sale_items,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $refundedSale = DB::transaction(function () use ($request, $sale) {
                $sale = Sale::whereKey($sale->id)->lockForUpdate()->with('items')->firstOrFail();

                if ($sale->status === 'voided') {
                    throw ValidationException::withMessages([
                        'sale' => 'Voided sales cannot be refunded.',
                    ]);
                }

                $requested = collect($request->items)->keyBy('sale_item_id');
                $saleItems = SaleItem::whereIn('id', $requested->keys())
                    ->where('sale_id', $sale->id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($saleItems->count() !== $requested->count()) {
                    throw ValidationException::withMessages([
                        'items' => 'One or more refund items do not belong to this sale.',
                    ]);
                }

                $refundAmount = 0;
                $refundProfit = 0;
                $refundLines = [];
                $subtotal = max((float) $sale->subtotal, 0.01);
                $discountRatio = max(min((float) $sale->discount_amount / $subtotal, 1), 0);

                foreach ($requested as $saleItemId => $payload) {
                    $saleItem = $saleItems->get((int) $saleItemId);
                    $quantity = (int) $payload['quantity'];
                    $available = (int) $saleItem->quantity - (int) $saleItem->refunded_quantity;

                    if ($quantity > $available) {
                        throw ValidationException::withMessages([
                            'items' => "Cannot refund more than {$available} unit(s) for {$saleItem->product_name}.",
                        ]);
                    }

                    $unitRefund = round((float) $saleItem->unit_price * (1 - $discountRatio), 2);
                    $lineRefund = round($unitRefund * $quantity, 2);
                    $unitProfit = (float) $saleItem->line_profit / max((int) $saleItem->quantity, 1);
                    $lineProfit = round($unitProfit * $quantity, 2);

                    $saleItem->update([
                        'refunded_quantity' => (int) $saleItem->refunded_quantity + $quantity,
                        'refunded_total' => (float) $saleItem->refunded_total + $lineRefund,
                        'refunded_profit' => (float) $saleItem->refunded_profit + $lineProfit,
                    ]);

                    $product = Product::whereKey($saleItem->product_id)->lockForUpdate()->first();
                    if ($product) {
                        $oldStock = (int) $product->stock_qty;
                        $product->increment('stock_qty', $quantity);
                        $product->refresh();

                        StockHistory::create([
                            'product_id' => $product->id,
                            'quantity_added' => $quantity,
                            'old_stock' => $oldStock,
                            'new_stock' => (int) $product->stock_qty,
                            'restocked_by' => $request->user()->id,
                        ]);
                    }

                    $refundAmount += $lineRefund;
                    $refundProfit += $lineProfit;
                    $refundLines[] = [
                        'sale_item_id' => $saleItem->id,
                        'product_id' => $saleItem->product_id,
                        'product_name' => $saleItem->product_name,
                        'quantity' => $quantity,
                        'refund_amount' => $lineRefund,
                    ];
                }

                $newRefundedAmount = min(round((float) $sale->refunded_amount + $refundAmount, 2), (float) $sale->total);
                $newRefundedProfit = round((float) $sale->refunded_profit + $refundProfit, 2);
                $allItemsRefunded = $sale->items()->get()->every(
                    fn ($item) => (int) $item->refunded_quantity >= (int) $item->quantity
                );

                $sale->update([
                    'status' => $allItemsRefunded ? 'refunded' : 'partially_refunded',
                    'refunded_amount' => $newRefundedAmount,
                    'refunded_profit' => $newRefundedProfit,
                    'refunded_at' => now(),
                    'refunded_by' => $request->user()->id,
                    'refund_reason' => $request->reason,
                ]);

                $this->reduceCustomerDebtForReversal($sale, $refundAmount);

                ActivityLog::record($request, 'sale_refunded', "Refunded sale #{$sale->id}", [
                    'subject_type' => Sale::class,
                    'subject_id' => $sale->id,
                    'after' => [
                        'status' => $sale->status,
                        'refund_amount' => $refundAmount,
                        'reason' => $request->reason,
                        'items' => $refundLines,
                    ],
                ]);

                return $sale->fresh()->load('cashier:id,name,email,role', 'customer', 'refundedBy:id,name,email,role', 'items.product');
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Refund processed successfully',
            'sale' => $refundedSale,
        ]);
    }

    public function void(Request $request, Sale $sale)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can void sales.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $voidedSale = DB::transaction(function () use ($request, $sale) {
                $sale = Sale::whereKey($sale->id)->lockForUpdate()->with('items')->firstOrFail();

                if ($sale->status === 'voided') {
                    throw ValidationException::withMessages([
                        'sale' => 'This sale has already been voided.',
                    ]);
                }

                if ($sale->items->sum('refunded_quantity') > 0) {
                    throw ValidationException::withMessages([
                        'sale' => 'Partially refunded sales cannot be voided. Refund the remaining items instead.',
                    ]);
                }

                foreach ($sale->items as $item) {
                    $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                    if (! $product) {
                        continue;
                    }

                    $oldStock = (int) $product->stock_qty;
                    $product->increment('stock_qty', (int) $item->quantity);
                    $product->refresh();

                    StockHistory::create([
                        'product_id' => $product->id,
                        'quantity_added' => (int) $item->quantity,
                        'old_stock' => $oldStock,
                        'new_stock' => (int) $product->stock_qty,
                        'restocked_by' => $request->user()->id,
                    ]);
                }

                $profit = round((float) $sale->items->sum('line_profit'), 2);
                $sale->update([
                    'status' => 'voided',
                    'refunded_amount' => (float) $sale->total,
                    'refunded_profit' => $profit,
                    'voided_at' => now(),
                    'voided_by' => $request->user()->id,
                    'void_reason' => $request->reason,
                ]);

                $this->reduceCustomerDebtForReversal($sale, (float) $sale->credit_amount);

                ActivityLog::record($request, 'sale_voided', "Voided sale #{$sale->id}", [
                    'subject_type' => Sale::class,
                    'subject_id' => $sale->id,
                    'after' => [
                        'status' => 'voided',
                        'reason' => $request->reason,
                        'refunded_amount' => $sale->total,
                    ],
                ]);

                return $sale->fresh()->load('cashier:id,name,email,role', 'customer', 'voidedBy:id,name,email,role', 'items.product');
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Sale voided successfully',
            'sale' => $voidedSale,
        ]);
    }

    private function resolveCreditCustomer(Request $request): Customer
    {
        if ($request->filled('customer_id')) {
            return Customer::whereKey($request->customer_id)->lockForUpdate()->firstOrFail();
        }

        if (! $request->filled('customer_name') || ! $request->filled('customer_phone')) {
            throw ValidationException::withMessages([
                'customer_name' => 'Customer name and phone are required for credit sales.',
            ]);
        }

        return Customer::where('phone', $request->customer_phone)
            ->lockForUpdate()
            ->first()
            ?? Customer::create([
                'name' => $request->customer_name,
                'phone' => $request->customer_phone,
                'email' => $request->customer_email,
            ]);
    }

    private function reduceCustomerDebtForReversal(Sale $sale, float $amount): void
    {
        if (! $sale->customer_id || $amount <= 0) {
            return;
        }

        $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->first();
        if (! $customer) {
            return;
        }

        $reduction = min((float) $customer->outstanding_balance, $amount);
        $customer->update([
            'outstanding_balance' => round((float) $customer->outstanding_balance - $reduction, 2),
        ]);
    }
}
