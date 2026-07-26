<?php

namespace Modules\Admin\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Admin\Services\ReportService;

class AdminReportController extends Controller
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get detailed order statistics for the Orders dashboard screen
     */
    public function ordersReport(): JsonResponse
    {
        try {
            $orderStatistics = $this->reportService->getOrderStatistics();

            return response()->json([
                'success' => true,
                'data' => $orderStatistics,
                'message' => 'Order statistics retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving order statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed vendor/laundry statistics for the Laundries dashboard screen
     */
    public function vendorReport(): JsonResponse
    {
        try {
            $vendorStatistics = $this->reportService->getVendorStatistics();

            return response()->json([
                'success' => true,
                'data' => $vendorStatistics,
                'message' => 'Vendor statistics retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving vendor statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed client statistics for the Clients dashboard screen
     */
    public function clientReport(): JsonResponse
    {
        try {
            $clientStatistics = $this->reportService->getClientStatistics();

            return response()->json([
                'success' => true,
                'data' => $clientStatistics,
                'message' => 'Client statistics retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving client statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
