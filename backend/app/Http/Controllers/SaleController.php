<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cashier_id' => 'required|exists:users,id',
            'payment_method' => 'required|string|in:cash,mobile_money,card',
            'amount_paid' => 'required|numeric|min:0',
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

                if ((float) $request->amount_paid < $subtotal) {
                    throw ValidationException::withMessages([
                        'amount_paid' => 'Amount paid is less than the sale total.',
                    ]);
                }

                $sale = Sale::create([
                    'cashier_id' => $request->cashier_id,
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                    'amount_paid' => $request->amount_paid,
                    'change_due' => (float) $request->amount_paid - $subtotal,
                    'payment_method' => $request->payment_method,
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

                return $sale->load('items');
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
