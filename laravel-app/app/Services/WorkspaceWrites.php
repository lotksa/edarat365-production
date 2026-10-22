<?php

namespace App\Services;

use App\Models\Association;
use App\Scopes\AssociationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validates relationship IDs before existing controllers perform any writes. */
final class WorkspaceWrites
{
    private const FOREIGN = [
        'association_id' => 'Association', 'associationId' => 'Association',
        'property_id' => 'Property', 'propertyId' => 'Property',
        'attendance_scope_id' => 'Property', 'unit_id' => 'Unit', 'unitId' => 'Unit',
        'owner_id' => 'Owner', 'tenant_id' => 'Tenant', 'property_manager_id' => 'PropertyManager',
        'invoice_id' => 'Invoice', 'original_invoice_id' => 'Invoice', 'replacement_invoice_id' => 'Invoice',
        'facility_id' => 'Facility', 'meeting_id' => 'Meeting', 'vote_id' => 'Vote',
        'parking_spot_id' => 'ParkingSpot', 'legal_case_id' => 'LegalCase',
        'representative_id' => 'LegalRepresentative', 'reply_to_id' => 'CaseMessage',
    ];

    public function validateRequest(Request $request, string $root): void
    {
        $this->foreignIds($request->all(), $root);
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return;
        }
        $id = app(AssociationContext::class)->id;
        if ($root === 'units' && $request->isMethod('POST') && $request->path() === 'api/v1/units'
            && ! $request->input('property_id')) {
            throw ValidationException::withMessages(['property_id' => 'اختر عقارًا من الجمعية الحالية']);
        }
        if ($root === 'units' && $request->isMethod('PUT') && $request->has('property_id') && ! $request->input('property_id')) {
            throw ValidationException::withMessages(['property_id' => 'يجب إبقاء الوحدة مرتبطة بعقار من الجمعية الحالية']);
        }
        $models = ['owners' => 'Owner', 'association-managers' => 'AssociationManager',
            'property-managers' => 'PropertyManager', 'tenants' => 'Tenant',
            'legal-representatives' => 'LegalRepresentative', 'associations' => 'Association',
            'properties' => 'Property', 'units' => 'Unit'];
        if (isset($models[$root]) && $request->has('ids') && is_array($request->input('ids'))) {
            $class = 'App\\Models\\'.$models[$root];
            foreach ($request->input('ids') as $value) {
                if (! is_scalar($value) || ! $class::whereKey($value)->exists()) {
                    throw ValidationException::withMessages(['ids' => 'القائمة تحتوي سجلاً خارج الجمعية الحالية']);
                }
                $this->protectSharedPerson($class, (int) $value);
            }
        }
        if (isset($models[$root]) && preg_match('#^api/v1/[^/]+/(\d+)#', $request->path(), $matches)
            && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
            $this->protectSharedPerson('App\\Models\\'.$models[$root], (int) $matches[1]);
        }
    }

    private function protectSharedPerson(string $class, int $key): void
    {
        $table = (new $class)->getTable();
        if (! in_array($table, AssociationScope::PEOPLE, true)) {
            return;
        }
        $scope = app(AssociationScope::class);
        foreach (Association::withoutGlobalScope(AssociationScope::class)->pluck('id') as $other) {
            if ((int) $other === app(AssociationContext::class)->id) {
                continue;
            }
            if (DB::query()->fromSub($scope->ids($table, $other), 'shared_person')->where('id', $key)->exists()) {
                abort(409, 'هذا الشخص مرتبط بأكثر من جمعية؛ بياناته المشتركة محمية من التعديل أو الحذف داخل جمعية واحدة');
            }
        }
    }

    private function foreignIds(array $values, string $root, string $prefix = ''): void
    {
        foreach ($values as $key => $value) {
            $field = $prefix.$key;
            if (is_array($value)) {
                if (isset(self::FOREIGN[$key]) || ($key === 'manager_id' && $root === 'associations')) {
                    throw ValidationException::withMessages([$field => 'معرّف السجل يجب أن يكون قيمة واحدة']);
                }
                if (in_array($key, ['owner_ids', 'invitees'], true)) {
                    foreach ($value as $v) {
                        $this->check('Owner', $v, $field);
                    }
                } else {
                    $this->foreignIds($value, $root, $field.'.');
                }

                continue;
            }
            if (isset(self::FOREIGN[$key])) {
                $this->check(self::FOREIGN[$key], $value, $field);
            } elseif ($key === 'manager_id' && $root === 'associations') {
                $this->check('AssociationManager', $value, $field);
            }
        }
    }

    private function check(string $model, mixed $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $class = 'App\\Models\\'.$model;
        if (! is_scalar($value) || ! ctype_digit((string) $value) || ! $class::whereKey($value)->exists()) {
            throw ValidationException::withMessages([$field => 'اختر سجلاً تابعًا للجمعية الحالية']);
        }
    }

    public function prepare(Model $model): void
    {
        $id = app(AssociationContext::class)->id;
        if ($id === null) {
            return;
        }
        if (in_array($model->getTable(), AssociationScope::DIRECT, true)) {
            if (! $model->exists) {
                $model->setAttribute('association_id', $id);
            } elseif ($model->isDirty('association_id') && (int) $model->association_id !== $id) {
                throw ValidationException::withMessages(['association_id' => 'لا يمكن نقل السجل إلى جمعية أخرى من هذه المساحة']);
            }
        }
        if ($model->getTable() === 'units' && ! $model->property_id) {
            throw ValidationException::withMessages(['property_id' => 'يجب ربط الوحدة بعقار من الجمعية الحالية']);
        }
    }
}
