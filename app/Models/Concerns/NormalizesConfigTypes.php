<?php

namespace App\Models\Concerns;

trait NormalizesConfigTypes
{
    public function setAttribute($key, $value)
    {
        if ($key === 'is_active') {
            $value = (bool) $value;
        } elseif (in_array($key, $this->integerWriteAttributes(), true)) {
            $value = $value === null ? null : (int) $value;
        }

        return parent::setAttribute($key, $value);
    }

    protected function integerWriteAttributes(): array
    {
        return [];
    }
}
