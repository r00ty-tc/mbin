<?php

declare(strict_types=1);

namespace App\Utils;

class HttpStatusHelper
{
    public const TEMP_STATUS_CODES = ['408', '409', '423', '425', '426', '428', '429', '502', '503', '504', '507', '508', '511', '509', '521', '522', '523', '524'];
    public const MISSING_STATUS_CODES = ['404', '410'];

    public static function isStatusCodeTemporary(string $value): bool
    {
        return self::checkStringBeginsWithArray(self::TEMP_STATUS_CODES, $value);
    }

    public static function isStatusCodeMissing(string $value): bool
    {
        return self::checkStringBeginsWithArray(self::MISSING_STATUS_CODES, $value);
    }

    private static function checkStringBeginsWithArray(array $itemList, string $value): bool
    {
        foreach ($itemList as $item) {
            if (str_starts_with($value, $item)) {
                return true;
            }
        }

        return false;
    }
}
