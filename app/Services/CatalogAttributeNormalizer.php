<?php

namespace App\Services;

/**
 * Single production entry point for catalog attribute canonicalization.
 *
 * Contract:
 * - attr_value is always canonical and filter-ready.
 * - attr_value_original keeps the raw parser/CRM value for audit.
 * - one logical value is one DB row, so "46 48 50" becomes 46, 48, 50.
 */
class CatalogAttributeNormalizer
{
    /** @var array<string, string> */
    private const DISPLAY_NAMES = [
        'size' => 'Размер',
        'color' => 'Цвет',
        'material' => 'Состав / Материал',
        'country_of_origin' => 'Страна производства',
        'brand' => 'Бренд',
        'article' => 'Артикул',
        'pack_quantity' => 'Кол-во в упаковке',
        'gender' => 'Пол',
        'season' => 'Сезон',
        'fit' => 'Посадка',
    ];

    /** @var array<string, string> */
    private const COLOR_ALIASES = [
        'черный' => 'Черный',
        'чёрный' => 'Черный',
        'черная' => 'Черный',
        'чёрная' => 'Черный',
        'чeрный' => 'Черный',
        'белый' => 'Белый',
        'белая' => 'Белый',
        'молочный' => 'Молочный',
        'айвори' => 'Молочный',
        'слоновая кость' => 'Молочный',
        'сливочный' => 'Молочный',
        'бежевый' => 'Бежевый',
        'песочный' => 'Бежевый',
        'серый' => 'Серый',
        'серый меланж' => 'Серый',
        'светло-серый' => 'Светло-серый',
        'темно-серый' => 'Темно-серый',
        'мокрый асфальт' => 'Темно-серый',
        'графит' => 'Темно-серый',
        'синий' => 'Синий',
        'темно-синий' => 'Темно-синий',
        'васильковый' => 'Синий',
        'электрик' => 'Синий',
        'голубой' => 'Голубой',
        'небесный' => 'Голубой',
        'зеленый' => 'Зеленый',
        'зелёный' => 'Зеленый',
        'изумруд' => 'Изумрудный',
        'изумрудный' => 'Изумрудный',
        'хаки' => 'Хаки',
        'олива' => 'Оливковый',
        'оливковый' => 'Оливковый',
        'мята' => 'Мятный',
        'мятный' => 'Мятный',
        'салатовый' => 'Салатовый',
        'фисташка' => 'Фисташковый',
        'фисташковый' => 'Фисташковый',
        'красный' => 'Красный',
        'бордовый' => 'Бордовый',
        'марсала' => 'Бордовый',
        'розовый' => 'Розовый',
        'пудра' => 'Пудровый',
        'пудровый' => 'Пудровый',
        'барби' => 'Розовый',
        'чайная роза' => 'Пудровый',
        'фуксия' => 'Фуксия',
        'фиолетовый' => 'Фиолетовый',
        'сиреневый' => 'Сиреневый',
        'сирень' => 'Сиреневый',
        'лаванда' => 'Лавандовый',
        'лиловый' => 'Лиловый',
        'желтый' => 'Желтый',
        'жёлтый' => 'Желтый',
        'лимон' => 'Желтый',
        'оранжевый' => 'Оранжевый',
        'апельсин' => 'Оранжевый',
        'морковный' => 'Оранжевый',
        'коричневый' => 'Коричневый',
        'шоколад' => 'Коричневый',
        'шоколадный' => 'Коричневый',
        'мокко' => 'Коричневый',
        'капучино' => 'Коричневый',
        'золотистый' => 'Золотой',
        'золотой' => 'Золотой',
        'серебристый' => 'Серебристый',
        'серебряный' => 'Серебристый',
        'бирюзовый' => 'Бирюзовый',
        'морская волна' => 'Бирюзовый',
        'персик' => 'Персиковый',
        'персиковый' => 'Персиковый',
        'лавандовый' => 'Лавандовый',
        'разноцветный' => 'Разноцветный',
        'мультиколор' => 'Разноцветный',
    ];

    /** @var list<string> */
    private const COLOR_REJECT_PATTERNS = [
        'как на фото',
        'без выбора',
        'нет выбора',
        'любой',
        'ассорти',
        'разные',
        'единые',
        'женские ',
        'мужские ',
        'костюм',
        'пиджак',
        'жилет',
        'плать',
        'массаж',
        'хлопок',
        'атлас',
        'качество',
        'фото с рынка',
    ];

    /** @var array<string, string> */
    private const MATERIAL_ALIASES = [
        'х/б' => 'Хлопок',
        'хб' => 'Хлопок',
        'cotton' => 'Хлопок',
        'хлопок' => 'Хлопок',
        'полиэстер' => 'Полиэстер',
        'polyester' => 'Полиэстер',
        'эластан' => 'Эластан',
        'elastane' => 'Эластан',
        'спандекс' => 'Эластан',
        'spandex' => 'Эластан',
        'вискоза' => 'Вискоза',
        'viscose' => 'Вискоза',
        'лен' => 'Лен',
        'лён' => 'Лен',
        'шерсть' => 'Шерсть',
        'wool' => 'Шерсть',
        'акрил' => 'Акрил',
        'нейлон' => 'Нейлон',
        'полиамид' => 'Полиамид',
        'кожа' => 'Кожа',
        'экокожа' => 'Экокожа',
        'замша' => 'Замша',
        'джинс' => 'Деним',
        'деним' => 'Деним',
    ];

    /** @var array<string, string> */
    private const COUNTRY_ALIASES = [
        'китай' => 'Китай',
        'china' => 'Китай',
        'турция' => 'Турция',
        'turkey' => 'Турция',
        'россия' => 'Россия',
        'рф' => 'Россия',
        'корея' => 'Корея',
        'korea' => 'Корея',
        'узбекистан' => 'Узбекистан',
        'киргизия' => 'Киргизия',
        'кыргызстан' => 'Киргизия',
        'беларусь' => 'Беларусь',
        'белоруссия' => 'Беларусь',
        'италия' => 'Италия',
        'польша' => 'Польша',
        'бангладеш' => 'Бангладеш',
    ];

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalizeExtractedRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out = array_merge($out, $this->normalizeAttribute(
                (string) ($row['attribute_key'] ?? ''),
                (string) ($row['attr_name'] ?? ''),
                (string) ($row['attr_value'] ?? ''),
                (string) ($row['attr_type'] ?? 'text'),
                (float) ($row['confidence'] ?? 1.0),
                (string) ($row['match_type'] ?? 'normalized')
            ));
        }

        return $this->dedupe($out);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizeAttribute(
        string $attributeKey,
        string $attrName,
        string $rawValue,
        string $attrType = 'text',
        float $confidence = 1.0,
        string $matchType = 'normalized'
    ): array {
        $key = $this->inferAttributeKey($attributeKey, $attrName);
        $raw = $this->cleanRaw($rawValue);
        if ($raw === '' || $key === null) {
            return [];
        }

        return match ($key) {
            'size' => $this->normalizeSize($raw, $confidence, $matchType),
            'color' => $this->normalizeColor($raw, $confidence, $matchType),
            'material' => $this->normalizeMaterial($raw, $confidence, $matchType),
            'country_of_origin' => $this->normalizeCountry($raw, $confidence, $matchType),
            default => $this->normalizeGeneric($key, $attrName, $raw, $attrType, $confidence, $matchType),
        };
    }

    public function displayName(string $attributeKey): string
    {
        return self::DISPLAY_NAMES[$attributeKey] ?? $attributeKey;
    }

    public function inferAttributeKey(string $attributeKey, string $attrName): ?string
    {
        $key = mb_strtolower(trim($attributeKey));
        if ($key !== '') {
            return $this->canonicalKey($key);
        }

        return $this->canonicalKey(mb_strtolower(trim($attrName)));
    }

    private function canonicalKey(string $key): ?string
    {
        return match ($key) {
            'size', 'размер', 'размеры', 'size_range' => 'size',
            'color', 'цвет', 'цвета' => 'color',
            'material', 'состав', 'материал', 'состав / материал', 'ткань' => 'material',
            'country', 'country_of_origin', 'страна', 'страна производства', 'производство' => 'country_of_origin',
            'brand', 'бренд' => 'brand',
            'article', 'sku', 'артикул' => 'article',
            'pack_quantity', 'кол-во в упаковке', 'количество в упаковке' => 'pack_quantity',
            'gender', 'пол' => 'gender',
            'season', 'сезон' => 'season',
            'fit', 'посадка' => 'fit',
            default => $key !== '' ? $key : null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSize(string $raw, float $confidence, string $matchType): array
    {
        $value = mb_strtoupper(str_replace(['–', '—'], '-', $raw));
        $value = preg_replace('/\b(РАЗМЕРЫ?|SIZE|Р-Р|РР)\b[:\s-]*/u', ' ', $value) ?? $value;
        $value = str_replace(['(', ')'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if ($value === '' || preg_match('/\b(ЦЕНА|РУБ|RUB|₽|ГОД|АРТ|МОДЕЛЬ|РОСТ)\b/u', $value)) {
            return [];
        }

        $sizes = [];
        if (preg_match_all('/\b(XXS|XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL)\b/iu', $value, $m)) {
            foreach ($m[1] as $token) {
                $sizes[] = $this->canonicalLetterSize($token);
            }
        }

        if (preg_match_all('/(?<!\d)(\d{2,3})(?!\d)/u', $value, $m)) {
            $numbers = array_map('intval', $m[1]);
            if (count($numbers) === 2 && preg_match('/\d{2,3}\s*-\s*\d{2,3}/u', $value)) {
                $sizes = array_merge($sizes, $this->expandNumericRange($numbers[0], $numbers[1]));
            } else {
                foreach ($numbers as $n) {
                    if ($this->isValidNumericSize($n)) {
                        $sizes[] = (string) $n;
                    }
                }
            }
        }

        $sizes = array_values(array_unique(array_filter($sizes)));
        if ($sizes === []) {
            return [];
        }

        usort($sizes, fn (string $a, string $b) => $this->sizeSortValue($a) <=> $this->sizeSortValue($b));

        return array_map(
            fn (string $size) => $this->row('size', 'Размер', $size, $raw, 'size', min(1.0, $confidence + 0.05), $matchType),
            $sizes
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeColor(string $raw, float $confidence, string $matchType): array
    {
        $value = $this->normalizeTextToken($raw);
        foreach (self::COLOR_REJECT_PATTERNS as $bad) {
            if (str_contains($value, $bad)) {
                return [];
            }
        }

        $colors = [];
        foreach (self::COLOR_ALIASES as $alias => $canonical) {
            if (preg_match('/(?<![а-яёa-z0-9])'.preg_quote($alias, '/').'(?![а-яёa-z0-9])/iu', $value)) {
                $colors[] = $canonical;
            }
        }

        $colors = array_values(array_unique($colors));
        if ($colors === []) {
            return [];
        }

        return array_map(
            fn (string $color) => $this->row('color', 'Цвет', $color, $raw, 'color', min(1.0, $confidence + 0.05), $matchType),
            $colors
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMaterial(string $raw, float $confidence, string $matchType): array
    {
        $value = $this->normalizeTextToken($raw);
        $materials = [];
        foreach (self::MATERIAL_ALIASES as $alias => $canonical) {
            if (preg_match('/(?<![а-яёa-z0-9])'.preg_quote($alias, '/').'(?![а-яёa-z0-9])/iu', $value)) {
                $materials[] = $canonical;
            }
        }

        if ($materials === []) {
            $candidate = $this->titleCaseRu($value);
            if (mb_strlen($candidate) >= 3 && mb_strlen($candidate) <= 80 && ! preg_match('/\d{3,}|цена|размер|цвет/u', $value)) {
                $materials[] = $candidate;
            }
        }

        return array_map(
            fn (string $material) => $this->row('material', 'Состав / Материал', $material, $raw, 'text', $confidence, $matchType),
            array_values(array_unique($materials))
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCountry(string $raw, float $confidence, string $matchType): array
    {
        $value = $this->normalizeTextToken($raw);
        foreach (self::COUNTRY_ALIASES as $alias => $canonical) {
            if (preg_match('/(?<![а-яёa-z0-9])'.preg_quote($alias, '/').'(?![а-яёa-z0-9])/iu', $value)) {
                return [$this->row('country_of_origin', 'Страна производства', $canonical, $raw, 'text', min(1.0, $confidence + 0.05), $matchType)];
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGeneric(string $key, string $attrName, string $raw, string $attrType, float $confidence, string $matchType): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($value === '' || mb_strlen($value) > 200) {
            return [];
        }

        $displayName = trim($attrName) !== '' ? trim($attrName) : $this->displayName($key);

        return [$this->row($key, $displayName, $this->titleCaseIfNeeded($value), $raw, $attrType, $confidence, $matchType)];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $key, string $name, string $value, string $raw, string $type, float $confidence, string $matchType): array
    {
        return [
            'attribute_key' => $key,
            'attr_name' => $name,
            'attr_value' => $value,
            'attr_value_original' => mb_substr($raw, 0, 500),
            'attr_type' => $type,
            'confidence' => round(max(0.0, min(1.0, $confidence)), 2),
            'match_type' => $matchType,
        ];
    }

    private function cleanRaw(string $raw): string
    {
        $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        return trim($raw, " \t\n\r\0\x0B,.;:");
    }

    private function normalizeTextToken(string $raw): string
    {
        $value = mb_strtolower($this->cleanRaw($raw));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/\b(цвета?|color|выберите|вариант)\b[:\s-]*/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B,.;:");
    }

    private function canonicalLetterSize(string $size): string
    {
        $s = mb_strtoupper(trim($size));
        return match ($s) {
            '2XL' => 'XXL',
            default => $s,
        };
    }

    /**
     * @return list<string>
     */
    private function expandNumericRange(int $from, int $to): array
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (! $this->isValidNumericSize($from) || ! $this->isValidNumericSize($to) || ($to - $from) > 24) {
            return [];
        }

        $step = 2;
        if ($from >= 16 && $to <= 47) {
            $step = 1;
        }
        if ($from >= 74 && $to <= 176) {
            $step = 6;
        }
        if ((($to - $from) % $step) !== 0) {
            $step = 2;
        }

        $out = [];
        for ($n = $from; $n <= $to; $n += $step) {
            if ($this->isValidNumericSize($n)) {
                $out[] = (string) $n;
            }
        }

        return $out;
    }

    private function isValidNumericSize(int $n): bool
    {
        if ($n >= 16 && $n <= 47) {
            return true; // shoes and some clothing edge cases
        }
        if ($n >= 32 && $n <= 72) {
            return true; // adult clothing
        }
        if ($n >= 74 && $n <= 176 && ($n % 2 === 0)) {
            return true; // children height sizes
        }

        return false;
    }

    private function sizeSortValue(string $size): int
    {
        $letterOrder = [
            'XXS' => 1,
            'XS' => 2,
            'S' => 3,
            'M' => 4,
            'L' => 5,
            'XL' => 6,
            'XXL' => 7,
            '3XL' => 8,
            '4XL' => 9,
            '5XL' => 10,
        ];
        if (isset($letterOrder[$size])) {
            return $letterOrder[$size];
        }
        if (ctype_digit($size)) {
            return 100 + (int) $size;
        }

        return 9999;
    }

    private function titleCaseRu(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private function titleCaseIfNeeded(string $value): string
    {
        if (preg_match('/^[а-яё\s-]+$/iu', $value)) {
            return $this->titleCaseRu($value);
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupe(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = ($row['attribute_key'] ?? '')."\0".mb_strtolower((string) ($row['attr_value'] ?? ''));
            if ($key === "\0" || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }
}
