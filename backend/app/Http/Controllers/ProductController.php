<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    // List all products
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->user()?->role !== 'admin' || $request->input('status', 'active') === 'active') {
            $query->whereNull('archived_at');
        } elseif ($request->input('status') === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $products = $query->orderBy('name')->get();

        if ($request->user()?->role !== 'admin') {
            $products->makeHidden(['cost_price']);
        }

        return response()->json($products);
    }

    // Show single product
    public function show(Request $request, $id)
    {
        $product = Product::find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($request->user()?->role !== 'admin') {
            $product->makeHidden(['cost_price']);
        }

        return response()->json($product);
    }

    // Create new product
    public function store(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage products.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'category' => 'required|string|max:255',
            'cost_price' => 'required|numeric',
            'selling_price' => 'required|numeric',
            'stock_qty' => 'required|integer',
            'min_stock_threshold' => 'integer',
            'expiry_date' => 'date|nullable',
            'image' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $product = Product::create($request->all());

        ActivityLog::record($request, 'product_created', "Created product {$product->name}", [
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'after' => $product->toArray(),
        ]);

        return response()->json($product, 201);
    }

    // Update product
    public function update(Request $request, $id)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage products.'], 403);
        }

        $product = Product::find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'sku' => 'string|unique:products,sku,'.$id,
            'category' => 'string|max:255',
            'cost_price' => 'numeric',
            'selling_price' => 'numeric',
            'stock_qty' => 'integer',
            'min_stock_threshold' => 'integer',
            'expiry_date' => 'date|nullable',
            'image' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $before = $product->only([
            'name',
            'sku',
            'category',
            'cost_price',
            'selling_price',
            'stock_qty',
            'min_stock_threshold',
            'expiry_date',
            'image',
        ]);

        $product->update($request->all());

        $after = $product->fresh()->only(array_keys($before));
        $changedFields = collect($after)
            ->filter(fn ($value, $field) => (string) ($before[$field] ?? '') !== (string) $value)
            ->keys()
            ->values()
            ->all();

        ActivityLog::record($request, 'product_updated', "Updated product {$product->name}", [
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'before' => $before,
            'after' => $after,
            'metadata' => ['changed_fields' => $changedFields],
        ]);

        if (in_array('cost_price', $changedFields, true) || in_array('selling_price', $changedFields, true)) {
            ActivityLog::record($request, 'price_changed', "Changed prices for {$product->name}", [
                'subject_type' => Product::class,
                'subject_id' => $product->id,
                'before' => [
                    'cost_price' => $before['cost_price'],
                    'selling_price' => $before['selling_price'],
                ],
                'after' => [
                    'cost_price' => $after['cost_price'],
                    'selling_price' => $after['selling_price'],
                ],
            ]);
        }

        return response()->json($product);
    }

    // Delete product
    public function destroy(Request $request, $id)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage products.'], 403);
        }

        $product = Product::find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $before = $product->only(['archived_at']);
        $product->update(['archived_at' => now()]);

        ActivityLog::record($request, 'product_archived', "Archived product {$product->name}", [
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'before' => $before,
            'after' => ['archived_at' => $product->archived_at],
        ]);

        return response()->json(['message' => 'Product archived successfully']);
    }

    public function restore(Request $request, $id)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can manage products.'], 403);
        }

        $product = Product::find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $before = $product->only(['archived_at']);
        $product->update(['archived_at' => null]);

        ActivityLog::record($request, 'product_restored', "Restored product {$product->name}", [
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'before' => $before,
            'after' => ['archived_at' => null],
        ]);

        return response()->json(['message' => 'Product restored successfully', 'product' => $product]);
    }

    public function restock(Request $request, $id)
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Only admins can restock products.'], 403);
        }

        $product = Product::find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $request->validate([
            'quantity_added' => 'required|integer|min:1',
            'cost_price' => 'required|numeric',
            'selling_price' => 'required|numeric',
        ]);

        $oldStock = $product->stock_qty;
        $before = $product->only(['stock_qty', 'cost_price', 'selling_price']);

        // Update product stock
        $product->stock_qty += $request->quantity_added;
        $product->cost_price = $request->cost_price;
        $product->selling_price = $request->selling_price;
        $product->save();

        // Log stock history
        $stockHistory = StockHistory::create([
            'product_id' => $product->id,
            'quantity_added' => $request->quantity_added,
            'old_stock' => $oldStock,
            'new_stock' => $product->stock_qty,
            'restocked_by' => $request->user()->id,
        ]);

        $after = $product->fresh()->only(['stock_qty', 'cost_price', 'selling_price']);

        ActivityLog::record($request, 'product_restocked', "Restocked {$product->name}", [
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'before' => $before,
            'after' => $after,
            'metadata' => [
                'stock_history_id' => $stockHistory->id,
                'quantity_added' => (int) $request->quantity_added,
            ],
        ]);

        if (
            (string) $before['cost_price'] !== (string) $after['cost_price']
            || (string) $before['selling_price'] !== (string) $after['selling_price']
        ) {
            ActivityLog::record($request, 'price_changed', "Changed prices while restocking {$product->name}", [
                'subject_type' => Product::class,
                'subject_id' => $product->id,
                'before' => [
                    'cost_price' => $before['cost_price'],
                    'selling_price' => $before['selling_price'],
                ],
                'after' => [
                    'cost_price' => $after['cost_price'],
                    'selling_price' => $after['selling_price'],
                ],
                'metadata' => ['stock_history_id' => $stockHistory->id],
            ]);
        }

        // Optional: Check low stock and add alert (can extend later)
        if ($product->stock_qty <= $product->min_stock_threshold) {
            // Example: send alert or flag
            // $this->sendLowStockAlert($product);
        }

        return response()->json([
            'message' => 'Product restocked successfully',
            'product' => $product,
        ]);
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }
}
