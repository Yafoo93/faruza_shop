<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can access analytics.'], 403);
        }

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $deadStockCutoff = Carbon::now()->subDays(60);
        $expiryCutoff = Carbon::now()->addDays(30)->endOfDay();

        $products = Product::all();
        $sales = Sale::with('cashier:id,name,email,role', 'items.product')->get();
        $activeSales = $sales->where('status', '!=', 'voided');
        $saleItems = SaleItem::with('sale.cashier:id,name,email,role', 'product')
            ->get()
            ->filter(fn ($item) => $item->sale?->status !== 'voided');

        $todaySales = $activeSales->filter(fn ($sale) => $sale->created_at?->isSameDay($today));
        $monthSales = $activeSales->filter(
            fn ($sale) => $sale->created_at && $sale->created_at->betweenIncluded($monthStart, $monthEnd)
        );

        $profitForItem = function ($item) {
            if ($item->line_profit !== null) {
                return max((float) $item->line_profit - (float) $item->refunded_profit, 0);
            }

            $unitCost = $item->unit_cost ?? $item->product?->cost_price ?? 0;

            return ((float) $item->unit_price - (float) $unitCost) * max((int) $item->quantity - (int) $item->refunded_quantity, 0);
        };

        $netQuantityForItem = fn ($item) => max((int) $item->quantity - (int) $item->refunded_quantity, 0);
        $netTotalForItem = fn ($item) => max((float) $item->line_total - (float) $item->refunded_total, 0);

        $profitForSales = function ($saleCollection) use ($profitForItem) {
            return $saleCollection
                ->flatMap(fn ($sale) => $sale->items)
                ->sum(fn ($item) => $profitForItem($item));
        };

        $topProducts = $saleItems
            ->filter(fn ($item) => $netQuantityForItem($item) > 0)
            ->groupBy('product_id')
            ->map(function ($items) use ($profitForItem, $netQuantityForItem, $netTotalForItem) {
                $first = $items->first();

                return [
                    'product_id' => $first->product_id,
                    'name' => $first->product_name,
                    'sku' => $first->product_sku,
                    'quantity' => (int) $items->sum(fn ($item) => $netQuantityForItem($item)),
                    'sales' => round((float) $items->sum(fn ($item) => $netTotalForItem($item)), 2),
                    'profit' => round((float) $items->sum(fn ($item) => $profitForItem($item)), 2),
                ];
            })
            ->sortByDesc('quantity')
            ->values();

        $lastSoldByProduct = $saleItems
            ->filter(fn ($item) => $item->sale?->created_at !== null)
            ->groupBy('product_id')
            ->map(fn ($items) => $items->max(fn ($item) => $item->sale->created_at));

        $rareMovers = $products
            ->filter(function ($product) use ($lastSoldByProduct, $deadStockCutoff) {
                $lastSoldAt = $lastSoldByProduct->get($product->id);

                return ! $lastSoldAt || Carbon::parse($lastSoldAt)->lessThan($deadStockCutoff);
            })
            ->sortByDesc('stock_qty')
            ->take(8)
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock_qty' => (int) $product->stock_qty,
                'last_sold_at' => $lastSoldByProduct->get($product->id),
            ])
            ->values();

        $cashierPerformance = $activeSales
            ->groupBy('cashier_id')
            ->map(function ($cashierSales) use ($profitForSales) {
                $firstSale = $cashierSales->first();

                return [
                    'cashier_id' => $firstSale->cashier_id,
                    'name' => $firstSale->cashier?->name ?? 'Unknown',
                    'transactions' => $cashierSales->count(),
                    'sales' => round((float) $cashierSales->sum(fn ($sale) => $sale->net_total), 2),
                    'profit' => round((float) $profitForSales($cashierSales), 2),
                ];
            })
            ->sortByDesc('sales')
            ->values();

        $peakHours = $activeSales
            ->groupBy(fn ($sale) => $sale->created_at?->format('H:00') ?? 'Unknown')
            ->map(fn ($hourSales, $hour) => [
                'hour' => $hour,
                'transactions' => $hourSales->count(),
                'sales' => round((float) $hourSales->sum(fn ($sale) => $sale->net_total), 2),
            ])
            ->sortBy('hour')
            ->values();

        $monthlyRevenue = collect(range(5, 0))
            ->map(function ($monthsBack) use ($activeSales, $profitForSales) {
                $month = Carbon::now()->subMonths($monthsBack);
                $monthSales = $activeSales->filter(
                    fn ($sale) => $sale->created_at
                        && $sale->created_at->year === $month->year
                        && $sale->created_at->month === $month->month
                );

                return [
                    'month' => $month->format('M Y'),
                    'sales' => round((float) $monthSales->sum(fn ($sale) => $sale->net_total), 2),
                    'profit' => round((float) $profitForSales($monthSales), 2),
                    'transactions' => $monthSales->count(),
                ];
            });

        $lowStockProducts = $products
            ->filter(fn ($product) => (int) $product->stock_qty <= (int) $product->min_stock_threshold)
            ->sortBy('stock_qty');

        $expiringProducts = $products
            ->filter(
                fn ($product) => $product->expiry_date
                    && $product->expiry_date->betweenIncluded($today, $expiryCutoff)
            )
            ->sortBy('expiry_date');

        $lowStockList = $lowStockProducts
            ->take(8)
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock_qty' => (int) $product->stock_qty,
                'min_stock_threshold' => (int) $product->min_stock_threshold,
            ])
            ->values();

        $expiringList = $expiringProducts
            ->take(8)
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock_qty' => (int) $product->stock_qty,
                'expiry_date' => $product->expiry_date?->toDateString(),
            ])
            ->values();

        $recentTransactions = $sales
            ->sortByDesc('created_at')
            ->take(8)
            ->map(fn ($sale) => [
                'id' => $sale->id,
                'created_at' => $sale->created_at,
                'cashier' => $sale->cashier?->name ?? 'Unknown',
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'total' => (float) $sale->net_total,
                'profit' => round((float) $profitForSales(collect([$sale])), 2),
                'total_quantity' => $sale->net_quantity,
            ])
            ->values();

        return response()->json([
            'kpis' => [
                'today_sales' => round((float) $todaySales->sum(fn ($sale) => $sale->net_total), 2),
                'today_profit' => round((float) $profitForSales($todaySales), 2),
                'today_transactions' => $todaySales->count(),
                'month_sales' => round((float) $monthSales->sum(fn ($sale) => $sale->net_total), 2),
                'month_profit' => round((float) $profitForSales($monthSales), 2),
                'total_profit' => round((float) $profitForSales($activeSales), 2),
                'refunded_amount' => round((float) $sales->sum('refunded_amount'), 2),
                'outstanding_credit' => round((float) Customer::sum('outstanding_balance'), 2),
                'owing_customers' => Customer::where('outstanding_balance', '>', 0)->count(),
                'voided_transactions' => $sales->where('status', 'voided')->count(),
                'stock_value' => round((float) $products->sum(fn ($product) => $product->stock_qty * $product->cost_price), 2),
                'low_stock_count' => $lowStockProducts->count(),
                'out_of_stock_count' => $products->where('stock_qty', '<=', 0)->count(),
                'expiring_soon_count' => $expiringProducts->count(),
            ],
            'top_selling_products' => $topProducts->take(8)->values(),
            'top_profit_products' => $topProducts->sortByDesc('profit')->take(8)->values(),
            'rare_movers' => $rareMovers,
            'cashier_performance' => $cashierPerformance,
            'peak_hours' => $peakHours,
            'monthly_revenue' => $monthlyRevenue,
            'low_stock_products' => $lowStockList,
            'expiring_products' => $expiringList,
            'recent_transactions' => $recentTransactions,
        ]);
    }
}
