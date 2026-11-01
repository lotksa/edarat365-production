<?php

namespace App\Support;

/** Presentation terminology only. Database keys, relations and stored documents stay intact. */
final class BuildingTerminology
{
    public static function text(string $value, bool $translateEnglish = true): string
    {
        // A government body's proper name is not the application's entity label.
        $official = ['الهيئة العامة للعقار', 'هيئة العقار'];
        foreach ($official as $index => $name) {
            $value = str_replace($name, "\u{E000}{$index}\u{E001}", $value);
        }

        $value = strtr($value, [
            'يتم تعيين شركة إدارة عقارية متخصصة لإدارة الجمعية' => 'يتم تعيين شركة متخصصة في إدارة المباني لإدارة الجمعية',
            'إدارة مشتركة بين الملاك وشركة إدارة عقارية بشكل تعاوني' => 'إدارة مشتركة بين الملاك وشركة لإدارة المباني بشكل تعاوني',
            'الوحدات العقارية' => 'وحدات المباني', 'وحدات عقارية' => 'وحدات المباني',
            'الوحدة العقارية' => 'وحدة المبنى', 'وحدة عقارية' => 'وحدة مبنى',
            'موقعه العقاري' => 'موقع المبنى', 'الرقم العقاري' => 'رقم المبنى',
            'إدارة عقارية' => 'إدارة المباني', 'العقارية' => 'الخاصة بالمباني',
            'عقارية' => 'خاصة بالمباني', 'العقاري' => 'الخاص بالمبنى', 'عقاري' => 'شؤون المباني',
            'عقارًا' => 'مبنى', 'عقارات' => 'مباني', 'عقار' => 'مبنى',
        ]);
        if ($translateEnglish) {
            $value = preg_replace_callback('/\b(properties|property)\b/i', static fn ($m) => match ($m[0]) {
                'PROPERTIES' => 'BUILDINGS', 'Properties' => 'Buildings',
                'PROPERTY' => 'BUILDING', 'Property' => 'Building',
                default => strtolower($m[1]) === 'properties' ? 'buildings' : 'building',
            }, $value);
            $value = str_replace('Buildings Management', 'Building Management', $value);
        }

        foreach ($official as $index => $name) {
            $value = str_replace("\u{E000}{$index}\u{E001}", $name, $value);
        }

        return $value;
    }

    /** Normalize only display labels in saved configuration, never keys or secrets. */
    public static function labels(array $values): array
    {
        $fields = ['name_ar', 'name_en', 'desc_ar', 'desc_en', 'label_ar', 'label_en',
            'title_ar', 'title_en', 'group_name_ar', 'group_name_en', 'description_ar', 'description_en'];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::labels($value);
            } elseif (is_string($value) && in_array($key, $fields, true)) {
                $values[$key] = self::text($value);
            }
        }

        return $values;
    }
}
