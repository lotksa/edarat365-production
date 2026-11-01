<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\Voucher;
use App\Services\AssociationBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecordDocumentController extends Controller
{
    private const MODULES = [
        'invoices' => [Invoice::class, InvoicePdfController::class, 'invoices.view'],
        'contracts' => [Contract::class, ContractController::class, 'contracts.view'],
        'vouchers' => [Voucher::class, VoucherController::class, 'vouchers.view'],
        'maintenance' => [MaintenanceRequest::class, MaintenanceController::class, 'maintenance.view'],
        'legal-cases' => [LegalCase::class, LegalCaseController::class, 'legal_cases.view'],
        'meetings' => [Meeting::class, MeetingController::class, 'meetings.view'],
    ];

    public function show(Request $request, string $module, int $id, AssociationBranding $branding): JsonResponse
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        [$model, $controller, $permission] = self::MODULES[$module];
        abort_unless($request->user()->isSuperAdmin() || $request->user()->hasPermission($permission), 403);
        $record = $model::findOrFail($id);
        $payload = app($controller)->show($id)->getData(true);

        return response()->json(['module' => $module, 'data' => $module === 'invoices' ? $payload : $payload['data'],
            'branding' => $branding->forRecord($record)]);
    }
}
