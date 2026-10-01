<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

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

        return response()->json($data);
    }

    public function products(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $data = $this->reportService->getProductPopularity($dateFrom, $dateTo);

        return response()->json(['data' => $data]);
    }

    public function dashboard(Request $request)
    {
        $data = $this->reportService->getDashboardMetrics();

        return response()->json($data);
    }
}
