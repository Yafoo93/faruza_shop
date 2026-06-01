<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can access inventory analytics.'], 403);
        }

        $products = Product::with('saleItems.sale')->orderBy('name')->get();
        $today = Carbon::today();
        $expiryCutoff = Carbon::now()->addDays(30)->endOfDay();
        $deadStockCutoff = Carbon::now()->subDays(60);

        $inventoryProducts = $products->map(function ($product) use ($today, $expiryCutoff, $deadStockCutoff) {
            $lastSoldAt = $product->saleItems
                ->filter(fn ($item) => $item->sale?->created_at !== null)
                ->max(fn ($item) => $item->sale->created_at);
            $unitsSold = (int) $product->saleItems->sum('quantity');
            $stockQty = (int) $product->stock_qty;
            $threshold = (int) $product->min_stock_threshold;
            $status = $this->stockStatus($stockQty, $threshold);
            $expiryDate = $product->expiry_date;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category,
                'cost_price' => (float) $product->cost_price,
                'selling_price' => (float) $product->selling_price,
                'stock_qty' => $stockQty,
                'min_stock_threshold' => $threshold,
                'stock_value' => round($stockQty * (float) $product->cost_price, 2),
                'potential_revenue' => round($stockQty * (float) $product->selling_price, 2),
                'status' => $status,
                'expiry_date' => $expiryDate?->toDateString(),
                'expires_soon' => $expiryDate && $expiryDate->betweenIncluded($today, $expiryCutoff),
                'units_sold' => $unitsSold,
                'last_sold_at' => $lastSoldAt ? Carbon::parse($lastSoldAt)->toDateTimeString() : null,
                'dead_stock' => ! $lastSoldAt || Carbon::parse($lastSoldAt)->lessThan($deadStockCutoff),
            ];
        });

        $summary = [
            'products_count' => $products->count(),
            'units_in_stock' => (int) $products->sum('stock_qty'),
            'stock_value' => round((float) $products->sum(fn ($product) => $product->stock_qty * $product->cost_price), 2),
            'potential_revenue' => round((float) $products->sum(fn ($product) => $product->stock_qty * $product->selling_price), 2),
            'low_stock_count' => $inventoryProducts->whereIn('status', ['Low Stock', 'Critical Stock'])->count(),
            'out_of_stock_count' => $inventoryProducts->where('status', 'Out of Stock')->count(),
            'expiring_soon_count' => $inventoryProducts->where('expires_soon', true)->count(),
            'dead_stock_count' => $inventoryProducts->where('dead_stock', true)->count(),
        ];

        $recentRestocks = StockHistory::with('product:id,name,sku', 'user:id,name,email,role')
            ->latest()
            ->take(12)
            ->get();

        return response()->json([
            'summary' => $summary,
            'products' => $inventoryProducts->values(),
            'alerts' => [
                'low_stock' => $inventoryProducts->whereIn('status', ['Low Stock', 'Critical Stock'])->sortBy('stock_qty')->values(),
                'out_of_stock' => $inventoryProducts->where('status', 'Out of Stock')->values(),
                'expiring_soon' => $inventoryProducts->where('expires_soon', true)->sortBy('expiry_date')->values(),
                'dead_stock' => $inventoryProducts->where('dead_stock', true)->sortByDesc('stock_qty')->values(),
            ],
            'recent_restocks' => $recentRestocks,
        ]);
    }

    public function history(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can access inventory history.'], 403);
        }

        $history = StockHistory::with('product:id,name,sku', 'user:id,name,email,role')
            ->latest()
            ->paginate((int) $request->input('per_page', 25));

        return response()->json($history);
    }

    private function stockStatus(int $stockQty, int $threshold): string
    {
        if ($stockQty <= 0) {
            return 'Out of Stock';
        }

        if ($stockQty <= 10) {
            return 'Critical Stock';
        }

        if ($stockQty <= max($threshold, 20)) {
            return 'Low Stock';
        }

        return 'In Stock';
    }
}
