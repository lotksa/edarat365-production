<?php

namespace App\Models\Concerns;

use App\Support\BuildingTerminology;

/** Keep old system labels readable with current terminology without a data rewrite. */
trait DisplaysBuildingTerminology
{
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        return is_string($value) && in_array($key, $this->buildingTerminologyFields, true)
            ? BuildingTerminology::text($value) : $value;
    }

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();
        foreach ($this->buildingTerminologyFields as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = BuildingTerminology::text($attributes[$key]);
            }
        }

        return $attributes;
    }
}
