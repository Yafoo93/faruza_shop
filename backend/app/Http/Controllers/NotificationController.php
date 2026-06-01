<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function alerts(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can access notifications.'], 403);
        }

        $today = Carbon::today();
        $expiryCutoff = Carbon::now()->addDays(30)->endOfDay();
        $deadStockCutoff = Carbon::now()->subDays(60);

        $products = Product::with('saleItems.sale')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        $mappedProducts = $products->map(function ($product) use ($today, $expiryCutoff, $deadStockCutoff) {
            $lastSoldAt = $product->saleItems
                ->filter(fn ($item) => $item->sale?->created_at !== null)
                ->max(fn ($item) => $item->sale->created_at);

            $expiryDate = $product->expiry_date;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock_qty' => (int) $product->stock_qty,
                'min_stock_threshold' => (int) $product->min_stock_threshold,
                'expiry_date' => $expiryDate?->toDateString(),
                'last_sold_at' => $lastSoldAt ? Carbon::parse($lastSoldAt)->toDateTimeString() : null,
                'expires_soon' => $expiryDate && $expiryDate->betweenIncluded($today, $expiryCutoff),
                'dead_stock' => ! $lastSoldAt || Carbon::parse($lastSoldAt)->lessThan($deadStockCutoff),
            ];
        });

        $suspiciousChanges = StockHistory::with('product:id,name,sku', 'user:id,name,email,role')
            ->latest()
            ->take(100)
            ->get()
            ->filter(function ($history) {
                $expectedStock = (int) $history->old_stock + (int) $history->quantity_added;

                return (int) $history->new_stock !== $expectedStock || (int) $history->quantity_added >= 100;
            })
            ->take(10)
            ->map(fn ($history) => [
                'id' => $history->id,
                'product' => $history->product?->name ?? 'Unknown product',
                'sku' => $history->product?->sku,
                'quantity_added' => (int) $history->quantity_added,
                'old_stock' => (int) $history->old_stock,
                'new_stock' => (int) $history->new_stock,
                'user' => $history->user?->name ?? 'Unknown',
                'created_at' => $history->created_at,
                'reason' => (int) $history->new_stock !== ((int) $history->old_stock + (int) $history->quantity_added)
                    ? 'Stock history mismatch'
                    : 'Large restock quantity',
            ])
            ->values();

        $alerts = [
            'low_stock' => $mappedProducts
                ->where('stock_qty', '>', 0)
                ->filter(fn ($product) => $product['stock_qty'] <= max($product['min_stock_threshold'], 20))
                ->values(),
            'out_of_stock' => $mappedProducts->where('stock_qty', '<=', 0)->values(),
            'expiring_soon' => $mappedProducts->where('expires_soon', true)->values(),
            'dead_stock' => $mappedProducts->where('dead_stock', true)->values(),
            'suspicious_changes' => $suspiciousChanges,
        ];

        return response()->json([
            'counts' => collect($alerts)->map(fn ($items) => $items->count()),
            'alerts' => $alerts,
        ]);
    }
}
