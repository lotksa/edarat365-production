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
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Public endpoints (no auth required) ───────────────────────────────────
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'edarat365-api',
    ]));

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/request-otp', [AuthController::class, 'requestOtp']);
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/auth/forgot-password', [AuthController::class, 'requestOtp']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    // ── Authenticated endpoints ───────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Account / session (any authenticated user)
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AccountController::class, 'me']);
        Route::put('/account/profile', [AccountController::class, 'updateProfile']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('permission:dashboard.view');

        // Global Search (available to any logged-in user; results already filter by what they can see)
        Route::get('/search', [GlobalSearchController::class, 'search']);
        Route::get('/search/ai', [GlobalSearchController::class, 'aiSearch']);

        // AI
        Route::post('/ai/chat', [AiController::class, 'chat']);
        Route::get('/ai/insights', [AiController::class, 'insights']);
        Route::post('/ai/suggest', [AiController::class, 'suggest']);

        // Settings (read = settings.view, write = settings.update)
        Route::get('/settings/{key}', [SettingsController::class, 'show'])
            ->middleware('permission:settings.view');
        Route::put('/settings/{key}', [SettingsController::class, 'update'])
            ->middleware('permission:settings.update');

        // ── Users management (strict) ─────────────────────────────────────────
        Route::middleware('permission:users.view')->group(function () {
            Route::get('/users/stats', [UserController::class, 'stats']);
            Route::get('/users', [UserController::class, 'index']);
            Route::get('/users/{id}', [UserController::class, 'show']);
        });
        Route::post('/users', [UserController::class, 'store'])
            ->middleware('permission:users.create');
        Route::put('/users/{id}', [UserController::class, 'update'])
            ->middleware('permission:users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])
            ->middleware('permission:users.delete');
        Route::post('/users/bulk-delete', [UserController::class, 'bulkDelete'])
            ->middleware('permission:users.delete');
        Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus'])
            ->middleware('permission:users.toggle_status');

        // ── Roles & Permissions management (strict) ──────────────────────────
        Route::middleware('permission:roles.view')->group(function () {
            Route::get('/roles/stats', [RoleController::class, 'stats']);
            Route::get('/roles', [RoleController::class, 'index']);
            Route::get('/roles/{id}', [RoleController::class, 'show']);
            Route::get('/permissions', [PermissionController::class, 'index']);
        });
        Route::post('/roles', [RoleController::class, 'store'])
            ->middleware('permission:roles.create');
        Route::put('/roles/{id}', [RoleController::class, 'update'])
            ->middleware('permission:roles.update');
        Route::delete('/roles/{id}', [RoleController::class, 'destroy'])
            ->middleware('permission:roles.delete');
        Route::put('/roles/{id}/permissions', [RoleController::class, 'syncPermissions'])
            ->middleware('permission:roles.assign_permissions');

        // ── Owners ────────────────────────────────────────────────────────────
        Route::middleware('permission:owners.view')->group(function () {
            Route::get('/owners/stats', [OwnerController::class, 'stats']);
            Route::get('/owners/export', [OwnerController::class, 'export']);
            Route::get('/owners/import-template', [OwnerController::class, 'importTemplate']);
            Route::get('/owners', [OwnerController::class, 'index']);
            Route::get('/owners/{id}', [OwnerController::class, 'show']);
        });
        Route::post('/owners', [OwnerController::class, 'store'])->middleware('permission:owners.create');
        Route::post('/owners/import', [OwnerController::class, 'import'])->middleware('permission:owners.create');
        Route::post('/owners/bulk-delete', [OwnerController::class, 'bulkDelete'])->middleware('permission:owners.delete');
        Route::patch('/owners/{id}/toggle-status', [OwnerController::class, 'toggleStatus'])->middleware('permission:owners.update');
        Route::put('/owners/{id}', [OwnerController::class, 'update'])->middleware('permission:owners.update');
        Route::delete('/owners/{id}', [OwnerController::class, 'destroy'])->middleware('permission:owners.delete');

        // ── Association Managers (under associations.* perms) ────────────────
        Route::middleware('permission:associations.view')->group(function () {
            Route::get('/association-managers/stats', [AssociationManagerController::class, 'stats']);
            Route::get('/association-managers', [AssociationManagerController::class, 'index']);
            Route::get('/association-managers/{id}', [AssociationManagerController::class, 'show']);
        });
        Route::post('/association-managers', [AssociationManagerController::class, 'store'])->middleware('permission:associations.create');
        Route::post('/association-managers/bulk-delete', [AssociationManagerController::class, 'bulkDelete'])->middleware('permission:associations.delete');
        Route::patch('/association-managers/{id}/toggle-status', [AssociationManagerController::class, 'toggleStatus'])->middleware('permission:associations.update');
        Route::put('/association-managers/{id}', [AssociationManagerController::class, 'update'])->middleware('permission:associations.update');
        Route::delete('/association-managers/{id}', [AssociationManagerController::class, 'destroy'])->middleware('permission:associations.delete');

        // ── Associations ─────────────────────────────────────────────────────
        Route::middleware('permission:associations.view')->group(function () {
            Route::get('/associations/stats', [AssociationController::class, 'stats']);
            Route::get('/associations', [AssociationController::class, 'index']);
            Route::get('/associations/{id}', [AssociationController::class, 'show']);
            Route::get('/associations/{id}/bookings', [FacilityController::class, 'associationBookings']);
        });
        Route::post('/associations', [AssociationController::class, 'store'])->middleware('permission:associations.create');
        Route::post('/associations/bulk-delete', [AssociationController::class, 'bulkDelete'])->middleware('permission:associations.delete');
        Route::post('/associations/bulk-status', [AssociationController::class, 'bulkUpdateStatus'])->middleware('permission:associations.update');
        Route::patch('/associations/{id}/toggle-status', [AssociationController::class, 'toggleStatus'])->middleware('permission:associations.update');
        Route::put('/associations/{id}', [AssociationController::class, 'update'])->middleware('permission:associations.update');
        Route::delete('/associations/{id}', [AssociationController::class, 'destroy'])->middleware('permission:associations.delete');

        // ── Property Managers ────────────────────────────────────────────────
        Route::middleware('permission:properties.view')->group(function () {
            Route::get('/property-managers/stats', [PropertyManagerController::class, 'stats']);
            Route::get('/property-managers', [PropertyManagerController::class, 'index']);
            Route::get('/property-managers/{id}', [PropertyManagerController::class, 'show']);
        });
        Route::post('/property-managers', [PropertyManagerController::class, 'store'])->middleware('permission:properties.create');
        Route::post('/property-managers/bulk-delete', [PropertyManagerController::class, 'bulkDelete'])->middleware('permission:properties.delete');
        Route::patch('/property-managers/{id}/toggle-status', [PropertyManagerController::class, 'toggleStatus'])->middleware('permission:properties.update');
        Route::put('/property-managers/{id}', [PropertyManagerController::class, 'update'])->middleware('permission:properties.update');
        Route::delete('/property-managers/{id}', [PropertyManagerController::class, 'destroy'])->middleware('permission:properties.delete');

        // ── Properties ───────────────────────────────────────────────────────
        Route::middleware('permission:properties.view')->group(function () {
            Route::get('/properties/stats', [PropertyController::class, 'stats']);
            Route::get('/properties', [PropertyController::class, 'index']);
            Route::get('/properties/{id}', [PropertyController::class, 'show']);
            Route::get('/properties/{id}/related/association', [PropertyController::class, 'relatedAssociation']);
            Route::get('/properties/{id}/related/units', [PropertyController::class, 'relatedUnits']);
            Route::get('/properties/{id}/related/owners', [PropertyController::class, 'relatedOwners']);
            Route::get('/properties/{id}/related/facilities', [PropertyController::class, 'relatedFacilities']);
            Route::get('/properties/{id}/related/maintenance', [PropertyController::class, 'relatedMaintenance']);
            Route::get('/properties/{id}/related/contracts', [PropertyController::class, 'relatedContracts']);
            Route::get('/properties/{id}/related/meetings', [PropertyController::class, 'relatedMeetings']);
            Route::get('/properties/{id}/related/legal-cases', [PropertyController::class, 'relatedLegalCases']);
            Route::get('/properties/{id}/related/approvals', [PropertyController::class, 'relatedApprovals']);
            Route::get('/properties/{propertyId}/meters', [PropertyController::class, 'listUtilityMeters']);
        });
        Route::post('/properties', [PropertyController::class, 'store'])->middleware('permission:properties.create');
        Route::post('/properties/bulk-delete', [PropertyController::class, 'bulkDelete'])->middleware('permission:properties.delete');
        Route::post('/properties/bulk-status', [PropertyController::class, 'bulkUpdateStatus'])->middleware('permission:properties.update');
        Route::put('/properties/{id}', [PropertyController::class, 'update'])->middleware('permission:properties.update');
        Route::delete('/properties/{id}', [PropertyController::class, 'destroy'])->middleware('permission:properties.delete');
        Route::post('/properties/{propertyId}/meters', [PropertyController::class, 'storeUtilityMeter'])->middleware('permission:properties.update');
        Route::put('/properties/{propertyId}/meters/{meterId}', [PropertyController::class, 'updateUtilityMeter'])->middleware('permission:properties.update');
        Route::delete('/properties/{propertyId}/meters/{meterId}', [PropertyController::class, 'destroyUtilityMeter'])->middleware('permission:properties.update');
        Route::post('/properties/{propertyId}/documents', [PropertyController::class, 'storeDocument'])->middleware('permission:properties.update');
        Route::delete('/properties/{propertyId}/documents/{docId}', [PropertyController::class, 'destroyDocument'])->middleware('permission:properties.update');
        Route::post('/properties/{propertyId}/documents/bulk-delete', [PropertyController::class, 'bulkDeleteDocuments'])->middleware('permission:properties.update');
        Route::post('/properties/{propertyId}/components', [PropertyController::class, 'storeComponents'])->middleware('permission:properties.update');

        // ── Units ────────────────────────────────────────────────────────────
        Route::middleware('permission:units.view')->group(function () {
            Route::get('/units/stats', [UnitController::class, 'stats']);
            Route::get('/units', [UnitController::class, 'index']);
            Route::get('/units/{id}', [UnitController::class, 'show']);
        });
        Route::post('/units', [UnitController::class, 'store'])->middleware('permission:units.create');
        Route::post('/units/bulk-delete', [UnitController::class, 'bulkDelete'])->middleware('permission:units.delete');
        Route::post('/units/bulk-status', [UnitController::class, 'bulkUpdateStatus'])->middleware('permission:units.update');
        Route::put('/units/{id}', [UnitController::class, 'update'])->middleware('permission:units.update');
        Route::delete('/units/{id}', [UnitController::class, 'destroy'])->middleware('permission:units.delete');
        Route::patch('/units/{id}/toggle-status', [UnitController::class, 'toggleStatus'])->middleware('permission:units.update');
        Route::post('/units/{unitId}/components', [UnitController::class, 'storeComponents'])->middleware('permission:units.update');
        Route::post('/units/{unitId}/owners', [UnitController::class, 'syncOwners'])->middleware('permission:units.update');
        Route::post('/units/{unitId}/images', [UnitController::class, 'uploadImages'])->middleware('permission:units.update');
        Route::put('/units/{unitId}/images/{imageId}', [UnitController::class, 'updateImage'])->middleware('permission:units.update');
        Route::delete('/units/{unitId}/images/{imageId}', [UnitController::class, 'deleteImage'])->middleware('permission:units.update');

        // ── Unified Person Search (used everywhere — needs at least one of the view perms) ──
        Route::get('/people/search', [PersonSearchController::class, 'search']);

        // ── Activity Logs ────────────────────────────────────────────────────
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])
            ->middleware('permission:activity_log.view');

        // ── Tenants (under contracts.*) ──────────────────────────────────────
        Route::middleware('permission:contracts.view')->group(function () {
            Route::get('/tenants', [TenantController::class, 'index']);
            Route::get('/tenants/{id}', [TenantController::class, 'show']);
        });
        Route::post('/tenants', [TenantController::class, 'store'])->middleware('permission:contracts.create');
        Route::put('/tenants/{id}', [TenantController::class, 'update'])->middleware('permission:contracts.update');
        Route::delete('/tenants/{id}', [TenantController::class, 'destroy'])->middleware('permission:contracts.delete');

        // ── Contracts ────────────────────────────────────────────────────────
        Route::middleware('permission:contracts.view')->group(function () {
            Route::get('/contracts/stats', [ContractController::class, 'stats']);
            Route::get('/contracts/clauses', [ContractController::class, 'clauses']);
            Route::get('/contracts', [ContractController::class, 'index']);
            Route::get('/contracts/{id}', [ContractController::class, 'show']);
        });
        Route::post('/contracts', [ContractController::class, 'store'])->middleware('permission:contracts.create');
        Route::put('/contracts/{id}', [ContractController::class, 'update'])->middleware('permission:contracts.update');
        Route::delete('/contracts/{id}', [ContractController::class, 'destroy'])->middleware('permission:contracts.delete');
        Route::patch('/contracts/{id}/terminate', [ContractController::class, 'terminate'])->middleware('permission:contracts.update');

        // ── Cities & Districts (settings.update) ─────────────────────────────
        Route::get('/cities/export', [CityController::class, 'export']);
        Route::get('/cities/import-template', [CityController::class, 'importTemplate']);
        Route::get('/cities', [CityController::class, 'index']);
        Route::get('/cities/{id}', [CityController::class, 'show']);
        Route::get('/cities/{cityId}/districts/export', [CityController::class, 'exportDistricts']);
        Route::get('/cities/{cityId}/districts/import-template', [CityController::class, 'districtsImportTemplate']);
        Route::get('/cities/{cityId}/districts', [CityController::class, 'districts']);
        Route::middleware('permission:settings.update')->group(function () {
            Route::post('/cities/import', [CityController::class, 'import']);
            Route::post('/cities', [CityController::class, 'store']);
            Route::put('/cities/{id}', [CityController::class, 'update']);
            Route::delete('/cities/{id}', [CityController::class, 'destroy']);
            Route::post('/cities/{cityId}/districts/import', [CityController::class, 'importDistricts']);
            Route::post('/cities/{cityId}/districts', [CityController::class, 'storeDistrict']);
            Route::put('/cities/{cityId}/districts/{districtId}', [CityController::class, 'updateDistrict']);
            Route::delete('/cities/{cityId}/districts/{districtId}', [CityController::class, 'destroyDistrict']);
        });

        // ── Meetings ─────────────────────────────────────────────────────────
        Route::middleware('permission:meetings.view')->group(function () {
            Route::get('/meetings/stats', [MeetingController::class, 'stats']);
            Route::get('/meetings', [MeetingController::class, 'index']);
            Route::get('/meetings/{id}', [MeetingController::class, 'show']);
            Route::get('/meetings/{meetingId}/resolutions', [MeetingController::class, 'resolutions']);
        });
        Route::post('/meetings', [MeetingController::class, 'store'])->middleware('permission:meetings.create');
        Route::put('/meetings/{id}', [MeetingController::class, 'update'])->middleware('permission:meetings.update');
        Route::delete('/meetings/{id}', [MeetingController::class, 'destroy'])->middleware('permission:meetings.delete');
        Route::post('/meetings/{id}/invite', [MeetingController::class, 'invite'])->middleware('permission:meetings.update');
        Route::put('/meetings/{id}/attendance', [MeetingController::class, 'updateAttendance'])->middleware('permission:meetings.attendance');
        Route::post('/meetings/{meetingId}/resolutions', [MeetingController::class, 'storeResolution'])->middleware('permission:meetings.update');
        Route::put('/meetings/{meetingId}/resolutions/{resolutionId}', [MeetingController::class, 'updateResolution'])->middleware('permission:meetings.update');
        Route::delete('/meetings/{meetingId}/resolutions/{resolutionId}', [MeetingController::class, 'destroyResolution'])->middleware('permission:meetings.update');

        // ── Legal Cases ──────────────────────────────────────────────────────
        Route::middleware('permission:legal_cases.view')->group(function () {
            Route::get('/legal-cases/stats', [LegalCaseController::class, 'stats']);
            Route::get('/legal-cases', [LegalCaseController::class, 'index']);
            Route::get('/legal-cases/{caseId}/updates', [LegalCaseController::class, 'updates']);
            Route::get('/legal-cases/{id}', [LegalCaseController::class, 'show']);
            Route::get('/legal-cases/{caseId}/permissions', [CasePermissionController::class, 'index']);
            Route::get('/legal-cases/{caseId}/messages', [CaseMessageController::class, 'index']);
            Route::get('/legal-cases/{caseId}/reminders', [CaseReminderController::class, 'index']);
        });
        Route::post('/legal-cases', [LegalCaseController::class, 'store'])->middleware('permission:legal_cases.create');
        Route::post('/legal-cases/upload-document', [LegalCaseController::class, 'uploadDocument'])->middleware('permission:legal_cases.update');
        Route::post('/legal-cases/{caseId}/updates', [LegalCaseController::class, 'storeUpdate'])->middleware('permission:legal_cases.update');
        Route::put('/legal-cases/{caseId}/updates/{updateId}', [LegalCaseController::class, 'updateUpdate'])->middleware('permission:legal_cases.update');
        Route::delete('/legal-cases/{caseId}/updates/{updateId}', [LegalCaseController::class, 'destroyUpdate'])->middleware('permission:legal_cases.update');
        Route::put('/legal-cases/{id}', [LegalCaseController::class, 'update'])->middleware('permission:legal_cases.update');
        Route::delete('/legal-cases/{id}', [LegalCaseController::class, 'destroy'])->middleware('permission:legal_cases.delete');
        Route::post('/legal-cases/{caseId}/permissions', [CasePermissionController::class, 'store'])->middleware('permission:legal_cases.update');
        Route::put('/legal-cases/{caseId}/permissions/{permId}', [CasePermissionController::class, 'update'])->middleware('permission:legal_cases.update');
        Route::delete('/legal-cases/{caseId}/permissions/{permId}', [CasePermissionController::class, 'destroy'])->middleware('permission:legal_cases.update');
        Route::post('/legal-cases/{caseId}/messages', [CaseMessageController::class, 'store'])->middleware('permission:legal_cases.view');
        Route::post('/legal-cases/{caseId}/messages/upload', [CaseMessageController::class, 'uploadAttachment'])->middleware('permission:legal_cases.view');
        Route::patch('/legal-cases/{caseId}/messages/{msgId}/pin', [CaseMessageController::class, 'pin'])->middleware('permission:legal_cases.view');
        Route::post('/legal-cases/{caseId}/messages/mark-read', [CaseMessageController::class, 'markRead'])->middleware('permission:legal_cases.view');
        Route::post('/legal-cases/{caseId}/reminders', [CaseReminderController::class, 'store'])->middleware('permission:legal_cases.update');
        Route::put('/legal-cases/{caseId}/reminders/{remId}', [CaseReminderController::class, 'update'])->middleware('permission:legal_cases.update');
        Route::patch('/legal-cases/{caseId}/reminders/{remId}/dismiss', [CaseReminderController::class, 'dismiss'])->middleware('permission:legal_cases.update');
        Route::delete('/legal-cases/{caseId}/reminders/{remId}', [CaseReminderController::class, 'destroy'])->middleware('permission:legal_cases.update');

        // ── Legal Representatives ────────────────────────────────────────────
        Route::middleware('permission:legal_cases.view')->group(function () {
            Route::get('/legal-representatives/stats', [LegalRepresentativeController::class, 'stats']);
            Route::get('/legal-representatives', [LegalRepresentativeController::class, 'index']);
            Route::get('/legal-representatives/{id}', [LegalRepresentativeController::class, 'show']);
        });
        Route::post('/legal-representatives', [LegalRepresentativeController::class, 'store'])->middleware('permission:legal_cases.create');
        Route::put('/legal-representatives/{id}', [LegalRepresentativeController::class, 'update'])->middleware('permission:legal_cases.update');
        Route::delete('/legal-representatives/{id}', [LegalRepresentativeController::class, 'destroy'])->middleware('permission:legal_cases.delete');

        // ── Invoices ─────────────────────────────────────────────────────────
        Route::middleware('permission:invoices.view')->group(function () {
            Route::get('/invoices/stats', [InvoiceController::class, 'stats']);
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
            Route::get('/invoices/{id}/pdf-data', [InvoicePdfController::class, 'show']);
        });
        Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.create');
        Route::put('/invoices/{id}', [InvoiceController::class, 'update'])->middleware('permission:invoices.update');
        Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy'])->middleware('permission:invoices.delete');

        // ── Vouchers ─────────────────────────────────────────────────────────
        Route::middleware('permission:vouchers.view')->group(function () {
            Route::get('/vouchers/stats', [VoucherController::class, 'stats']);
            Route::get('/vouchers', [VoucherController::class, 'index']);
            Route::get('/vouchers/{id}', [VoucherController::class, 'show']);
        });
        Route::post('/vouchers', [VoucherController::class, 'store'])->middleware('permission:vouchers.create');
        Route::put('/vouchers/{id}', [VoucherController::class, 'update'])->middleware('permission:vouchers.update');
        Route::delete('/vouchers/{id}', [VoucherController::class, 'destroy'])->middleware('permission:vouchers.delete');

        // ── Parking Spots ───────────────────────────────────────────────────
        Route::middleware('permission:vehicles.view')->group(function () {
            Route::get('/parking-spots/stats', [ParkingSpotController::class, 'stats']);
            Route::get('/parking-spots', [ParkingSpotController::class, 'index']);
            Route::get('/parking-spots/{id}', [ParkingSpotController::class, 'show']);
        });
        Route::post('/parking-spots', [ParkingSpotController::class, 'store'])->middleware('permission:vehicles.create');
        Route::put('/parking-spots/{id}', [ParkingSpotController::class, 'update'])->middleware('permission:vehicles.update');
        Route::delete('/parking-spots/{id}', [ParkingSpotController::class, 'destroy'])->middleware('permission:vehicles.delete');

        // ── Vehicles ─────────────────────────────────────────────────────────
        Route::middleware('permission:vehicles.view')->group(function () {
            Route::get('/vehicles/stats', [VehicleController::class, 'stats']);
            Route::get('/vehicles', [VehicleController::class, 'index']);
            Route::get('/vehicles/{id}', [VehicleController::class, 'show']);
        });
        Route::post('/vehicles', [VehicleController::class, 'store'])->middleware('permission:vehicles.create');
        Route::put('/vehicles/{id}', [VehicleController::class, 'update'])->middleware('permission:vehicles.update');
        Route::delete('/vehicles/{id}', [VehicleController::class, 'destroy'])->middleware('permission:vehicles.delete');

        // ── Transactions (under vehicles.* perms) ────────────────────────────
        Route::middleware('permission:vehicles.view')->group(function () {
            Route::get('/transactions/stats', [TransactionController::class, 'stats']);
            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::get('/transactions/{id}', [TransactionController::class, 'show']);
        });
        Route::post('/transactions', [TransactionController::class, 'store'])->middleware('permission:vehicles.create');
        Route::put('/transactions/{id}', [TransactionController::class, 'update'])->middleware('permission:vehicles.update');
        Route::delete('/transactions/{id}', [TransactionController::class, 'destroy'])->middleware('permission:vehicles.delete');

        // ── Votes ────────────────────────────────────────────────────────────
        Route::middleware('permission:votes.view')->group(function () {
            Route::get('/votes/stats', [VoteController::class, 'stats']);
            Route::get('/votes/association-owners-count/{associationId}', [VoteController::class, 'associationOwnersCount']);
            Route::get('/votes', [VoteController::class, 'index']);
            Route::get('/votes/{id}', [VoteController::class, 'show']);
        });
        Route::post('/votes', [VoteController::class, 'store'])->middleware('permission:votes.create');
        Route::put('/votes/{id}', [VoteController::class, 'update'])->middleware('permission:votes.update');
        Route::delete('/votes/{id}', [VoteController::class, 'destroy'])->middleware('permission:votes.delete');
        Route::post('/votes/{id}/cast', [VoteController::class, 'castVote'])->middleware('permission:votes.view');
        Route::post('/votes/{id}/advance-phase', [VoteController::class, 'advancePhase'])->middleware('permission:votes.update');

        // ── Facilities ──────────────────────────────────────────────────────
        Route::middleware('permission:facilities.view')->group(function () {
            Route::get('/facilities/stats', [FacilityController::class, 'stats']);
            Route::get('/facilities', [FacilityController::class, 'index']);
            Route::get('/facilities/{id}', [FacilityController::class, 'show']);
            Route::get('/facilities/{id}/availability', [FacilityController::class, 'availability']);
            Route::get('/facilities/{id}/bookings', [FacilityController::class, 'bookings']);
            Route::get('/bookings/stats', [FacilityController::class, 'bookingStats']);
            Route::get('/bookings', [FacilityController::class, 'allBookings']);
        });
        Route::post('/facilities', [FacilityController::class, 'store'])->middleware('permission:facilities.create');
        Route::put('/facilities/{id}', [FacilityController::class, 'update'])->middleware('permission:facilities.update');
        Route::delete('/facilities/{id}', [FacilityController::class, 'destroy'])->middleware('permission:facilities.delete');
        Route::post('/facilities/{id}/book', [FacilityController::class, 'book'])->middleware('permission:facilities.book');
        Route::post('/bookings/{id}/cancel', [FacilityController::class, 'cancelBooking'])->middleware('permission:facilities.cancel_booking');

        // ── Maintenance ─────────────────────────────────────────────────────
        Route::middleware('permission:maintenance.view')->group(function () {
            Route::get('/maintenance/stats', [MaintenanceController::class, 'stats']);
            Route::get('/maintenance', [MaintenanceController::class, 'index']);
            Route::get('/maintenance/{id}', [MaintenanceController::class, 'show']);
        });
        Route::post('/maintenance', [MaintenanceController::class, 'store'])->middleware('permission:maintenance.create');
        Route::put('/maintenance/{id}', [MaintenanceController::class, 'update'])->middleware('permission:maintenance.update');
        Route::patch('/maintenance/{id}/status', [MaintenanceController::class, 'updateStatus'])->middleware('permission:maintenance.update_status');
        Route::delete('/maintenance/{id}', [MaintenanceController::class, 'destroy'])->middleware('permission:maintenance.delete');

        // ── Reports ─────────────────────────────────────────────────────────
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/reports/financial', [ReportController::class, 'financial']);
            Route::get('/reports/owners', [ReportController::class, 'owners']);
            Route::get('/reports/properties', [ReportController::class, 'properties']);
            Route::get('/reports/contracts', [ReportController::class, 'contracts']);
            Route::get('/reports/maintenance', [ReportController::class, 'maintenance']);
            Route::get('/reports/legal', [ReportController::class, 'legal']);
            Route::get('/reports/meetings', [ReportController::class, 'meetings']);
            Route::get('/reports/parking', [ReportController::class, 'parking']);
        });

        // ── Export / Import ─────────────────────────────────────────────────
        Route::get('/export/{module}', [ExportImportController::class, 'export'])->middleware('permission:reports.export');
        Route::get('/export/{module}/template', [ExportImportController::class, 'template'])->middleware('permission:reports.export');
        Route::post('/import/{module}', [ExportImportController::class, 'import'])->middleware('permission:settings.update');

        // ── Generic resources fallback (already gated above; keep for compat) ──
        $resources = [
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
});
