<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Association;
use App\Services\AssociationBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssociationBrandingController extends Controller
{
    public function show(int $id, AssociationBranding $branding): JsonResponse
    {
        return response()->json(['data' => $branding->data(Association::findOrFail($id))]);
    }

    public function update(Request $request, int $id, AssociationBranding $branding): JsonResponse
    {
        $association = Association::findOrFail($id);
        $request->validate([
            'logo' => ['required_without:icon', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'icon' => ['required_without:logo', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            '*.image' => 'اختر صورة صالحة', '*.mimes' => 'الصيغ المتاحة PNG أو JPG أو WEBP',
            '*.max' => 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت',
            '*.required_without' => 'اختر شعارًا أو أيقونة لحفظ الهوية',
        ]);
        $changes = [];
        foreach (['logo', 'icon'] as $field) {
            if ($request->hasFile($field)) {
                $changes[$field] = $request->file($field)->store('associations/'.($field === 'logo' ? 'logos' : 'icons'), 'public');
            }
        }
        // Retain previous uploads; replacing identity must not delete files
        // referenced by older documents, records or another association.
        $association->update($changes);
        ActivityLog::record('association', $association->id, 'updated', 'تحديث هوية الجمعية');

        return response()->json(['data' => $branding->data($association->fresh()), 'message' => 'تم حفظ هوية الجمعية']);
    }
}
