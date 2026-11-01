<?php

namespace App\Services;

use App\Models\Association;
use Illuminate\Database\Eloquent\Model;

final class AssociationBranding
{
    public function data(Association $association): array
    {
        return $association->only(['id', 'name', 'name_en', 'logo', 'icon', 'association_number',
            'registration_number', 'address', 'phone', 'email']);
    }

    public function forRecord(Model $record): array
    {
        $id = $record->getAttribute('association_id');
        if (! $id && method_exists($record, 'property')) {
            $id = $record->property?->association_id;
        }
        if (! $id && method_exists($record, 'unit')) {
            $id = $record->unit?->property?->association_id;
        }
        // Old owner-only vouchers have no direct link. Their global scope has
        // already verified membership in this explicitly selected workspace.
        $id ??= app(AssociationContext::class)->id;
        $association = $id ? Association::find($id) : null;
        abort_unless($association, 409, 'اربط المستند بجمعية قبل طباعته');

        return $this->data($association);
    }
}
