<?php

namespace Modules\Vendor\Http\Controllers\Api\V1\LaundryDashboard;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusLog;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Vendor\Support\LaundryDashboardResponse;

/**
 * Laundry Dashboard → Overview (single aggregated endpoint)
 * GET /api/v1/vendor/laundry-dashboard/overview?year=2026
 *
 * Scope: all branches of the authenticated vendor. Time zone: app timezone (Asia/Riyadh).
 *
 * Order buckets (used by the doughnut, evolution chart and "ongoing"):
 *   new         = pending
 *   completed   = completed, delivered
 *   cancelled   = cancelled
 *   in_progress = every other status
 *   ongoing     = new + in_progress (anything not finished)
 */
class OverviewController extends Controller
{
    use LaundryDashboardResponse;

    private const COMPLETED = [OrderStatus::COMPLETED->value, OrderStatus::DELIVERED->value];

    private const CANCELLED = [OrderStatus::CANCELLED->value];

    private const NEW = [OrderStatus::PENDING->value];

    private const WEEKDAYS = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    public function index(Request $request): JsonResponse
    {
        $validator = $this->makeValidator($request->all(), [
            'year' => ['nullable', 'integer', 'min:2000', 'max:'.((int) now()->year + 1)],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $year = (int) ($request->input('year') ?: now()->year);
        $branchIds = Branch::where('vendor_id', $this->vendorId())->pluck('id');

        $data = [
            'year' => $year,
            'stats' => $this->stats($branchIds),
            'orders_evolution' => $this->ordersEvolution($branchIds, $year),
            'top_branches' => $this->topBranches($branchIds, $year),
            'order_statuses' => $this->orderStatuses($branchIds),
            'monthly_revenue' => $this->monthlyRevenue($branchIds, $year),
            'recent_activities' => $this->recentActivities($branchIds),
        ];

        return $this->ok($data, 'تم جلب بيانات لوحة التحكم بنجاح', 'Dashboard overview retrieved successfully');
    }

    // ------------------------------------------------------------------

    private function orders($branchIds)
    {
        return Order::query()->whereIn('branch_id', $branchIds);
    }

    private function bucket(string $status): string
    {
        return match (true) {
            in_array($status, self::NEW, true) => 'new',
            in_array($status, self::COMPLETED, true) => 'completed',
            in_array($status, self::CANCELLED, true) => 'cancelled',
            default => 'in_progress',
        };
    }

    private function stats($branchIds): array
    {
        // Total + ongoing (single grouped query)
        $byStatus = $this->orders($branchIds)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $total = (int) $byStatus->sum();
        $ongoing = (int) $byStatus->filter(fn ($c, $status) => in_array($this->bucket($status), ['new', 'in_progress'], true))->sum();

        // Last 6 months trend (current month included)
        $from = now()->startOfMonth()->subMonths(5);
        $trendRaw = $this->orders($branchIds)
            ->where('created_at', '>=', $from)
            ->select(DB::raw('YEAR(created_at) as y'), DB::raw('MONTH(created_at) as m'), DB::raw('COUNT(*) as c'))
            ->groupBy('y', 'm')
            ->get()
            ->keyBy(fn ($r) => $r->y.'-'.$r->m);

        $trend = [];
        for ($i = 0; $i < 6; $i++) {
            $d = $from->copy()->addMonths($i);
            $trend[] = [
                'month' => $d->month,
                'value' => (int) ($trendRaw[$d->year.'-'.$d->month]->c ?? 0),
            ];
        }

        // Today + current week (Saturday → Friday)
        $weekStart = now()->startOfWeek(Carbon::SATURDAY);
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
        $perDay = $this->orders($branchIds)
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
            ->groupBy('d')
            ->pluck('c', 'd');

        $byWeekday = [];
        foreach (self::WEEKDAYS as $i => $weekday) {
            $date = $weekStart->copy()->addDays($i)->toDateString();
            $byWeekday[] = ['weekday' => $weekday, 'value' => (int) ($perDay[$date] ?? 0)];
        }

        return [
            'total_orders' => ['value' => $total, 'monthly_trend' => $trend],
            'today_orders' => [
                'value' => (int) ($perDay[now()->toDateString()] ?? 0),
                'by_weekday' => $byWeekday,
            ],
            'ongoing_orders' => ['value' => $ongoing],
        ];
    }

    /** 12 months: orders created that month, split by their current outcome. */
    private function ordersEvolution($branchIds, int $year): array
    {
        $rows = $this->orders($branchIds)
            ->whereBetween('created_at', [Carbon::create($year, 1, 1)->startOfDay(), Carbon::create($year, 12, 31)->endOfDay()])
            ->select(DB::raw('MONTH(created_at) as m'), 'status', DB::raw('COUNT(*) as c'))
            ->groupBy('m', 'status')
            ->get();

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => $m, 'new' => 0, 'completed' => 0, 'cancelled' => 0];
        }

        foreach ($rows as $row) {
            $months[(int) $row->m]['new'] += (int) $row->c; // every order created in the month
            $bucket = $this->bucket($row->status);
            if ($bucket === 'completed' || $bucket === 'cancelled') {
                $months[(int) $row->m][$bucket] += (int) $row->c;
            }
        }

        return array_values($months);
    }

    private function topBranches($branchIds, int $year): array
    {
        $counts = $this->orders($branchIds)
            ->whereBetween('created_at', [Carbon::create($year, 1, 1)->startOfDay(), Carbon::create($year, 12, 31)->endOfDay()])
            ->select('branch_id', DB::raw('COUNT(*) as c'))
            ->groupBy('branch_id')
            ->orderByDesc('c')
            ->limit(4)
            ->pluck('c', 'branch_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $branches = Branch::whereIn('id', $counts->keys())->get()->keyBy('id');

        return $counts->map(fn ($count, $branchId) => [
            'branch_id' => (int) $branchId,
            'name' => $this->bilingual($branches->get($branchId)?->getTranslations('name') ?? ''),
            'orders_count' => (int) $count,
        ])->values()->all();
    }

    /** All-time breakdown (matches the "total orders" card). */
    private function orderStatuses($branchIds): array
    {
        $byStatus = $this->orders($branchIds)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $counts = ['completed' => 0, 'in_progress' => 0, 'new' => 0, 'cancelled' => 0];
        foreach ($byStatus as $status => $c) {
            $counts[$this->bucket($status)] += (int) $c;
        }

        $names = [
            'completed' => ['ar' => 'مكتملة', 'en' => 'Completed'],
            'in_progress' => ['ar' => 'قيد التنفيذ', 'en' => 'In progress'],
            'new' => ['ar' => 'جديدة', 'en' => 'New'],
            'cancelled' => ['ar' => 'ملغية', 'en' => 'Cancelled'],
        ];

        $items = [];
        foreach ($counts as $key => $count) {
            $items[] = ['key' => $key, 'name' => $names[$key], 'count' => $count];
        }

        return ['total' => array_sum($counts), 'items' => $items];
    }

    /** Revenue = completed payment transactions on this vendor's orders, in SAR, grouped by payment month. */
    private function monthlyRevenue($branchIds, int $year): array
    {
        $dateExpr = 'COALESCE(payment_transactions.paid_at, payment_transactions.created_at)';

        $rows = PaymentTransaction::query()
            ->where('payment_transactions.status', 'completed')
            ->whereHas('order', fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereRaw("$dateExpr BETWEEN ? AND ?", [
                Carbon::create($year, 1, 1)->startOfDay()->toDateTimeString(),
                Carbon::create($year, 12, 31)->endOfDay()->toDateTimeString(),
            ])
            ->select(DB::raw("MONTH($dateExpr) as m"), DB::raw('SUM(payment_transactions.amount) as total'))
            ->groupBy('m')
            ->pluck('total', 'm');

        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $result[] = ['month' => $m, 'revenue' => (float) ($rows[$m] ?? 0)];
        }

        return $result;
    }

    /** Latest order status changes across the vendor's branches, newest first. */
    private function recentActivities($branchIds): array
    {
        return OrderStatusLog::query()
            ->with('order:id,order_number,branch_id')
            ->whereHas('order', fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(function (OrderStatusLog $log) {
                $status = OrderStatus::tryFrom($log->status);
                $labelAr = $status?->labelAr() ?? $log->status;
                $labelEn = $status?->label() ?? $log->status;
                $number = $log->order?->order_number;

                return [
                    'id' => $log->id,
                    'type' => 'order_'.$log->status,
                    'order_id' => $log->order_id,
                    'order_number' => $number !== null ? (string) $number : null,
                    'title' => ['ar' => $labelAr, 'en' => $labelEn],
                    'message' => [
                        'ar' => "الطلب رقم {$number}: {$labelAr}",
                        'en' => "Order #{$number}: {$labelEn}",
                    ],
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
