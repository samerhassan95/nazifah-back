<?php

namespace Modules\Client\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Modules\Client\Http\Requests\StoreClientRequest;
use Modules\Client\Http\Requests\UpdateClientRequest;
use Modules\Client\Http\Resources\ClientResource;
use Modules\Client\Services\ClientService;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clientService
    ) {}

    public function index(): JsonResponse
    {
        $clients = $this->clientService->getAllClients();

        return successResponse(
            ClientResource::collection($clients),
            __('client::client.clients')
        );
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = $this->clientService->createClient($request->validated());

        return successResponse(
            new ClientResource($client),
            __('client::client.created_successfully'),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $client = $this->clientService->getClientById($id);

        if (! $client) {
            return ErrorResponse::make(__('client::client.not_found'), null, 404);
        }

        return successResponse(
            new ClientResource($client),
            __('client::client.client')
        );
    }

    public function update(UpdateClientRequest $request, int $id): JsonResponse
    {
        $client = $this->clientService->updateClient($id, $request->validated());

        if (! $client) {
            return ErrorResponse::make(__('client::client.not_found'), null, 404);
        }

        return successResponse(
            new ClientResource($client),
            __('client::client.updated_successfully')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->clientService->deleteClient($id);

        if (! $deleted) {
            return ErrorResponse::make(__('client::client.not_found'), null, 404);
        }

        return successResponse(
            null,
            __('client::client.deleted_successfully')
        );
    }
}
