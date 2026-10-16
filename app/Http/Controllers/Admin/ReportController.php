<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function sales(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $data = $this->reportService->getSalesSummary($dateFrom, $dateTo);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function products(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $data = $this->reportService->getProductPopularity($dateFrom, $dateTo);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function dashboard(Request $request)
    {

        $data = $this->reportService->getDashboardMetrics();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function dailyOrders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
            'order_session_id' => ['sometimes', 'integer', 'exists:order_sessions,id'],
            'status' => ['sometimes', 'string', Rule::in(['submitted', 'cancelled'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if ($request->filled('date_from')
                && $request->filled('date_to')
                && $request->input('date_to') < $request->input('date_from')) {
                $validator->errors()->add('date_to', 'The date to must be on or after the date from.');
            }
        });
        $filters = $validator->validate();
        $timezone = config('app.business_timezone');
        $orders = $this->reportService->getDetailedOrdersReport($filters, $timezone);

        return response()->json(array_merge($orders->toArray(), [
            'filters' => [
                'date_from' => $filters['date_from'] ?? (! isset($filters['date_to'])
                    ? Carbon::now($timezone)->toDateString()
                    : null),
                'date_to' => $filters['date_to'] ?? (! isset($filters['date_from'])
                    ? Carbon::now($timezone)->toDateString()
                    : null),
                'order_session_id' => $filters['order_session_id'] ?? null,
                'status' => $filters['status'] ?? null,
                'search' => $filters['search'] ?? null,
                'timezone' => $timezone,
            ],
        ]));
    }
}
