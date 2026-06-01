<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
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
            'payment_method' => 'nullable|string|in:cash,mobile_money,card',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $query = Sale::query()
            ->with('cashier:id,name,email,role')
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

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
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
                    });
            });
        }

        $summaryQuery = clone $query;
        $summary = [
            'sales_count' => (clone $summaryQuery)->count(),
            'subtotal' => (float) (clone $summaryQuery)->sum('subtotal'),
            'discount_amount' => (float) (clone $summaryQuery)->sum('discount_amount'),
            'total' => (float) (clone $summaryQuery)->sum('total'),
            'amount_paid' => (float) (clone $summaryQuery)->sum('amount_paid'),
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
            'sale' => $sale->load('cashier:id,name,email,role', 'items.product'),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cashier_id' => 'nullable|exists:users,id',
            'payment_method' => 'required|string|in:cash,mobile_money,card',
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

                if ((float) $request->amount_paid < $total) {
                    throw ValidationException::withMessages([
                        'amount_paid' => 'Amount paid is less than the sale total.',
                    ]);
                }

                $sale = Sale::create([
                    'cashier_id' => $request->input('cashier_id', $request->user()?->id),
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                    'amount_paid' => $request->amount_paid,
                    'change_due' => (float) $request->amount_paid - $total,
                    'payment_method' => $request->payment_method,
                    'notes' => $request->notes,
                ]);

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
                        'line_total' => $lineTotal,
                    ]);

                    $product->decrement('stock_qty', $quantity);
                }

                return $sale->load('cashier:id,name,email,role', 'items.product');
            });
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
}
