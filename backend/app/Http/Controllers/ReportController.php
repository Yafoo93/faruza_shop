<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can export reports.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'format' => 'nullable|string|in:pdf,csv,excel',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : Carbon::now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : Carbon::now()->endOfDay();
        $format = $request->input('format', 'pdf');

        $sales = Sale::with('cashier:id,name,email,role', 'customer', 'items.product')
            ->whereBetween('created_at', [$from, $to])
            ->oldest()
            ->get();

        $summary = $this->salesSummary($sales);
        $productRows = $this->productBreakdown($sales);
        $cashierRows = $this->cashierBreakdown($sales);
        $filenameBase = 'sales-report-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d');

        if ($format === 'csv') {
            return $this->csvResponse($this->salesReportCsvRows($sales, $summary, $productRows, $cashierRows), "{$filenameBase}.csv");
        }

        if ($format === 'excel') {
            return $this->excelResponse(
                'Sales Report',
                $this->salesReportTables($sales, $summary, $productRows, $cashierRows),
                "{$filenameBase}.xls"
            );
        }

        return $this->pdfResponse(
            'Sales Report',
            $this->salesReportPdfLines($from, $to, $sales, $summary, $productRows, $cashierRows),
            "{$filenameBase}.pdf"
        );
    }

    public function receipt(Request $request, Sale $sale)
    {
        $validator = Validator::make($request->all(), [
            'format' => 'nullable|string|in:pdf,csv,excel',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $sale->load('cashier:id,name,email,role', 'customer', 'items.product');
        $format = $request->input('format', 'pdf');
        $filenameBase = 'receipt-sale-'.$sale->id;

        if ($format === 'csv') {
            return $this->csvResponse($this->receiptCsvRows($sale), "{$filenameBase}.csv");
        }

        if ($format === 'excel') {
            return $this->excelResponse('Receipt', $this->receiptTables($sale), "{$filenameBase}.xls");
        }

        return $this->pdfResponse('Receipt', $this->receiptPdfLines($sale), "{$filenameBase}.pdf");
    }

    private function salesSummary(Collection $sales): array
    {
        $nonVoidedSales = $sales->where('status', '!=', 'voided');

        return [
            'transactions' => $nonVoidedSales->count(),
            'voided_transactions' => $sales->where('status', 'voided')->count(),
            'items_sold' => $nonVoidedSales->sum(fn ($sale) => $this->saleNetQuantity($sale)),
            'subtotal' => round((float) $nonVoidedSales->sum('subtotal'), 2),
            'discount' => round((float) $nonVoidedSales->sum('discount_amount'), 2),
            'gross_sales' => round((float) $nonVoidedSales->sum('total'), 2),
            'refunded' => round((float) $sales->sum('refunded_amount'), 2),
            'credit' => round((float) $nonVoidedSales->sum('credit_amount'), 2),
            'net_sales' => round((float) $sales->sum(fn ($sale) => $sale->net_total), 2),
            'profit' => round((float) $sales->sum(fn ($sale) => $this->saleProfit($sale)), 2),
            'cash' => round((float) $nonVoidedSales->where('payment_method', 'cash')->sum(fn ($sale) => $sale->net_total), 2),
            'mobile_money' => round((float) $nonVoidedSales->where('payment_method', 'mobile_money')->sum(fn ($sale) => $sale->net_total), 2),
            'card' => round((float) $nonVoidedSales->where('payment_method', 'card')->sum(fn ($sale) => $sale->net_total), 2),
        ];
    }

    private function productBreakdown(Collection $sales): Collection
    {
        return $sales
            ->where('status', '!=', 'voided')
            ->flatMap(fn ($sale) => $sale->items)
            ->filter(fn ($item) => $this->itemNetQuantity($item) > 0)
            ->groupBy('product_id')
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'product' => $first->product_name,
                    'sku' => $first->product_sku,
                    'quantity' => (int) $items->sum(fn ($item) => $this->itemNetQuantity($item)),
                    'gross_sales' => round((float) $items->sum(fn ($item) => $this->itemNetTotal($item)), 2),
                    'profit' => round((float) $items->sum(fn ($item) => $this->itemProfit($item)), 2),
                ];
            })
            ->sortByDesc('gross_sales')
            ->values();
    }

    private function cashierBreakdown(Collection $sales): Collection
    {
        return $sales
            ->where('status', '!=', 'voided')
            ->groupBy('cashier_id')
            ->map(fn ($cashierSales) => [
                'cashier' => $cashierSales->first()->cashier?->name ?? 'Unknown',
                'transactions' => $cashierSales->count(),
                'items_sold' => $cashierSales->sum(fn ($sale) => $this->saleNetQuantity($sale)),
                'gross_sales' => round((float) $cashierSales->sum(fn ($sale) => $sale->net_total), 2),
                'profit' => round((float) $cashierSales->sum(fn ($sale) => $this->saleProfit($sale)), 2),
            ])
            ->sortByDesc('gross_sales')
            ->values();
    }

    private function saleProfit(Sale $sale): float
    {
        if ($sale->status === 'voided') {
            return 0;
        }

        return (float) $sale->items->sum(fn ($item) => $this->itemProfit($item));
    }

    private function itemProfit($item): float
    {
        if ($item->line_profit !== null) {
            return max((float) $item->line_profit - (float) $item->refunded_profit, 0);
        }

        $unitCost = $item->unit_cost ?? $item->product?->cost_price ?? 0;

        return ((float) $item->unit_price - (float) $unitCost) * $this->itemNetQuantity($item);
    }

    private function saleNetQuantity(Sale $sale): int
    {
        if ($sale->status === 'voided') {
            return 0;
        }

        return (int) $sale->items->sum(fn ($item) => $this->itemNetQuantity($item));
    }

    private function itemNetQuantity($item): int
    {
        return max((int) $item->quantity - (int) $item->refunded_quantity, 0);
    }

    private function itemNetTotal($item): float
    {
        return max((float) $item->line_total - (float) $item->refunded_total, 0);
    }

    private function salesReportCsvRows(Collection $sales, array $summary, Collection $productRows, Collection $cashierRows): array
    {
        $rows = [
            ['F mart Sales Report'],
            ['Summary'],
            ['Transactions', $summary['transactions']],
            ['Items sold', $summary['items_sold']],
            ['Subtotal', $summary['subtotal']],
            ['Discounts', $summary['discount']],
            ['Gross sales', $summary['gross_sales']],
            ['Refunded', $summary['refunded']],
            ['Credit issued', $summary['credit']],
            ['Net sales', $summary['net_sales']],
            ['Estimated profit', $summary['profit']],
            ['Cash sales', $summary['cash']],
            ['Mobile money sales', $summary['mobile_money']],
            ['Card sales', $summary['card']],
            [],
            ['Sales'],
            ['Sale ID', 'Date', 'Cashier', 'Customer', 'Status', 'Payment', 'Payment status', 'Items', 'Subtotal', 'Discount', 'Gross total', 'Refunded', 'Credit', 'Net total', 'Profit'],
        ];

        foreach ($sales as $sale) {
            $rows[] = [
                $sale->id,
                $sale->created_at?->toDateTimeString(),
                $sale->cashier?->name ?? 'Unknown',
                $sale->customer_name ?? $sale->customer?->name ?? 'Walk-in',
                $sale->status,
                $sale->payment_method,
                $sale->payment_status,
                $this->saleNetQuantity($sale),
                $sale->subtotal,
                $sale->discount_amount,
                $sale->total,
                $sale->refunded_amount,
                $sale->credit_amount,
                $sale->net_total,
                round($this->saleProfit($sale), 2),
            ];
        }

        $rows[] = [];
        $rows[] = ['Product Breakdown'];
        $rows[] = ['Product', 'SKU', 'Quantity', 'Gross sales', 'Profit'];
        foreach ($productRows as $row) {
            $rows[] = array_values($row);
        }

        $rows[] = [];
        $rows[] = ['Cashier Breakdown'];
        $rows[] = ['Cashier', 'Transactions', 'Items sold', 'Gross sales', 'Profit'];
        foreach ($cashierRows as $row) {
            $rows[] = array_values($row);
        }

        return $rows;
    }

    private function salesReportTables(Collection $sales, array $summary, Collection $productRows, Collection $cashierRows): array
    {
        return [
            'Summary' => [
                ['Metric', 'Value'],
                ['Transactions', $summary['transactions']],
                ['Items sold', $summary['items_sold']],
                ['Gross sales', $summary['gross_sales']],
                ['Refunded', $summary['refunded']],
                ['Credit issued', $summary['credit']],
                ['Net sales', $summary['net_sales']],
                ['Estimated profit', $summary['profit']],
                ['Discounts', $summary['discount']],
                ['Voided transactions', $summary['voided_transactions']],
            ],
            'Sales' => collect([['Sale ID', 'Date', 'Cashier', 'Customer', 'Status', 'Payment', 'Payment status', 'Items', 'Subtotal', 'Discount', 'Gross total', 'Refunded', 'Credit', 'Net total', 'Profit']])
                ->merge($sales->map(fn ($sale) => [
                    $sale->id,
                    $sale->created_at?->toDateTimeString(),
                    $sale->cashier?->name ?? 'Unknown',
                    $sale->customer_name ?? $sale->customer?->name ?? 'Walk-in',
                    $sale->status,
                    $sale->payment_method,
                    $sale->payment_status,
                    $this->saleNetQuantity($sale),
                    $sale->subtotal,
                    $sale->discount_amount,
                    $sale->total,
                    $sale->refunded_amount,
                    $sale->credit_amount,
                    $sale->net_total,
                    round($this->saleProfit($sale), 2),
                ]))
                ->all(),
            'Product Breakdown' => collect([['Product', 'SKU', 'Quantity', 'Gross sales', 'Profit']])
                ->merge($productRows->map(fn ($row) => array_values($row)))
                ->all(),
            'Cashier Breakdown' => collect([['Cashier', 'Transactions', 'Items sold', 'Gross sales', 'Profit']])
                ->merge($cashierRows->map(fn ($row) => array_values($row)))
                ->all(),
        ];
    }

    private function salesReportPdfLines(Carbon $from, Carbon $to, Collection $sales, array $summary, Collection $productRows, Collection $cashierRows): array
    {
        $lines = [
            'F mart Sales Report',
            'Period: '.$from->toDateString().' to '.$to->toDateString(),
            'Generated: '.now()->toDateTimeString(),
            '',
            'Summary',
            'Transactions: '.$summary['transactions'],
            'Items sold: '.$summary['items_sold'],
            'Subtotal: GHS '.$this->money($summary['subtotal']),
            'Discounts: GHS '.$this->money($summary['discount']),
            'Gross sales: GHS '.$this->money($summary['gross_sales']),
            'Refunded: GHS '.$this->money($summary['refunded']),
            'Credit issued: GHS '.$this->money($summary['credit']),
            'Net sales: GHS '.$this->money($summary['net_sales']),
            'Estimated profit: GHS '.$this->money($summary['profit']),
            'Cash: GHS '.$this->money($summary['cash']),
            'Mobile money: GHS '.$this->money($summary['mobile_money']),
            'Card: GHS '.$this->money($summary['card']),
            '',
            'Recent Sales',
        ];

        foreach ($sales->sortByDesc('created_at')->take(20) as $sale) {
            $lines[] = '#'.$sale->id.' | '.$sale->created_at?->format('Y-m-d H:i').' | '.$sale->cashier?->name.' | '.($sale->customer_name ?? 'Walk-in').' | '.$sale->status.' | Net GHS '.$this->money($sale->net_total).' | Credit GHS '.$this->money($sale->credit_amount);
        }

        $lines[] = '';
        $lines[] = 'Top Products';
        foreach ($productRows->take(12) as $row) {
            $lines[] = $row['product'].' ('.$row['sku'].') | Qty '.$row['quantity'].' | Sales GHS '.$this->money($row['gross_sales']).' | Profit GHS '.$this->money($row['profit']);
        }

        $lines[] = '';
        $lines[] = 'Cashier Performance';
        foreach ($cashierRows as $row) {
            $lines[] = $row['cashier'].' | '.$row['transactions'].' sales | GHS '.$this->money($row['gross_sales']).' | Profit GHS '.$this->money($row['profit']);
        }

        return $lines;
    }

    private function receiptCsvRows(Sale $sale): array
    {
        $rows = [
            ['F mart Receipt'],
            ['Sale ID', $sale->id],
            ['Date', $sale->created_at?->toDateTimeString()],
            ['Cashier', $sale->cashier?->name ?? 'Unknown'],
            ['Customer', $sale->customer_name ?? $sale->customer?->name ?? 'Walk-in'],
            ['Payment method', $sale->payment_method],
            ['Payment status', $sale->payment_status],
            ['Status', $sale->status],
            [],
            ['Item', 'SKU', 'Quantity', 'Refunded', 'Net quantity', 'Unit price', 'Line total'],
        ];

        foreach ($sale->items as $item) {
            $rows[] = [$item->product_name, $item->product_sku, $item->quantity, $item->refunded_quantity, $this->itemNetQuantity($item), $item->unit_price, $item->line_total];
        }

        return array_merge($rows, [
            [],
            ['Subtotal', $sale->subtotal],
            ['Discount', $sale->discount_amount],
            ['Total', $sale->total],
            ['Refunded', $sale->refunded_amount],
            ['Credit', $sale->credit_amount],
            ['Net total', $sale->net_total],
            ['Paid', $sale->amount_paid],
            ['Change', $sale->change_due],
            ['Notes', $sale->notes],
        ]);
    }

    private function receiptTables(Sale $sale): array
    {
        return [
            'Receipt' => $this->receiptCsvRows($sale),
        ];
    }

    private function receiptPdfLines(Sale $sale): array
    {
        $lines = [
            'F mart Receipt',
            'Sale #'.$sale->id,
            'Date: '.$sale->created_at?->toDateTimeString(),
            'Cashier: '.($sale->cashier?->name ?? 'Unknown'),
            'Customer: '.($sale->customer_name ?? $sale->customer?->name ?? 'Walk-in'),
            'Payment: '.$sale->payment_method,
            'Payment status: '.$sale->payment_status,
            'Status: '.$sale->status,
            '',
            'Items',
        ];

        foreach ($sale->items as $item) {
            $lines[] = $item->product_name.' ('.$item->product_sku.')';
            $lines[] = '  '.$item->quantity.' x GHS '.$this->money($item->unit_price).' = GHS '.$this->money($item->line_total);
            if ((int) $item->refunded_quantity > 0) {
                $lines[] = '  Refunded: '.$item->refunded_quantity.' | Net qty: '.$this->itemNetQuantity($item);
            }
        }

        return array_merge($lines, [
            '',
            'Subtotal: GHS '.$this->money($sale->subtotal),
            'Discount: GHS '.$this->money($sale->discount_amount),
            'Total: GHS '.$this->money($sale->total),
            'Refunded: GHS '.$this->money($sale->refunded_amount),
            'Credit: GHS '.$this->money($sale->credit_amount),
            'Net total: GHS '.$this->money($sale->net_total),
            'Paid: GHS '.$this->money($sale->amount_paid),
            'Change: GHS '.$this->money($sale->change_due),
            $sale->notes ? 'Notes: '.$sale->notes : '',
            '',
            'Thank you for shopping with us.',
        ]);
    }

    private function csvResponse(array $rows, string $filename)
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function excelResponse(string $title, array $tables, string $filename)
    {
        $html = '<html><head><meta charset="UTF-8"></head><body>';
        $html .= '<h1>'.$this->escapeHtml($title).'</h1>';

        foreach ($tables as $heading => $rows) {
            $html .= '<h2>'.$this->escapeHtml($heading).'</h2><table border="1">';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>'.$this->escapeHtml($cell).'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table><br>';
        }

        $html .= '</body></html>';

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function pdfResponse(string $title, array $lines, string $filename)
    {
        return response($this->makePdf($title, $lines), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function makePdf(string $title, array $lines): string
    {
        $pages = collect($lines)
            ->filter(fn ($line) => $line !== null)
            ->chunk(42)
            ->values();

        if ($pages->isEmpty()) {
            $pages = collect([collect([$title])]);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.$pages->keys()->map(fn ($index) => (4 + ($index * 2)).' 0 R')->implode(' ').'] /Count '.$pages->count().' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        foreach ($pages as $index => $pageLines) {
            $pageObjectId = 4 + ($index * 2);
            $contentObjectId = $pageObjectId + 1;
            $content = $this->pdfTextStream($pageLines->values()->all(), $index + 1, $pages->count());
            $objects[$pageObjectId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentObjectId.' 0 R >>';
            $objects[$contentObjectId] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_keys($objects) as $id) {
            $pdf .= str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }

    private function pdfTextStream(array $lines, int $page, int $pageCount): string
    {
        $content = "BT\n/F1 11 Tf\n14 TL\n50 760 Td\n";

        foreach ($lines as $line) {
            $content .= '('.$this->escapePdf((string) $line).") Tj\nT*\n";
        }

        $content .= "T*\n(Page {$page} of {$pageCount}) Tj\nET";

        return $content;
    }

    private function money($value): string
    {
        return number_format((float) $value, 2);
    }

    private function escapeHtml($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function escapePdf(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], substr($value, 0, 115));
    }
}
