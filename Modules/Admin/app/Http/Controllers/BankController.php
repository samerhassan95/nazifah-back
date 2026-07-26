<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\Bank;

class BankController extends Controller
{
    protected UploadFilesService $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * List banks (dashboard).
     * GET /api/v1/admin/banks
     */
    public function index(Request $request): JsonResponse
    {
        $query = Bank::query()->orderBy('created_at', 'desc');

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $banks = $query->paginate($request->input('per_page', 15));

        $banks->getCollection()->transform(fn (Bank $bank) => $this->present($bank));

        return successResponse($banks, __('bank.banks_retrieved'));
    }

    /**
     * Create a bank.
     * POST /api/v1/admin/banks
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required_without_all:name.ar,name.en'],
            'name.ar' => ['required_without:name', 'string', 'max:255'],
            'name.en' => ['required_without:name', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,svg,webp', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $this->uploadFilesService->uploadImage($request->file('logo'), 'banks/logos');
        }

        $bank = Bank::create([
            'name' => $this->normalizeName($request->input('name')),
            'logo' => $logoPath,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return successResponse($this->present($bank), __('bank.bank_created'), 201);
    }

    /**
     * Show a bank.
     * GET /api/v1/admin/banks/{id}
     */
    public function show(int $id): JsonResponse
    {
        $bank = Bank::find($id);

        if (! $bank) {
            return notFoundResponse(__('bank.bank_not_found'));
        }

        return successResponse($this->present($bank), __('bank.bank_retrieved'));
    }

    /**
     * Update a bank.
     * PUT /api/v1/admin/banks/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $bank = Bank::find($id);

        if (! $bank) {
            return notFoundResponse(__('bank.bank_not_found'));
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required_without_all:name.ar,name.en'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,svg,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        if ($request->has('name')) {
            $bank->name = $this->normalizeName($request->input('name'));
        }

        if ($request->hasFile('logo')) {
            $bank->logo = $this->uploadFilesService->uploadImage(
                $request->file('logo'),
                'banks/logos',
                $bank->getRawOriginal('logo')
            );
        }

        if ($request->has('is_active')) {
            $bank->is_active = $request->boolean('is_active');
        }

        $bank->save();

        return successResponse($this->present($bank), __('bank.bank_updated'));
    }

    /**
     * Delete a bank.
     * DELETE /api/v1/admin/banks/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $bank = Bank::find($id);

        if (! $bank) {
            return notFoundResponse(__('bank.bank_not_found'));
        }

        if ($bank->logo) {
            $this->uploadFilesService->deleteFile($bank->logo);
        }

        $bank->delete();

        return successResponse(null, __('bank.bank_deleted'));
    }

    /**
     * Toggle active status.
     * POST /api/v1/admin/banks/{id}/toggle-status
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $bank = Bank::find($id);

        if (! $bank) {
            return notFoundResponse(__('bank.bank_not_found'));
        }

        $bank->is_active = ! $bank->is_active;
        $bank->save();

        return successResponse($this->present($bank), __('bank.bank_status_updated'));
    }

    /**
     * Normalize the name into a translatable {ar, en} array.
     *
     * @param  string|array  $name
     */
    private function normalizeName($name): array
    {
        if (is_array($name)) {
            return [
                'ar' => $name['ar'] ?? $name['en'] ?? '',
                'en' => $name['en'] ?? $name['ar'] ?? '',
            ];
        }

        return ['ar' => $name, 'en' => $name];
    }

    private function present(Bank $bank): array
    {
        return [
            'id' => $bank->id,
            'name' => $bank->getTranslations('name'),
            'logo' => $this->uploadFilesService->getFullUrl($bank->logo),
            'is_active' => (bool) $bank->is_active,
            'created_at' => $bank->created_at?->toISOString(),
        ];
    }
}
