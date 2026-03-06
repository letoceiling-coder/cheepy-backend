<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the initial set of attribute extraction rules and synonyms
 * derived from the full product description audit (1535 products).
 *
 * All patterns are stored as PCRE strings WITHOUT delimiters.
 * The service wraps them: preg_match('/' . $rule->pattern . '/iu', $text, $m)
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ─────────────────────────────────────────────────────────────
        // ATTRIBUTE RULES
        // ─────────────────────────────────────────────────────────────
        $rules = [

            // ── SIZE ──────────────────────────────────────────────────
            // "Размеры: S, M, L, XL" / "Размер: M L XL"
            [
                'attribute_key' => 'size',
                'display_name'  => 'Размер',
                'rule_type'     => 'regex',
                'pattern'       => '(?:размер[ыи]?|size)\s*[:\-]?\s*([XS]{0,2}(?:XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL)[\s,\-\/]+(?:[XS]{0,2}(?:XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL)[\s,\-\/]*)+)',
                'attr_type'     => 'size',
                'priority'      => 10,
                'apply_synonyms'=> false,
            ],
            // "S, M, L" / "M L XL" standalone run of letter sizes
            [
                'attribute_key' => 'size',
                'display_name'  => 'Размер',
                'rule_type'     => 'regex',
                'pattern'       => '(?<![а-яё\w])((?:XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL)(?:[\s,\-\/]+(?:XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL))+)(?![а-яё\w])',
                'attr_type'     => 'size',
                'priority'      => 20,
                'apply_synonyms'=> false,
            ],
            // "S-(40-42) M-(42-44) L-(44-46)" bracket notation
            [
                'attribute_key' => 'size',
                'display_name'  => 'Размер',
                'rule_type'     => 'regex',
                'pattern'       => '((?:[XS]{0,2}(?:XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL)\s*[\-–]\s*\(\d{2}[\-–]\d{2}\)\s*)+)',
                'attr_type'     => 'size',
                'priority'      => 15,
                'apply_synonyms'=> false,
            ],
            // Numeric size range: "42-44-46-48" / "42 44 46 48"
            [
                'attribute_key' => 'size',
                'display_name'  => 'Размер',
                'rule_type'     => 'regex',
                'pattern'       => '(?:размер[ыи]?|size)?\s*[:\-]?\s*((?:[3-6]\d)(?:[\s,\-\/]+(?:[3-6]\d))+)',
                'attr_type'     => 'size',
                'priority'      => 30,
                'apply_synonyms'=> false,
            ],
            // "единый 42-48" / "единый (42-52)"
            [
                'attribute_key' => 'size',
                'display_name'  => 'Размер',
                'rule_type'     => 'regex',
                'pattern'       => 'единый\s*\(?\s*([3-6]\d\s*[\-–]\s*[3-6]\d)\s*\)?',
                'attr_type'     => 'size',
                'priority'      => 25,
                'apply_synonyms'=> false,
            ],

            // ── MATERIAL / COMPOSITION ────────────────────────────────
            // "Состав: 95% хлопок 5% эластан" / "Ткань: хлопок"
            [
                'attribute_key' => 'material',
                'display_name'  => 'Состав / Материал',
                'rule_type'     => 'regex',
                'pattern'       => '(?:состав|ткань|материал|fabric|composition)\s*[:\-]?\s*([^\n\r\.]{5,120})',
                'attr_type'     => 'text',
                'priority'      => 10,
                'apply_synonyms'=> true,
            ],
            // "95% ХЛОПОК 5% ЭЛАСТАН" (percentage followed by material name)
            [
                'attribute_key' => 'material',
                'display_name'  => 'Состав / Материал',
                'rule_type'     => 'regex',
                'pattern'       => '(\d{1,3}%\s*[а-яёa-z]+(?:\s+\d{1,3}%\s*[а-яёa-z]+)*)',
                'attr_type'     => 'text',
                'priority'      => 20,
                'apply_synonyms'=> true,
            ],

            // ── BRAND ─────────────────────────────────────────────────
            [
                'attribute_key' => 'brand',
                'display_name'  => 'Бренд',
                'rule_type'     => 'regex',
                'pattern'       => '(?:бренд|brand)\s*[:\-]?\s*[«»,,\"\']*([A-Za-zА-Яа-яёЁ0-9\s\-]+?)[«»,,\"\']*(?:\s|$|\n)',
                'attr_type'     => 'text',
                'priority'      => 10,
                'apply_synonyms'=> false,
            ],

            // ── COUNTRY OF ORIGIN ─────────────────────────────────────
            // "Пр.-во: Турция" / "Производство: Китай" / "Made in Turkey"
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'regex',
                'pattern'       => '(?:пр\.?-?во|производств\w*|страна\s+произв\w*|сделано\s+в|made\s+in|производитель)\s*[:\-]?\s*([А-Яа-яёA-Za-z\-]+)',
                'attr_type'     => 'text',
                'priority'      => 10,
                'apply_synonyms'=> true,
            ],
            // "Фабрика Китай" / "фабричный Китай"
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'regex',
                'pattern'       => '(?:фабрик\w*|производств\w*)\s+(китай|турция|россия|узбекистан|киргиз\w*|беларус\w*|корея|италия|польша|бангладеш)',
                'attr_type'     => 'text',
                'priority'      => 20,
                'apply_synonyms'=> true,
            ],
            // standalone country keyword (lower priority fallback)
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'keyword',
                'pattern'       => 'китай',
                'attr_type'     => 'text',
                'priority'      => 50,
                'apply_synonyms'=> true,
            ],
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'keyword',
                'pattern'       => 'турция',
                'attr_type'     => 'text',
                'priority'      => 50,
                'apply_synonyms'=> true,
            ],
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'keyword',
                'pattern'       => 'turkey',
                'attr_type'     => 'text',
                'priority'      => 50,
                'apply_synonyms'=> true,
            ],
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'keyword',
                'pattern'       => 'china',
                'attr_type'     => 'text',
                'priority'      => 50,
                'apply_synonyms'=> true,
            ],
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'keyword',
                'pattern'       => 'россия',
                'attr_type'     => 'text',
                'priority'      => 50,
                'apply_synonyms'=> true,
            ],
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'keyword',
                'pattern'       => 'узбекистан',
                'attr_type'     => 'text',
                'priority'      => 50,
                'apply_synonyms'=> true,
            ],
            [
                'attribute_key' => 'country_of_origin',
                'display_name'  => 'Страна производства',
                'rule_type'     => 'keyword',
                'pattern'       => 'беларусь',
                'attr_type'     => 'text',
                'priority'      => 50,
                'apply_synonyms'=> true,
            ],

            // ── ARTICLE / SKU ─────────────────────────────────────────
            [
                'attribute_key' => 'article',
                'display_name'  => 'Артикул',
                'rule_type'     => 'regex',
                'pattern'       => '(?:артикул|арт\.?)\s*[:\-]?\s*[«»,,\"\']*([A-Za-zА-Яа-я0-9\-\_\/\.\s]{2,40})',
                'attr_type'     => 'text',
                'priority'      => 10,
                'apply_synonyms'=> false,
            ],

            // ── COLOR ─────────────────────────────────────────────────
            // "Цвет: черный, белый, бежевый" / "Цвета: ..."
            [
                'attribute_key' => 'color',
                'display_name'  => 'Цвет',
                'rule_type'     => 'regex',
                'pattern'       => '(?:цвет[аА]?|color)\s*[:\-]?\s*([А-Яа-яёa-zA-Z][А-Яа-яёa-zA-Z\s,;\/]+?)(?:\n|\r|Размер|Состав|Ткань|Бренд|Арт|$)',
                'attr_type'     => 'color',
                'priority'      => 10,
                'apply_synonyms'=> false,
            ],

            // ── PACK QUANTITY ─────────────────────────────────────────
            // "4×200=800", "уп 6х300", "в упаковке 5 шт"
            [
                'attribute_key' => 'pack_quantity',
                'display_name'  => 'Кол-во в упаковке',
                'rule_type'     => 'regex',
                'pattern'       => '(?:в\s+упак\w*|упак\w*)\s*[\(]?\s*(\d+)\s*(?:шт)?',
                'attr_type'     => 'number',
                'priority'      => 10,
                'apply_synonyms'=> false,
            ],
            // "4×200" or "4x300" means 4 pcs in pack
            [
                'attribute_key' => 'pack_quantity',
                'display_name'  => 'Кол-во в упаковке',
                'rule_type'     => 'regex',
                'pattern'       => '(\d+)\s*[×хx]\s*\d+\s*=\s*\d+',
                'attr_type'     => 'number',
                'priority'      => 20,
                'apply_synonyms'=> false,
            ],
            // "(размерный ряд-4штуки)"
            [
                'attribute_key' => 'pack_quantity',
                'display_name'  => 'Кол-во в упаковке',
                'rule_type'     => 'regex',
                'pattern'       => 'размерный\s+ряд[\-\s]*(\d+)\s*штук',
                'attr_type'     => 'number',
                'priority'      => 15,
                'apply_synonyms'=> false,
            ],
        ];

        foreach ($rules as $rule) {
            DB::table('attribute_rules')->insert(array_merge($rule, [
                'enabled'    => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // ─────────────────────────────────────────────────────────────
        // SYNONYMS
        // ─────────────────────────────────────────────────────────────
        $synonyms = [
            // material synonyms
            ['attribute_key' => 'material', 'word' => 'cotton',      'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'word' => 'х/б',         'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'word' => 'хб',          'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'word' => 'х\\б',        'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'word' => 'хлопковый',   'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'word' => 'polyester',   'normalized_value' => 'полиэстер'],
            ['attribute_key' => 'material', 'word' => 'п/э',         'normalized_value' => 'полиэстер'],
            ['attribute_key' => 'material', 'word' => 'spandex',     'normalized_value' => 'эластан'],
            ['attribute_key' => 'material', 'word' => 'elastane',    'normalized_value' => 'эластан'],
            ['attribute_key' => 'material', 'word' => 'эластик',     'normalized_value' => 'эластан'],
            ['attribute_key' => 'material', 'word' => 'viscose',     'normalized_value' => 'вискоза'],
            ['attribute_key' => 'material', 'word' => 'rayon',       'normalized_value' => 'вискоза'],
            ['attribute_key' => 'material', 'word' => 'acrylic',     'normalized_value' => 'акрил'],
            ['attribute_key' => 'material', 'word' => 'wool',        'normalized_value' => 'шерсть'],
            ['attribute_key' => 'material', 'word' => 'шерстяной',   'normalized_value' => 'шерсть'],
            ['attribute_key' => 'material', 'word' => 'linen',       'normalized_value' => 'лён'],
            ['attribute_key' => 'material', 'word' => 'льняной',     'normalized_value' => 'лён'],
            ['attribute_key' => 'material', 'word' => 'бамбук',      'normalized_value' => 'бамбук'],
            ['attribute_key' => 'material', 'word' => 'bamboo',      'normalized_value' => 'бамбук'],
            ['attribute_key' => 'material', 'word' => 'кашемир',     'normalized_value' => 'кашемир'],
            ['attribute_key' => 'material', 'word' => 'cashmere',    'normalized_value' => 'кашемир'],
            ['attribute_key' => 'material', 'word' => 'пайетки',     'normalized_value' => 'пайетки'],
            ['attribute_key' => 'material', 'word' => 'лайкра',      'normalized_value' => 'лайкра'],
            ['attribute_key' => 'material', 'word' => 'lycra',       'normalized_value' => 'лайкра'],
            ['attribute_key' => 'material', 'word' => 'двухнитка',   'normalized_value' => 'двухнитка'],
            ['attribute_key' => 'material', 'word' => 'флис',        'normalized_value' => 'флис'],
            ['attribute_key' => 'material', 'word' => 'fleece',      'normalized_value' => 'флис'],

            // country synonyms
            ['attribute_key' => 'country_of_origin', 'word' => 'china',       'normalized_value' => 'Китай'],
            ['attribute_key' => 'country_of_origin', 'word' => 'chinese',     'normalized_value' => 'Китай'],
            ['attribute_key' => 'country_of_origin', 'word' => 'turkey',      'normalized_value' => 'Турция'],
            ['attribute_key' => 'country_of_origin', 'word' => 'турецкий',    'normalized_value' => 'Турция'],
            ['attribute_key' => 'country_of_origin', 'word' => 'russia',      'normalized_value' => 'Россия'],
            ['attribute_key' => 'country_of_origin', 'word' => 'russian',     'normalized_value' => 'Россия'],
            ['attribute_key' => 'country_of_origin', 'word' => 'uzbekistan',  'normalized_value' => 'Узбекистан'],
            ['attribute_key' => 'country_of_origin', 'word' => 'kyrgyzstan',  'normalized_value' => 'Кыргызстан'],
            ['attribute_key' => 'country_of_origin', 'word' => 'киргизия',    'normalized_value' => 'Кыргызстан'],
            ['attribute_key' => 'country_of_origin', 'word' => 'беларусь',    'normalized_value' => 'Беларусь'],
            ['attribute_key' => 'country_of_origin', 'word' => 'белоруссия',  'normalized_value' => 'Беларусь'],
            ['attribute_key' => 'country_of_origin', 'word' => 'belarus',     'normalized_value' => 'Беларусь'],
            ['attribute_key' => 'country_of_origin', 'word' => 'italy',       'normalized_value' => 'Италия'],
            ['attribute_key' => 'country_of_origin', 'word' => 'korea',       'normalized_value' => 'Корея'],
            ['attribute_key' => 'country_of_origin', 'word' => 'корея',       'normalized_value' => 'Корея'],
            ['attribute_key' => 'country_of_origin', 'word' => 'польша',      'normalized_value' => 'Польша'],
            ['attribute_key' => 'country_of_origin', 'word' => 'poland',      'normalized_value' => 'Польша'],
            ['attribute_key' => 'country_of_origin', 'word' => 'bangladesh',  'normalized_value' => 'Бангладеш'],
            ['attribute_key' => 'country_of_origin', 'word' => 'бангладеш',   'normalized_value' => 'Бангладеш'],
        ];

        foreach ($synonyms as $s) {
            DB::table('attribute_synonyms')->insert(array_merge($s, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        DB::table('attribute_synonyms')->truncate();
        DB::table('attribute_rules')->truncate();
    }
};
