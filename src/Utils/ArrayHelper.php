<?php

declare(strict_types=1);

namespace App\Utils;

class ArrayHelper
{
    public static function safe_merge_array(string|array|null $first, string|array|null $second): array
    {
        $first = $first ?? [];
        $second = $second ?? [];

        return array_merge(is_array($first) ? $first : [$first], is_array($second) ? $second : [$second]);
    }
}
