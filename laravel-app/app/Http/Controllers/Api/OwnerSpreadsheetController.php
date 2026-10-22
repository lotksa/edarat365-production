<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OwnerSpreadsheetImport;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

final class OwnerSpreadsheetController extends Controller
{
    public function template()
    {
        return response()->streamDownload(function () {
            $path = tempnam(sys_get_temp_dir(), 'edarat-owner-template-');
            try {
                $writer = new Writer();
                $writer->openToFile($path);
                $sheet = $writer->getCurrentSheet();
                $sheet->setName('الملاك');
                $sheet->setSheetView(new SheetView(freezeRow: 2, rightToLeft: true));
                $sheet->setColumnWidthForRange(28, 1, 6);
                $writer->addRow(Row::fromValuesWithStyle(OwnerSpreadsheetImport::COLUMNS, new Style(fontBold: true, fontName: 'Tajawal', fontSize: 12)));
                $writer->close();
                readfile($path);
            } finally { if (is_file($path)) unlink($path); }
        }, 'owners-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function preview(Request $request, OwnerSpreadsheetImport $import)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv', 'max:10240'],
            'property_id' => ['nullable', 'integer'],
        ], ['file.mimes' => 'استخدم ملف Excel بصيغة XLSX أو ملف CSV', 'file.extensions' => 'استخدم امتداد XLSX أو CSV',
            'file.max' => 'الحد الأقصى للملف 10 ميجابايت']);
        return response()->json($import->preview($request->file('file'), $request->user()->id,
            empty($data['property_id']) ? null : (int) $data['property_id']));
    }

    public function commit(Request $request, OwnerSpreadsheetImport $import)
    {
        $data = $request->validate(['token' => ['required', 'uuid']]);
        return response()->json($import->commit($data['token'], $request->user()->id));
    }
}
