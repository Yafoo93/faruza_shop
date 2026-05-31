<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    // List all products
    public function index()
    {
        $products = Product::all();
        return response()->json($products);
    }

    // Show single product
    public function show($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }
        return response()->json($product);
    }

    // Create new product
    public function store(Request $request)
    {
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
        return response()->json($product, 201);
    }

    // Update product
    public function update(Request $request, $id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'sku' => 'string|unique:products,sku,' . $id,
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

        $product->update($request->all());
        return response()->json($product);
    }

    // Delete product
    public function destroy($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->delete();
        return response()->json(['message' => 'Product deleted successfully']);
    }

    public function restock(Request $request, $id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $request->validate([
            'quantity_added' => 'required|integer|min:1',
            'cost_price' => 'required|numeric',
            'selling_price' => 'required|numeric',
            'restocked_by' => 'required|exists:users,id'
        ]);

        $oldStock = $product->stock_qty;

        // Update product stock
        $product->stock_qty += $request->quantity_added;
        $product->cost_price = $request->cost_price;
        $product->selling_price = $request->selling_price;
        $product->save();

        // Log stock history
        \App\Models\StockHistory::create([
            'product_id' => $product->id,
            'quantity_added' => $request->quantity_added,
            'old_stock' => $oldStock,
            'new_stock' => $product->stock_qty,
            'restocked_by' => $request->restocked_by,
        ]);

        // Optional: Check low stock and add alert (can extend later)
        if ($product->stock_qty <= $product->min_stock_threshold) {
            // Example: send alert or flag
            // $this->sendLowStockAlert($product);
        }

        return response()->json([
            'message' => 'Product restocked successfully',
            'product' => $product
        ]);
    }
}