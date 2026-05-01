<?php

namespace App\Support;

/**
 * Нормализация списка slug продавцов, исключаемых из парсинга товаров.
 */
final class ParserExcludedSellers
{
    /**
     * @return list<string> уникальные непустые slug в нижнем регистре
     */
    public static function normalizeList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $map = [];
        foreach ($raw as $item) {
            $s = strtolower(trim((string) $item));
            if ($s !== '') {
                $map[$s] = true;
            }
        }

        return array_keys($map);
    }
}
