<?php

use App\Http\Controllers\Api\AssociationController;
use App\Http\Controllers\Api\AssociationManagerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OwnerController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PropertyManagerController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\LegalCaseController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoicePdfController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\VoteController;
use App\Http\Controllers\Api\ParkingSpotController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\ExportImportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\PersonSearchController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\LegalRepresentativeController;
use App\Http\Controllers\Api\CasePermissionController;
use App\Http\Controllers\Api\CaseMessageController;
use App\Http\Controllers\Api\CaseReminderController;
use App\Http\Controllers\Api\MaintenanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'edarat365-api',
    ]));

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/request-otp', [AuthController::class, 'requestOtp']);
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AccountController::class, 'me']);
        Route::put('/account/profile', [AccountController::class, 'updateProfile']);
    });

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Global Search
    Route::get('/search', [GlobalSearchController::class, 'search']);
    Route::get('/search/ai', [GlobalSearchController::class, 'aiSearch']);

    // Facilities Management
    Route::get('/facilities/stats', [FacilityController::class, 'stats']);
    Route::get('/facilities', [FacilityController::class, 'index']);
    Route::get('/facilities/{id}', [FacilityController::class, 'show']);
    Route::post('/facilities', [FacilityController::class, 'store']);
    Route::put('/facilities/{id}', [FacilityController::class, 'update']);
    Route::delete('/facilities/{id}', [FacilityController::class, 'destroy']);
    Route::get('/facilities/{id}/availability', [FacilityController::class, 'availability']);
    Route::post('/facilities/{id}/book', [FacilityController::class, 'book']);
    Route::get('/facilities/{id}/bookings', [FacilityController::class, 'bookings']);
    Route::get('/bookings/stats', [FacilityController::class, 'bookingStats']);
    Route::get('/bookings', [FacilityController::class, 'allBookings']);
    Route::post('/bookings/{id}/cancel', [FacilityController::class, 'cancelBooking']);
    Route::get('/associations/{id}/bookings', [FacilityController::class, 'associationBookings']);

    // Maintenance Management
    Route::get('/maintenance/stats', [MaintenanceController::class, 'stats']);
    Route::get('/maintenance', [MaintenanceController::class, 'index']);
    Route::get('/maintenance/{id}', [MaintenanceController::class, 'show']);
    Route::post('/maintenance', [MaintenanceController::class, 'store']);
    Route::put('/maintenance/{id}', [MaintenanceController::class, 'update']);
    Route::patch('/maintenance/{id}/status', [MaintenanceController::class, 'updateStatus']);
    Route::delete('/maintenance/{id}', [MaintenanceController::class, 'destroy']);

    // AI
    Route::post('/ai/chat', [AiController::class, 'chat']);
    Route::get('/ai/insights', [AiController::class, 'insights']);
    Route::post('/ai/suggest', [AiController::class, 'suggest']);

    Route::get('/settings/{key}', [SettingsController::class, 'show']);
    Route::put('/settings/{key}', [SettingsController::class, 'update']);

    Route::get('/owners/stats', [OwnerController::class, 'stats']);
    Route::get('/owners/export', [OwnerController::class, 'export']);
    Route::get('/owners/import-template', [OwnerController::class, 'importTemplate']);
    Route::post('/owners/import', [OwnerController::class, 'import']);
    Route::get('/owners', [OwnerController::class, 'index']);
    Route::post('/owners', [OwnerController::class, 'store']);
    Route::post('/owners/bulk-delete', [OwnerController::class, 'bulkDelete']);
    Route::patch('/owners/{id}/toggle-status', [OwnerController::class, 'toggleStatus']);
    Route::get('/owners/{id}', [OwnerController::class, 'show']);
    Route::put('/owners/{id}', [OwnerController::class, 'update']);
    Route::delete('/owners/{id}', [OwnerController::class, 'destroy']);

    // Association Managers
    Route::get('/association-managers/stats', [AssociationManagerController::class, 'stats']);
    Route::get('/association-managers', [AssociationManagerController::class, 'index']);
    Route::post('/association-managers', [AssociationManagerController::class, 'store']);
    Route::post('/association-managers/bulk-delete', [AssociationManagerController::class, 'bulkDelete']);
    Route::patch('/association-managers/{id}/toggle-status', [AssociationManagerController::class, 'toggleStatus']);
    Route::get('/association-managers/{id}', [AssociationManagerController::class, 'show']);
    Route::put('/association-managers/{id}', [AssociationManagerController::class, 'update']);
    Route::delete('/association-managers/{id}', [AssociationManagerController::class, 'destroy']);

    // Associations
    Route::get('/associations/stats', [AssociationController::class, 'stats']);
    Route::get('/associations', [AssociationController::class, 'index']);
    Route::post('/associations', [AssociationController::class, 'store']);
    Route::post('/associations/bulk-delete', [AssociationController::class, 'bulkDelete']);
    Route::post('/associations/bulk-status', [AssociationController::class, 'bulkUpdateStatus']);
    Route::patch('/associations/{id}/toggle-status', [AssociationController::class, 'toggleStatus']);
    Route::get('/associations/{id}', [AssociationController::class, 'show']);
    Route::put('/associations/{id}', [AssociationController::class, 'update']);
    Route::delete('/associations/{id}', [AssociationController::class, 'destroy']);

    // Property Managers
    Route::get('/property-managers/stats', [PropertyManagerController::class, 'stats']);
    Route::get('/property-managers', [PropertyManagerController::class, 'index']);
    Route::post('/property-managers', [PropertyManagerController::class, 'store']);
    Route::post('/property-managers/bulk-delete', [PropertyManagerController::class, 'bulkDelete']);
    Route::patch('/property-managers/{id}/toggle-status', [PropertyManagerController::class, 'toggleStatus']);
    Route::get('/property-managers/{id}', [PropertyManagerController::class, 'show']);
    Route::put('/property-managers/{id}', [PropertyManagerController::class, 'update']);
    Route::delete('/property-managers/{id}', [PropertyManagerController::class, 'destroy']);

    // Properties
    Route::get('/properties/stats', [PropertyController::class, 'stats']);
    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::post('/properties/bulk-delete', [PropertyController::class, 'bulkDelete']);
    Route::post('/properties/bulk-status', [PropertyController::class, 'bulkUpdateStatus']);
    Route::get('/properties/{id}', [PropertyController::class, 'show']);
    Route::put('/properties/{id}', [PropertyController::class, 'update']);
    Route::delete('/properties/{id}', [PropertyController::class, 'destroy']);

    // Property Related Data
    Route::get('/properties/{id}/related/association', [PropertyController::class, 'relatedAssociation']);
    Route::get('/properties/{id}/related/units', [PropertyController::class, 'relatedUnits']);
    Route::get('/properties/{id}/related/owners', [PropertyController::class, 'relatedOwners']);
    Route::get('/properties/{id}/related/facilities', [PropertyController::class, 'relatedFacilities']);
    Route::get('/properties/{id}/related/maintenance', [PropertyController::class, 'relatedMaintenance']);
    Route::get('/properties/{id}/related/contracts', [PropertyController::class, 'relatedContracts']);
    Route::get('/properties/{id}/related/meetings', [PropertyController::class, 'relatedMeetings']);
    Route::get('/properties/{id}/related/legal-cases', [PropertyController::class, 'relatedLegalCases']);
    Route::get('/properties/{id}/related/approvals', [PropertyController::class, 'relatedApprovals']);

    // Property Utility Meters & Documents
    Route::get('/properties/{propertyId}/meters', [PropertyController::class, 'listUtilityMeters']);
    Route::post('/properties/{propertyId}/meters', [PropertyController::class, 'storeUtilityMeter']);
    Route::put('/properties/{propertyId}/meters/{meterId}', [PropertyController::class, 'updateUtilityMeter']);
    Route::delete('/properties/{propertyId}/meters/{meterId}', [PropertyController::class, 'destroyUtilityMeter']);
    Route::post('/properties/{propertyId}/documents', [PropertyController::class, 'storeDocument']);
    Route::delete('/properties/{propertyId}/documents/{docId}', [PropertyController::class, 'destroyDocument']);
    Route::post('/properties/{propertyId}/documents/bulk-delete', [PropertyController::class, 'bulkDeleteDocuments']);
    Route::post('/properties/{propertyId}/components', [PropertyController::class, 'storeComponents']);

    // Units
    Route::get('/units/stats', [UnitController::class, 'stats']);
    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);
    Route::post('/units/bulk-delete', [UnitController::class, 'bulkDelete']);
    Route::post('/units/bulk-status', [UnitController::class, 'bulkUpdateStatus']);
    Route::get('/units/{id}', [UnitController::class, 'show']);
    Route::put('/units/{id}', [UnitController::class, 'update']);
    Route::delete('/units/{id}', [UnitController::class, 'destroy']);
    Route::patch('/units/{id}/toggle-status', [UnitController::class, 'toggleStatus']);
    Route::post('/units/{unitId}/components', [UnitController::class, 'storeComponents']);
    Route::post('/units/{unitId}/owners', [UnitController::class, 'syncOwners']);
    Route::post('/units/{unitId}/images', [UnitController::class, 'uploadImages']);
    Route::put('/units/{unitId}/images/{imageId}', [UnitController::class, 'updateImage']);
    Route::delete('/units/{unitId}/images/{imageId}', [UnitController::class, 'deleteImage']);

    // Unified Person Search (owners, tenants, managers, heads)
    Route::get('/people/search', [PersonSearchController::class, 'search']);

    // Activity Logs
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);

    // Tenants
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{id}', [TenantController::class, 'show']);
    Route::put('/tenants/{id}', [TenantController::class, 'update']);
    Route::delete('/tenants/{id}', [TenantController::class, 'destroy']);

    // Contracts (dedicated)
    Route::get('/contracts/stats', [ContractController::class, 'stats']);
    Route::get('/contracts/clauses', [ContractController::class, 'clauses']);
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::post('/contracts', [ContractController::class, 'store']);
    Route::get('/contracts/{id}', [ContractController::class, 'show']);
    Route::put('/contracts/{id}', [ContractController::class, 'update']);
    Route::delete('/contracts/{id}', [ContractController::class, 'destroy']);
    Route::patch('/contracts/{id}/terminate', [ContractController::class, 'terminate']);

    // Cities & Districts
    Route::get('/cities/export', [CityController::class, 'export']);
    Route::get('/cities/import-template', [CityController::class, 'importTemplate']);
    Route::post('/cities/import', [CityController::class, 'import']);
    Route::get('/cities', [CityController::class, 'index']);
    Route::post('/cities', [CityController::class, 'store']);
    Route::get('/cities/{id}', [CityController::class, 'show']);
    Route::put('/cities/{id}', [CityController::class, 'update']);
    Route::delete('/cities/{id}', [CityController::class, 'destroy']);
    Route::get('/cities/{cityId}/districts/export', [CityController::class, 'exportDistricts']);
    Route::get('/cities/{cityId}/districts/import-template', [CityController::class, 'districtsImportTemplate']);
    Route::post('/cities/{cityId}/districts/import', [CityController::class, 'importDistricts']);
    Route::get('/cities/{cityId}/districts', [CityController::class, 'districts']);
    Route::post('/cities/{cityId}/districts', [CityController::class, 'storeDistrict']);
    Route::put('/cities/{cityId}/districts/{districtId}', [CityController::class, 'updateDistrict']);
    Route::delete('/cities/{cityId}/districts/{districtId}', [CityController::class, 'destroyDistrict']);

    // Meetings (dedicated)
    Route::get('/meetings/stats', [MeetingController::class, 'stats']);
    Route::get('/meetings', [MeetingController::class, 'index']);
    Route::post('/meetings', [MeetingController::class, 'store']);
    Route::get('/meetings/{id}', [MeetingController::class, 'show']);
    Route::put('/meetings/{id}', [MeetingController::class, 'update']);
    Route::delete('/meetings/{id}', [MeetingController::class, 'destroy']);
    Route::post('/meetings/{id}/invite', [MeetingController::class, 'invite']);
    Route::put('/meetings/{id}/attendance', [MeetingController::class, 'updateAttendance']);
    Route::get('/meetings/{meetingId}/resolutions', [MeetingController::class, 'resolutions']);
    Route::post('/meetings/{meetingId}/resolutions', [MeetingController::class, 'storeResolution']);
    Route::put('/meetings/{meetingId}/resolutions/{resolutionId}', [MeetingController::class, 'updateResolution']);
    Route::delete('/meetings/{meetingId}/resolutions/{resolutionId}', [MeetingController::class, 'destroyResolution']);

    // Legal Cases (dedicated)
    Route::get('/legal-cases/stats', [LegalCaseController::class, 'stats']);
    Route::post('/legal-cases/upload-document', [LegalCaseController::class, 'uploadDocument']);
    Route::get('/legal-cases', [LegalCaseController::class, 'index']);
    Route::post('/legal-cases', [LegalCaseController::class, 'store']);
    Route::get('/legal-cases/{caseId}/updates', [LegalCaseController::class, 'updates']);
    Route::post('/legal-cases/{caseId}/updates', [LegalCaseController::class, 'storeUpdate']);
    Route::put('/legal-cases/{caseId}/updates/{updateId}', [LegalCaseController::class, 'updateUpdate']);
    Route::delete('/legal-cases/{caseId}/updates/{updateId}', [LegalCaseController::class, 'destroyUpdate']);
    Route::get('/legal-cases/{id}', [LegalCaseController::class, 'show']);
    Route::put('/legal-cases/{id}', [LegalCaseController::class, 'update']);
    Route::delete('/legal-cases/{id}', [LegalCaseController::class, 'destroy']);

    // Legal Cases -- Permissions
    Route::get('/legal-cases/{caseId}/permissions', [CasePermissionController::class, 'index']);
    Route::post('/legal-cases/{caseId}/permissions', [CasePermissionController::class, 'store']);
    Route::put('/legal-cases/{caseId}/permissions/{permId}', [CasePermissionController::class, 'update']);
    Route::delete('/legal-cases/{caseId}/permissions/{permId}', [CasePermissionController::class, 'destroy']);

    // Legal Cases -- Messages
    Route::get('/legal-cases/{caseId}/messages', [CaseMessageController::class, 'index']);
    Route::post('/legal-cases/{caseId}/messages', [CaseMessageController::class, 'store']);
    Route::post('/legal-cases/{caseId}/messages/upload', [CaseMessageController::class, 'uploadAttachment']);
    Route::patch('/legal-cases/{caseId}/messages/{msgId}/pin', [CaseMessageController::class, 'pin']);
    Route::post('/legal-cases/{caseId}/messages/mark-read', [CaseMessageController::class, 'markRead']);

    // Legal Cases -- Reminders
    Route::get('/legal-cases/{caseId}/reminders', [CaseReminderController::class, 'index']);
    Route::post('/legal-cases/{caseId}/reminders', [CaseReminderController::class, 'store']);
    Route::put('/legal-cases/{caseId}/reminders/{remId}', [CaseReminderController::class, 'update']);
    Route::patch('/legal-cases/{caseId}/reminders/{remId}/dismiss', [CaseReminderController::class, 'dismiss']);
    Route::delete('/legal-cases/{caseId}/reminders/{remId}', [CaseReminderController::class, 'destroy']);

    // Legal Representatives
    Route::get('/legal-representatives/stats', [LegalRepresentativeController::class, 'stats']);
    Route::get('/legal-representatives', [LegalRepresentativeController::class, 'index']);
    Route::post('/legal-representatives', [LegalRepresentativeController::class, 'store']);
    Route::get('/legal-representatives/{id}', [LegalRepresentativeController::class, 'show']);
    Route::put('/legal-representatives/{id}', [LegalRepresentativeController::class, 'update']);
    Route::delete('/legal-representatives/{id}', [LegalRepresentativeController::class, 'destroy']);

    // Invoices
    Route::get('/invoices/stats', [InvoiceController::class, 'stats']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
    Route::get('/invoices/{id}/pdf-data', [InvoicePdfController::class, 'show']);

    // Vouchers (Receipts & Payments)
    Route::get('/vouchers/stats', [VoucherController::class, 'stats']);
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::post('/vouchers', [VoucherController::class, 'store']);
    Route::get('/vouchers/{id}', [VoucherController::class, 'show']);
    Route::put('/vouchers/{id}', [VoucherController::class, 'update']);
    Route::delete('/vouchers/{id}', [VoucherController::class, 'destroy']);

    // Parking Spots
    Route::get('/parking-spots/stats', [ParkingSpotController::class, 'stats']);
    Route::get('/parking-spots', [ParkingSpotController::class, 'index']);
    Route::post('/parking-spots', [ParkingSpotController::class, 'store']);
    Route::get('/parking-spots/{id}', [ParkingSpotController::class, 'show']);
    Route::put('/parking-spots/{id}', [ParkingSpotController::class, 'update']);
    Route::delete('/parking-spots/{id}', [ParkingSpotController::class, 'destroy']);

    // Vehicles
    Route::get('/vehicles/stats', [VehicleController::class, 'stats']);
    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::post('/vehicles', [VehicleController::class, 'store']);
    Route::get('/vehicles/{id}', [VehicleController::class, 'show']);
    Route::put('/vehicles/{id}', [VehicleController::class, 'update']);
    Route::delete('/vehicles/{id}', [VehicleController::class, 'destroy']);

    // Transactions (dedicated)
    Route::get('/transactions/stats', [TransactionController::class, 'stats']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::put('/transactions/{id}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);

    // Votes (التصويت)
    Route::get('/votes/stats', [VoteController::class, 'stats']);
    Route::get('/votes/association-owners-count/{associationId}', [VoteController::class, 'associationOwnersCount']);
    Route::get('/votes', [VoteController::class, 'index']);
    Route::post('/votes', [VoteController::class, 'store']);
    Route::get('/votes/{id}', [VoteController::class, 'show']);
    Route::put('/votes/{id}', [VoteController::class, 'update']);
    Route::delete('/votes/{id}', [VoteController::class, 'destroy']);
    Route::post('/votes/{id}/cast', [VoteController::class, 'castVote']);
    Route::post('/votes/{id}/advance-phase', [VoteController::class, 'advancePhase']);

    // Reports
    Route::get('/reports/financial', [ReportController::class, 'financial']);
    Route::get('/reports/owners', [ReportController::class, 'owners']);
    Route::get('/reports/properties', [ReportController::class, 'properties']);
    Route::get('/reports/contracts', [ReportController::class, 'contracts']);
    Route::get('/reports/maintenance', [ReportController::class, 'maintenance']);
    Route::get('/reports/legal', [ReportController::class, 'legal']);
    Route::get('/reports/meetings', [ReportController::class, 'meetings']);
    Route::get('/reports/parking', [ReportController::class, 'parking']);

    // Export / Import (CSV)
    Route::get('/export/{module}', [ExportImportController::class, 'export']);
    Route::get('/export/{module}/template', [ExportImportController::class, 'template']);
    Route::post('/import/{module}', [ExportImportController::class, 'import']);

    // Generic resources (remaining)
    $resources = [
        'facilities',
        'bookings',
        'maintenance-requests',
        'approval-requests',
    ];

    foreach ($resources as $resource) {
        Route::get("/{$resource}", [ResourceController::class, 'index'])->defaults('resource', $resource);
        Route::get("/{$resource}/{id}", [ResourceController::class, 'show'])->defaults('resource', $resource);
        Route::post("/{$resource}", [ResourceController::class, 'store'])->defaults('resource', $resource);
        Route::put("/{$resource}/{id}", [ResourceController::class, 'update'])->defaults('resource', $resource);
        Route::delete("/{$resource}/{id}", [ResourceController::class, 'destroy'])->defaults('resource', $resource);
    }
});
