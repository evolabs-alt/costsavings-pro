<?php

namespace CostSavings;

/**
 * String helpers with fallbacks when ext-mbstring is not installed.
 */
class StringUtil
{
    public static function strtolower(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
