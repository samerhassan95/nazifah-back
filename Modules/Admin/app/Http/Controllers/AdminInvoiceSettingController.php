<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Invoice\Services\InvoiceSettingsService;

class AdminInvoiceSettingController extends Controller
{
    public function __construct(
        private InvoiceSettingsService $invoiceSettingsService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $locale = $request->input('language', app()->getLocale());
            $message = $locale === 'ar'
                ? 'تم استرجاع إعدادات الفواتير بنجاح'
                : 'Invoice settings retrieved successfully';

            return successResponse($this->invoiceSettingsService->adminPayload(), $message);
        } catch (\Throwable) {
            $message = $request->input('language', app()->getLocale()) === 'ar'
                ? 'فشل في استرجاع إعدادات الفواتير'
                : 'Failed to retrieve invoice settings';

            return ErrorResponse::make($message, null, 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => ['required', 'array'],
            'settings.invoice_company_name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_company_name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_company_vat_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_company_registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_company_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'settings.invoice_auto_issue' => ['sometimes', 'boolean'],
            'settings.invoice_issue_cod' => ['sometimes', 'boolean'],
            'settings.invoice_public_link_ttl_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.invoice_currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'settings.invoice_zatca_enabled' => ['sometimes', 'boolean'],
            'settings.invoice_zatca_driver' => ['sometimes', 'string', 'in:http,mock'],
            'settings.invoice_zatca_environment' => ['sometimes', 'string', 'in:sandbox,production'],
            'settings.invoice_zatca_base_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'settings.invoice_zatca_submit_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_zatca_api_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.invoice_zatca_timeout' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'settings.invoice_whatsapp_enabled' => ['sometimes', 'boolean'],
            'settings.invoice_whatsapp_driver' => ['sometimes', 'string', 'in:http,mock'],
            'settings.invoice_whatsapp_base_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'settings.invoice_whatsapp_send_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_whatsapp_api_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.invoice_whatsapp_template' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_whatsapp_sender' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.invoice_whatsapp_timeout' => ['sometimes', 'integer', 'min:1', 'max:120'],
        ]);

        if ($validator->fails()) {
            $message = $request->input('language', app()->getLocale()) === 'ar'
                ? 'فشل التحقق من البيانات'
                : 'Validation failed';

            return ErrorResponse::make($message, $validator->errors(), 422);
        }

        try {
            $settings = $request->input('settings', []);
            $this->invoiceSettingsService->updateSettings($settings);

            $message = $request->input('language', app()->getLocale()) === 'ar'
                ? 'تم تحديث إعدادات الفواتير بنجاح'
                : 'Invoice settings updated successfully';

            return successResponse($this->invoiceSettingsService->adminPayload(), $message);
        } catch (\Throwable) {
            $message = $request->input('language', app()->getLocale()) === 'ar'
                ? 'فشل في تحديث إعدادات الفواتير'
                : 'Failed to update invoice settings';

            return ErrorResponse::make($message, null, 500);
        }
    }
}
