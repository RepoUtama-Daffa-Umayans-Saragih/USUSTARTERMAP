<?php

namespace App\Http\Controllers\AdminFotokopi;

use App\Http\Controllers\Controller;
use App\Models\FotocopyOrder;
use App\Models\FotocopyStore;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $scopedStoreId = auth()->user()?->scopedStoreId();
        $baseQuery = FotocopyOrder::query();

        if ($scopedStoreId !== null) {
            $baseQuery->where('store_id', $scopedStoreId);
        } elseif ($request->filled('store_id')) {
            $baseQuery->where('store_id', $request->input('store_id'));
        }

        if ($request->filled('order_status')) {
            $baseQuery->where('order_status', $request->input('order_status'));
        }

        if ($request->filled('payment_method')) {
            $baseQuery->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $baseQuery->where(function ($sq) use ($q) {
                $sq->whereSearchLike('order_code', "%{$q}%")
                    ->orWhereSearchLike('customer_name', "%{$q}%")
                    ->orWhereSearchLike('notes', "%{$q}%");
            });
        }

        $orders = (clone $baseQuery)->with(['store', 'items'])->latest()->paginate(15)->withQueryString();

        $nowWib = Carbon::now('Asia/Jakarta');
        $startDay = $nowWib->copy()->subDays(13)->startOfDay();
        $trendMap = $this->initializeTrendMap($nowWib);

        $useMaterializedView = DB::getDriverName() === 'pgsql' && !$request->filled('q');
        $mvAggregated = $useMaterializedView
            ? $this->aggregateFromMaterializedView($request, $scopedStoreId, (int) $orders->total(), $startDay)
            : null;

        if ($mvAggregated !== null) {
            $totalOrdersCount = $mvAggregated['totalOrdersCount'];
            $totalRevenueSum = $mvAggregated['totalRevenueSum'];
            $dailyStats = $mvAggregated['dailyStats'];
            $paymentGrouped = $mvAggregated['paymentGrouped'];
        } else {
            $totalOrdersCount = (int) $orders->total();
            $totalRevenueSum = (int) round((float) (clone $baseQuery)->where('payment_status', 'paid')->sum('total_price'));
            $dailyStats = $this->aggregateDailyStatsFromOrders($baseQuery, $startDay);
            $paymentGrouped = $this->aggregatePaymentBreakdownFromOrders($baseQuery);
        }

        foreach ($dailyStats as $dayKey => $row) {
            if (isset($trendMap[$dayKey])) {
                $trendMap[$dayKey]['orders_count'] = (int) $row['orders_count'];
                $trendMap[$dayKey]['paid_revenue'] = (int) round((float) $row['paid_revenue']);
            }
        }

        $maxTrendOrders = max(1, ...array_column($trendMap, 'orders_count'));
        foreach ($trendMap as &$point) {
            if ($point['orders_count'] > 0) {
                $heightPct = max(8, (int) round(($point['orders_count'] / $maxTrendOrders) * 100));
                $point['height_pct'] = $heightPct;
                $point['bar_px'] = max(14, (int) round(180 * ($heightPct / 100)));
            } else {
                $point['height_pct'] = 0;
                $point['bar_px'] = 4;
            }
        }
        unset($point);

        $dailyTrend = array_values($trendMap);
        $trendTotals = [
            'orders' => array_sum(array_column($dailyTrend, 'orders_count')),
            'revenue' => array_sum(array_column($dailyTrend, 'paid_revenue')),
        ];

        [$paymentBreakdown, $paymentBreakdownTotal] = $this->formatPaymentBreakdown($paymentGrouped, $totalRevenueSum);

        $stores = $scopedStoreId !== null
            ? FotocopyStore::where('id', $scopedStoreId)->get(['id', 'name'])
            : FotocopyStore::all(['id', 'name']);

        return view('features.fotocopy.admin.reports.index', compact(
            'orders',
            'stores',
            'totalOrdersCount',
            'totalRevenueSum',
            'dailyTrend',
            'maxTrendOrders',
            'trendTotals',
            'paymentBreakdown',
            'paymentBreakdownTotal'
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function initializeTrendMap(Carbon $nowWib): array
    {
        $trendMap = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $nowWib->copy()->subDays($i);
            $key = $d->format('Y-m-d');
            $trendMap[$key] = [
                'date' => $key,
                'date_label' => $d->format('d M'),
                'full_label' => $d->copy()->locale('id')->translatedFormat('l, d F Y'),
                'orders_count' => 0,
                'paid_revenue' => 0,
                'height_pct' => 0,
                'bar_px' => 4,
            ];
        }

        return $trendMap;
    }

    /**
     * Read pre-aggregated KPI, 14-day trend, and payment breakdown from mv_fotocopy_daily_revenue_14d.
     *
     * @return array{totalOrdersCount: int, totalRevenueSum: int, dailyStats: array<string, array{orders_count: int, paid_revenue: float}>, paymentGrouped: array<int, object>}|null
     */
    protected function aggregateFromMaterializedView(
        Request $request,
        ?int $scopedStoreId,
        int $expectedOrdersCount,
        Carbon $startDay
    ): ?array {
        try {
            $mvBase = DB::table('mv_fotocopy_daily_revenue_14d');
            $this->applyReportFilters($mvBase, $request, $scopedStoreId);

            $summary = (clone $mvBase)
                ->selectRaw('COALESCE(SUM(orders_count), 0) AS total_orders, COALESCE(SUM(paid_revenue), 0) AS paid_revenue')
                ->first();

            $mvOrdersCount = (int) ($summary->total_orders ?? 0);
            if ($mvOrdersCount !== $expectedOrdersCount) {
                return null;
            }

            $dailyRows = (clone $mvBase)
                ->where('order_date_wib', '>=', $startDay->format('Y-m-d'))
                ->groupBy('order_date_wib')
                ->selectRaw('order_date_wib AS order_date, SUM(orders_count) AS orders_count, COALESCE(SUM(paid_revenue), 0) AS paid_revenue')
                ->get();

            $dailyStats = [];
            foreach ($dailyRows as $r) {
                $dailyStats[(string) $r->order_date] = [
                    'orders_count' => (int) $r->orders_count,
                    'paid_revenue' => (float) $r->paid_revenue,
                ];
            }

            $paymentGrouped = (clone $mvBase)
                ->where('payment_status', 'paid')
                ->groupBy('payment_method')
                ->selectRaw('payment_method, SUM(paid_count) AS orders_count, COALESCE(SUM(paid_revenue), 0) AS revenue')
                ->get()
                ->all();

            return [
                'totalOrdersCount' => $mvOrdersCount,
                'totalRevenueSum' => (int) round((float) ($summary->paid_revenue ?? 0)),
                'dailyStats' => $dailyStats,
                'paymentGrouped' => $paymentGrouped,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function applyReportFilters(QueryBuilder $query, Request $request, ?int $scopedStoreId): void
    {
        if ($scopedStoreId !== null) {
            $query->where('store_id', $scopedStoreId);
        } elseif ($request->filled('store_id')) {
            $query->where('store_id', $request->input('store_id'));
        }

        if ($request->filled('order_status')) {
            $query->where('order_status', $request->input('order_status'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }
    }

    /**
     * @return array<string, array{orders_count: int, paid_revenue: float}>
     */
    protected function aggregateDailyStatsFromOrders($baseQuery, Carbon $startDay): array
    {
        $appTimezone = config('app.timezone', 'Asia/Jakarta');
        $dateExpr = DB::getDriverName() === 'pgsql'
            ? 'created_at::date'
            : 'DATE(created_at)';

        $rows = (clone $baseQuery)
            ->where('created_at', '>=', $startDay->copy()->timezone($appTimezone))
            ->groupBy(DB::raw($dateExpr))
            ->selectRaw("{$dateExpr} AS order_date, COUNT(*) AS orders_count, COALESCE(SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN total_price ELSE 0 END), 0) AS paid_revenue")
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row->order_date] = [
                'orders_count' => (int) $row->orders_count,
                'paid_revenue' => (float) $row->paid_revenue,
            ];
        }

        return $stats;
    }

    /**
     * @return array<int, object>
     */
    protected function aggregatePaymentBreakdownFromOrders($baseQuery): array
    {
        return (clone $baseQuery)
            ->where('payment_status', 'paid')
            ->groupBy('payment_method')
            ->selectRaw('payment_method, COUNT(*) AS orders_count, COALESCE(SUM(total_price), 0) AS revenue')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, object>  $groupedRows
     * @return array{0: array<string, array<string, mixed>>, 1: int}
     */
    protected function formatPaymentBreakdown(array $groupedRows, int $totalRevenueSum): array
    {
        $paymentBreakdown = [
            'qris' => ['label' => 'QRIS Dinamis', 'count' => 0, 'revenue' => 0, 'percentage' => 0.0],
            'bank_transfer' => ['label' => 'Transfer Bank', 'count' => 0, 'revenue' => 0, 'percentage' => 0.0],
            'cash' => ['label' => 'Tunai Kasir', 'count' => 0, 'revenue' => 0, 'percentage' => 0.0],
            'other' => ['label' => 'Lainnya', 'count' => 0, 'revenue' => 0, 'percentage' => 0.0],
        ];

        foreach ($groupedRows as $row) {
            $m = strtolower(trim((string) ($row->payment_method ?? '')));
            $bucket = match (true) {
                in_array($m, ['qris', 'qris_dinamis', 'qris dinamis'], true) => 'qris',
                in_array($m, ['bank_transfer', 'transfer', 'transfer bank', 'va', 'virtual_account'], true) => 'bank_transfer',
                in_array($m, ['cash', 'tunai', 'tunai kasir'], true) => 'cash',
                default => 'other',
            };
            $paymentBreakdown[$bucket]['count'] += (int) ($row->orders_count ?? 0);
            $paymentBreakdown[$bucket]['revenue'] += (int) round((float) ($row->revenue ?? 0));
        }

        if ($paymentBreakdown['other']['count'] === 0) {
            unset($paymentBreakdown['other']);
        }

        $paymentBreakdownTotal = (int) array_sum(array_column($paymentBreakdown, 'revenue'));
        foreach ($paymentBreakdown as &$stats) {
            $stats['percentage'] = $totalRevenueSum > 0
                ? round(($stats['revenue'] / $totalRevenueSum) * 100, 1)
                : 0.0;
        }
        unset($stats);

        return [$paymentBreakdown, $paymentBreakdownTotal];
    }
}
