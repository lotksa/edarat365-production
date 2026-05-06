<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = City::withCount('districts');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        if ($request->query('all') === 'true') {
            return response()->json(['data' => $query->orderBy('name_ar')->get()]);
        }

        $perPage = (int) $request->query('per_page', 20);
        $records = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_ar'   => ['required', 'string', 'max:255'],
            'name_en'   => ['nullable', 'string', 'max:255'],
            'latitude'  => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'status'    => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $city = City::create($data);
        return response()->json(['message' => 'City created', 'data' => $city->loadCount('districts')], 201);
    }

    public function show(int $id): JsonResponse
    {
        $city = City::with('districts')->withCount('districts')->findOrFail($id);
        return response()->json(['data' => $city]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $city = City::findOrFail($id);
        $data = $request->validate([
            'name_ar'   => ['sometimes', 'string', 'max:255'],
            'name_en'   => ['nullable', 'string', 'max:255'],
            'latitude'  => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'status'    => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $city->update($data);
        return response()->json(['message' => 'Updated', 'data' => $city->fresh()->loadCount('districts')]);
    }

    public function destroy(int $id): JsonResponse
    {
        City::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // ── Export Cities as CSV ──
    public function export(): StreamedResponse
    {
        $cities = City::withCount('districts')->orderBy('name_ar')->get();

        return response()->streamDownload(function () use ($cities) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['اسم المدينة (عربي)', 'اسم المدينة (إنجليزي)', 'عدد الأحياء', 'الحالة']);
            foreach ($cities as $c) {
                fputcsv($out, [$c->name_ar, $c->name_en ?? '', $c->districts_count, $c->status]);
            }
            fclose($out);
        }, 'cities.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Import Cities from CSV ──
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);
        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);

        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $nameAr = trim($row[0] ?? '');
            $nameEn = trim($row[1] ?? '');
            if (!$nameAr) { $skipped++; continue; }
            if (City::where('name_ar', $nameAr)->exists()) { $skipped++; continue; }
            City::create(['name_ar' => $nameAr, 'name_en' => $nameEn ?: null, 'status' => 'active']);
            $created++;
        }

        return response()->json(['message' => "Created {$created}, skipped {$skipped}", 'created' => $created, 'skipped' => $skipped]);
    }

    // ── Import Template ──
    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['اسم المدينة (عربي)', 'اسم المدينة (إنجليزي)']);
            fputcsv($out, ['الرياض', 'Riyadh']);
            fclose($out);
        }, 'cities-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Districts ──
    public function districts(int $cityId, Request $request): JsonResponse
    {
        $query = District::where('city_id', $cityId);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        if ($request->query('all') === 'true') {
            return response()->json(['data' => $query->orderBy('name_ar')->get()]);
        }

        $perPage = (int) $request->query('per_page', 50);
        $records = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    public function storeDistrict(Request $request, int $cityId): JsonResponse
    {
        City::findOrFail($cityId);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'status'  => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $data['city_id'] = $cityId;
        $district = District::create($data);
        return response()->json(['message' => 'District created', 'data' => $district], 201);
    }

    public function updateDistrict(Request $request, int $cityId, int $districtId): JsonResponse
    {
        $district = District::where('city_id', $cityId)->findOrFail($districtId);
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'status'  => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $district->update($data);
        return response()->json(['message' => 'Updated', 'data' => $district->fresh()]);
    }

    public function destroyDistrict(int $cityId, int $districtId): JsonResponse
    {
        District::where('city_id', $cityId)->findOrFail($districtId)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // ── Export Districts for a City ──
    public function exportDistricts(int $cityId): StreamedResponse
    {
        $city = City::findOrFail($cityId);
        $districts = District::where('city_id', $cityId)->orderBy('name_ar')->get();

        $filename = "districts-{$city->name_en}.csv";

        return response()->streamDownload(function () use ($districts) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['اسم الحي (عربي)', 'اسم الحي (إنجليزي)', 'الحالة']);
            foreach ($districts as $d) {
                fputcsv($out, [$d->name_ar, $d->name_en ?? '', $d->status]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Import Districts for a City ──
    public function importDistricts(Request $request, int $cityId): JsonResponse
    {
        City::findOrFail($cityId);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);
        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        array_shift($rows);

        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $nameAr = trim($row[0] ?? '');
            $nameEn = trim($row[1] ?? '');
            if (!$nameAr) { $skipped++; continue; }
            if (District::where('city_id', $cityId)->where('name_ar', $nameAr)->exists()) { $skipped++; continue; }
            District::create(['city_id' => $cityId, 'name_ar' => $nameAr, 'name_en' => $nameEn ?: null, 'status' => 'active']);
            $created++;
        }

        return response()->json(['message' => "Created {$created}, skipped {$skipped}", 'created' => $created, 'skipped' => $skipped]);
    }

    public function districtsImportTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['اسم الحي (عربي)', 'اسم الحي (إنجليزي)']);
            fputcsv($out, ['العليا', 'Al Olaya']);
            fclose($out);
        }, 'districts-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
