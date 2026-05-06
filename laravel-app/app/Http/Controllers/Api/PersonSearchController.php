<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssociationManager;
use App\Models\Owner;
use App\Models\PropertyManager;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $search = trim($request->query('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $seen = [];
        $results = [];

        $sources = [
            ['model' => Owner::class,              'type' => 'owner',              'label_ar' => 'مالك',           'label_en' => 'Owner',              'has_address' => true],
            ['model' => Tenant::class,             'type' => 'tenant',             'label_ar' => 'مستأجر',         'label_en' => 'Tenant',             'has_address' => true],
            ['model' => PropertyManager::class,    'type' => 'property_manager',   'label_ar' => 'مدير عقار',      'label_en' => 'Property Manager',   'has_address' => false],
            ['model' => AssociationManager::class, 'type' => 'association_manager', 'label_ar' => 'رئيس جمعية',    'label_en' => 'Association Head',   'has_address' => false],
        ];

        foreach ($sources as $src) {
            $query = $src['model']::query()
                ->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('national_id', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                })
                ->limit(20)
                ->get();

            foreach ($query as $person) {
                $nid = $person->national_id;

                if ($nid && isset($seen[$nid])) {
                    $existing = &$results[$seen[$nid]];
                    if (!in_array($src['label_ar'], $existing['roles_ar'])) {
                        $existing['roles_ar'][] = $src['label_ar'];
                        $existing['roles_en'][] = $src['label_en'];
                    }
                    if ($src['has_address'] && !$existing['has_national_address'] && $person->has_national_address) {
                        $existing = array_merge($existing, $this->addressFields($person));
                    }
                    continue;
                }

                $entry = [
                    'id'            => $person->id,
                    'source_type'   => $src['type'],
                    'full_name'     => $person->full_name,
                    'national_id'   => $nid,
                    'phone'         => $person->phone,
                    'email'         => $person->email ?? '',
                    'roles_ar'      => [$src['label_ar']],
                    'roles_en'      => [$src['label_en']],
                ];

                if ($src['has_address']) {
                    $entry = array_merge($entry, $this->addressFields($person));
                } else {
                    $entry['has_national_address'] = false;
                    $entry['address_type'] = null;
                    $entry['address_short_code'] = null;
                    $entry['address_region'] = null;
                    $entry['address_city'] = null;
                    $entry['address_district'] = null;
                    $entry['address_street'] = null;
                    $entry['address_building_no'] = null;
                    $entry['address_additional_no'] = null;
                    $entry['address_postal_code'] = null;
                    $entry['address_unit_no'] = null;
                }

                $idx = count($results);
                $results[] = $entry;
                if ($nid) {
                    $seen[$nid] = $idx;
                }
            }
        }

        usort($results, fn ($a, $b) => strcmp($a['full_name'], $b['full_name']));

        return response()->json(['data' => array_slice($results, 0, 20)]);
    }

    private function addressFields($person): array
    {
        return [
            'has_national_address' => (bool) ($person->has_national_address ?? false),
            'address_type'         => $person->address_type ?? null,
            'address_short_code'   => $person->address_short_code ?? null,
            'address_region'       => $person->address_region ?? null,
            'address_city'         => $person->address_city ?? null,
            'address_district'     => $person->address_district ?? null,
            'address_street'       => $person->address_street ?? null,
            'address_building_no'  => $person->address_building_no ?? null,
            'address_additional_no'=> $person->address_additional_no ?? null,
            'address_postal_code'  => $person->address_postal_code ?? null,
            'address_unit_no'      => $person->address_unit_no ?? null,
        ];
    }
}
