<?php

namespace Modules\Driver\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $uploadService = app(UploadFilesService::class);
        $lang = app()->getLocale();

        return [
            'id' => $this->id,
            'piece' => $this->when($this->piece, [
                'id' => $this->piece?->id,
                'name' => method_exists($this->piece, 'getTranslation')
                    ? $this->piece?->getTranslation('name', $lang)
                    : $this->piece?->name,
                'icon' => $uploadService->getFullUrl($this->piece?->icon),
            ]),
            'service' => $this->when($this->service, [
                'id' => $this->service?->id,
                'name' => method_exists($this->service, 'getTranslation')
                    ? $this->service?->getTranslation('service_name', $lang)
                    : $this->service?->service_name,
            ]),
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'additional_services' => $this->additional_services ?? [],
        ];
    }
}
