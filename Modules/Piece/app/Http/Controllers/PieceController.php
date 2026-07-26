<?php

namespace Modules\Piece\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Piece\Http\Requests\StorePieceRequest;
use Modules\Piece\Http\Requests\UpdatePieceRequest;
use Modules\Piece\Http\Resources\PieceResource;
use Modules\Piece\Services\PieceService;

class PieceController extends Controller
{
    public function __construct(private PieceService $pieceService) {}

    public function index(): JsonResponse
    {
        $pieces = $this->pieceService->getAllPieces();

        return successResponse(
            PieceResource::collection($pieces),
            'Pieces retrieved successfully'
        );
    }

    public function store(StorePieceRequest $request): JsonResponse
    {
        $piece = $this->pieceService->createPiece($request->validated());

        return successResponse(new PieceResource($piece), 'Piece created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $piece = $this->pieceService->getPieceById($id);
        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        return successResponse(new PieceResource($piece), 'Piece retrieved successfully');
    }

    public function update(UpdatePieceRequest $request, int $id): JsonResponse
    {
        $piece = $this->pieceService->updatePiece($id, $request->validated());
        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        return successResponse(new PieceResource($piece), 'Piece updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->pieceService->deletePiece($id);
        if (! $deleted) {
            return notFoundResponse('Piece not found');
        }

        return successResponse(null, 'Piece deleted successfully');
    }

    /**
     * Get all services related to this piece (including service additions)
     * Optionally filter by branch_id query parameter
     */
    public function getServices(int $id): JsonResponse
    {
        // Get branch_id from query parameter
        $branchId = request()->query('branch_id');

        $services = $this->pieceService->getServicesByPiece($id, $branchId);

        if ($services === null) {
            if ($branchId) {
                return notFoundResponse('Piece not found or not available at this branch');
            }

            return notFoundResponse('Piece not found');
        }

        return successResponse($services, 'Services retrieved successfully');
    }
}
