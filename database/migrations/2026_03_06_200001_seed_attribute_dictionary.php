<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── DICTIONARY ─────────────────────────────────────────────────────
        $dict = [
            // sizes (letter)
            ['attribute_key' => 'size', 'value' => 'XS',  'sort_order' => 1],
            ['attribute_key' => 'size', 'value' => 'S',   'sort_order' => 2],
            ['attribute_key' => 'size', 'value' => 'M',   'sort_order' => 3],
            ['attribute_key' => 'size', 'value' => 'L',   'sort_order' => 4],
            ['attribute_key' => 'size', 'value' => 'XL',  'sort_order' => 5],
            ['attribute_key' => 'size', 'value' => 'XXL', 'sort_order' => 6],
            ['attribute_key' => 'size', 'value' => '2XL', 'sort_order' => 7],
            ['attribute_key' => 'size', 'value' => '3XL', 'sort_order' => 8],
            ['attribute_key' => 'size', 'value' => '4XL', 'sort_order' => 9],
            ['attribute_key' => 'size', 'value' => '5XL', 'sort_order' => 10],
            // sizes (numeric)
            ['attribute_key' => 'size', 'value' => '36', 'sort_order' => 11],
            ['attribute_key' => 'size', 'value' => '38', 'sort_order' => 12],
            ['attribute_key' => 'size', 'value' => '40', 'sort_order' => 13],
            ['attribute_key' => 'size', 'value' => '42', 'sort_order' => 14],
            ['attribute_key' => 'size', 'value' => '44', 'sort_order' => 15],
            ['attribute_key' => 'size', 'value' => '46', 'sort_order' => 16],
            ['attribute_key' => 'size', 'value' => '48', 'sort_order' => 17],
            ['attribute_key' => 'size', 'value' => '50', 'sort_order' => 18],
            ['attribute_key' => 'size', 'value' => '52', 'sort_order' => 19],
            ['attribute_key' => 'size', 'value' => '54', 'sort_order' => 20],
            ['attribute_key' => 'size', 'value' => '56', 'sort_order' => 21],
            ['attribute_key' => 'size', 'value' => '58', 'sort_order' => 22],
            ['attribute_key' => 'size', 'value' => '60', 'sort_order' => 23],
            ['attribute_key' => 'size', 'value' => '62', 'sort_order' => 24],
            ['attribute_key' => 'size', 'value' => '64', 'sort_order' => 25],
            ['attribute_key' => 'size', 'value' => '66', 'sort_order' => 26],
            // size ranges
            ['attribute_key' => 'size', 'value' => '42-48',    'sort_order' => 50],
            ['attribute_key' => 'size', 'value' => '42-50',    'sort_order' => 51],
            ['attribute_key' => 'size', 'value' => '42-52',    'sort_order' => 52],
            ['attribute_key' => 'size', 'value' => '44-54',    'sort_order' => 53],
            ['attribute_key' => 'size', 'value' => '50-60',    'sort_order' => 54],
            ['attribute_key' => 'size', 'value' => 'единый',   'sort_order' => 60],

            // country
            ['attribute_key' => 'country_of_origin', 'value' => 'Китай',       'sort_order' => 1],
            ['attribute_key' => 'country_of_origin', 'value' => 'Турция',      'sort_order' => 2],
            ['attribute_key' => 'country_of_origin', 'value' => 'Россия',      'sort_order' => 3],
            ['attribute_key' => 'country_of_origin', 'value' => 'Узбекистан',  'sort_order' => 4],
            ['attribute_key' => 'country_of_origin', 'value' => 'Кыргызстан', 'sort_order' => 5],
            ['attribute_key' => 'country_of_origin', 'value' => 'Беларусь',    'sort_order' => 6],
            ['attribute_key' => 'country_of_origin', 'value' => 'Индия',       'sort_order' => 7],
            ['attribute_key' => 'country_of_origin', 'value' => 'Бангладеш',   'sort_order' => 8],
            ['attribute_key' => 'country_of_origin', 'value' => 'Корея',       'sort_order' => 9],
            ['attribute_key' => 'country_of_origin', 'value' => 'Италия',      'sort_order' => 10],
            ['attribute_key' => 'country_of_origin', 'value' => 'Польша',      'sort_order' => 11],
            ['attribute_key' => 'country_of_origin', 'value' => 'Вьетнам',     'sort_order' => 12],

            // material
            ['attribute_key' => 'material', 'value' => 'хлопок',      'sort_order' => 1],
            ['attribute_key' => 'material', 'value' => 'вискоза',      'sort_order' => 2],
            ['attribute_key' => 'material', 'value' => 'полиэстер',    'sort_order' => 3],
            ['attribute_key' => 'material', 'value' => 'эластан',      'sort_order' => 4],
            ['attribute_key' => 'material', 'value' => 'лайкра',       'sort_order' => 5],
            ['attribute_key' => 'material', 'value' => 'шерсть',       'sort_order' => 6],
            ['attribute_key' => 'material', 'value' => 'акрил',        'sort_order' => 7],
            ['attribute_key' => 'material', 'value' => 'кашемир',      'sort_order' => 8],
            ['attribute_key' => 'material', 'value' => 'лён',          'sort_order' => 9],
            ['attribute_key' => 'material', 'value' => 'бамбук',       'sort_order' => 10],
            ['attribute_key' => 'material', 'value' => 'флис',         'sort_order' => 11],
            ['attribute_key' => 'material', 'value' => 'микрофибра',   'sort_order' => 12],
            ['attribute_key' => 'material', 'value' => 'трикотаж',     'sort_order' => 13],
            ['attribute_key' => 'material', 'value' => 'джинс',        'sort_order' => 14],
            ['attribute_key' => 'material', 'value' => 'кожа',         'sort_order' => 15],
            ['attribute_key' => 'material', 'value' => 'экокожа',      'sort_order' => 16],
            ['attribute_key' => 'material', 'value' => 'нейлон',       'sort_order' => 17],
            ['attribute_key' => 'material', 'value' => 'синтепон',     'sort_order' => 18],

            // color
            ['attribute_key' => 'color', 'value' => 'белый',        'sort_order' => 1],
            ['attribute_key' => 'color', 'value' => 'чёрный',       'sort_order' => 2],
            ['attribute_key' => 'color', 'value' => 'серый',        'sort_order' => 3],
            ['attribute_key' => 'color', 'value' => 'бежевый',      'sort_order' => 4],
            ['attribute_key' => 'color', 'value' => 'коричневый',   'sort_order' => 5],
            ['attribute_key' => 'color', 'value' => 'красный',      'sort_order' => 6],
            ['attribute_key' => 'color', 'value' => 'розовый',      'sort_order' => 7],
            ['attribute_key' => 'color', 'value' => 'синий',        'sort_order' => 8],
            ['attribute_key' => 'color', 'value' => 'голубой',      'sort_order' => 9],
            ['attribute_key' => 'color', 'value' => 'зелёный',      'sort_order' => 10],
            ['attribute_key' => 'color', 'value' => 'жёлтый',       'sort_order' => 11],
            ['attribute_key' => 'color', 'value' => 'оранжевый',    'sort_order' => 12],
            ['attribute_key' => 'color', 'value' => 'фиолетовый',   'sort_order' => 13],
            ['attribute_key' => 'color', 'value' => 'бордовый',     'sort_order' => 14],
            ['attribute_key' => 'color', 'value' => 'мятный',       'sort_order' => 15],
            ['attribute_key' => 'color', 'value' => 'хаки',         'sort_order' => 16],
            ['attribute_key' => 'color', 'value' => 'молочный',     'sort_order' => 17],
            ['attribute_key' => 'color', 'value' => 'марсала',      'sort_order' => 18],
            ['attribute_key' => 'color', 'value' => 'разноцветный', 'sort_order' => 19],

            // future attributes — gender, season, fit
            ['attribute_key' => 'gender', 'value' => 'женский',    'sort_order' => 1],
            ['attribute_key' => 'gender', 'value' => 'мужской',    'sort_order' => 2],
            ['attribute_key' => 'gender', 'value' => 'унисекс',    'sort_order' => 3],
            ['attribute_key' => 'gender', 'value' => 'детский',    'sort_order' => 4],

            ['attribute_key' => 'season', 'value' => 'весна-лето',   'sort_order' => 1],
            ['attribute_key' => 'season', 'value' => 'осень-зима',   'sort_order' => 2],
            ['attribute_key' => 'season', 'value' => 'всесезонный',  'sort_order' => 3],

            ['attribute_key' => 'fit', 'value' => 'свободный',   'sort_order' => 1],
            ['attribute_key' => 'fit', 'value' => 'приталенный', 'sort_order' => 2],
            ['attribute_key' => 'fit', 'value' => 'оверсайз',    'sort_order' => 3],
            ['attribute_key' => 'fit', 'value' => 'прямой',      'sort_order' => 4],
        ];

        foreach ($dict as $row) {
            DB::table('attribute_dictionary')->insertOrIgnore(array_merge($row, [
                'created_at' => $now, 'updated_at' => $now,
            ]));
        }

        // ── CANONICAL NORMALIZATION ────────────────────────────────────────
        $canon = [
            // country
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'turkey',       'normalized_value' => 'Турция'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'made in turkey','normalized_value' => 'Турция'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'турция',        'normalized_value' => 'Турция'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'турецкий',      'normalized_value' => 'Турция'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'китай',         'normalized_value' => 'Китай'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'china',         'normalized_value' => 'Китай'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'made in china', 'normalized_value' => 'Китай'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'chinese',       'normalized_value' => 'Китай'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'фабрика китай', 'normalized_value' => 'Китай'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'россия',        'normalized_value' => 'Россия'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'russia',        'normalized_value' => 'Россия'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'российский',    'normalized_value' => 'Россия'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'узбекистан',    'normalized_value' => 'Узбекистан'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'uzbekistan',    'normalized_value' => 'Узбекистан'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'киргизия',      'normalized_value' => 'Кыргызстан'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'кыргызстан',    'normalized_value' => 'Кыргызстан'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'kyrgyzstan',    'normalized_value' => 'Кыргызстан'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'беларусь',      'normalized_value' => 'Беларусь'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'белоруссия',    'normalized_value' => 'Беларусь'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'belarus',       'normalized_value' => 'Беларусь'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'india',         'normalized_value' => 'Индия'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'индия',         'normalized_value' => 'Индия'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'korea',         'normalized_value' => 'Корея'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'корея',         'normalized_value' => 'Корея'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'italy',         'normalized_value' => 'Италия'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'италия',        'normalized_value' => 'Италия'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'vietnam',       'normalized_value' => 'Вьетнам'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'вьетнам',       'normalized_value' => 'Вьетнам'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'bangladesh',    'normalized_value' => 'Бангладеш'],
            ['attribute_key' => 'country_of_origin', 'raw_value' => 'бангладеш',     'normalized_value' => 'Бангладеш'],

            // material
            ['attribute_key' => 'material', 'raw_value' => 'cotton',      'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'raw_value' => 'х/б',         'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'raw_value' => 'хб',          'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'raw_value' => 'хлопковый',   'normalized_value' => 'хлопок'],
            ['attribute_key' => 'material', 'raw_value' => 'polyester',   'normalized_value' => 'полиэстер'],
            ['attribute_key' => 'material', 'raw_value' => 'п/э',         'normalized_value' => 'полиэстер'],
            ['attribute_key' => 'material', 'raw_value' => 'spandex',     'normalized_value' => 'эластан'],
            ['attribute_key' => 'material', 'raw_value' => 'elastane',    'normalized_value' => 'эластан'],
            ['attribute_key' => 'material', 'raw_value' => 'эластик',     'normalized_value' => 'эластан'],
            ['attribute_key' => 'material', 'raw_value' => 'viscose',     'normalized_value' => 'вискоза'],
            ['attribute_key' => 'material', 'raw_value' => 'rayon',       'normalized_value' => 'вискоза'],
            ['attribute_key' => 'material', 'raw_value' => 'acrylic',     'normalized_value' => 'акрил'],
            ['attribute_key' => 'material', 'raw_value' => 'wool',        'normalized_value' => 'шерсть'],
            ['attribute_key' => 'material', 'raw_value' => 'шерстяной',   'normalized_value' => 'шерсть'],
            ['attribute_key' => 'material', 'raw_value' => 'linen',       'normalized_value' => 'лён'],
            ['attribute_key' => 'material', 'raw_value' => 'льняной',     'normalized_value' => 'лён'],
            ['attribute_key' => 'material', 'raw_value' => 'bamboo',      'normalized_value' => 'бамбук'],
            ['attribute_key' => 'material', 'raw_value' => 'fleece',      'normalized_value' => 'флис'],
            ['attribute_key' => 'material', 'raw_value' => 'cashmere',    'normalized_value' => 'кашемир'],
            ['attribute_key' => 'material', 'raw_value' => 'lycra',       'normalized_value' => 'лайкра'],
            ['attribute_key' => 'material', 'raw_value' => 'nylon',       'normalized_value' => 'нейлон'],
            ['attribute_key' => 'material', 'raw_value' => 'нейлоновый',  'normalized_value' => 'нейлон'],
            ['attribute_key' => 'material', 'raw_value' => 'синтетика',   'normalized_value' => 'полиэстер'],
            ['attribute_key' => 'material', 'raw_value' => 'microfiber',  'normalized_value' => 'микрофибра'],

            // size aliases
            ['attribute_key' => 'size', 'raw_value' => 'xxl',      'normalized_value' => 'XXL'],
            ['attribute_key' => 'size', 'raw_value' => '2xl',       'normalized_value' => '2XL'],
            ['attribute_key' => 'size', 'raw_value' => '3xl',       'normalized_value' => '3XL'],
            ['attribute_key' => 'size', 'raw_value' => 'oversize',  'normalized_value' => 'единый'],
            ['attribute_key' => 'size', 'raw_value' => 'оверсайз',  'normalized_value' => 'единый'],
            ['attribute_key' => 'size', 'raw_value' => 'one size',  'normalized_value' => 'единый'],
            ['attribute_key' => 'size', 'raw_value' => 'free size', 'normalized_value' => 'единый'],

            // color
            ['attribute_key' => 'color', 'raw_value' => 'black',       'normalized_value' => 'чёрный'],
            ['attribute_key' => 'color', 'raw_value' => 'white',       'normalized_value' => 'белый'],
            ['attribute_key' => 'color', 'raw_value' => 'beige',       'normalized_value' => 'бежевый'],
            ['attribute_key' => 'color', 'raw_value' => 'grey',        'normalized_value' => 'серый'],
            ['attribute_key' => 'color', 'raw_value' => 'gray',        'normalized_value' => 'серый'],
            ['attribute_key' => 'color', 'raw_value' => 'red',         'normalized_value' => 'красный'],
            ['attribute_key' => 'color', 'raw_value' => 'pink',        'normalized_value' => 'розовый'],
            ['attribute_key' => 'color', 'raw_value' => 'blue',        'normalized_value' => 'синий'],
            ['attribute_key' => 'color', 'raw_value' => 'green',       'normalized_value' => 'зелёный'],
            ['attribute_key' => 'color', 'raw_value' => 'brown',       'normalized_value' => 'коричневый'],
            ['attribute_key' => 'color', 'raw_value' => 'yellow',      'normalized_value' => 'жёлтый'],
            ['attribute_key' => 'color', 'raw_value' => 'orange',      'normalized_value' => 'оранжевый'],
            ['attribute_key' => 'color', 'raw_value' => 'purple',      'normalized_value' => 'фиолетовый'],
            ['attribute_key' => 'color', 'raw_value' => 'violet',      'normalized_value' => 'фиолетовый'],
            ['attribute_key' => 'color', 'raw_value' => 'шоколадный',  'normalized_value' => 'коричневый'],
            ['attribute_key' => 'color', 'raw_value' => 'кофе',        'normalized_value' => 'коричневый'],
            ['attribute_key' => 'color', 'raw_value' => 'табак',       'normalized_value' => 'коричневый'],
            ['attribute_key' => 'color', 'raw_value' => 'молоко',      'normalized_value' => 'молочный'],
            ['attribute_key' => 'color', 'raw_value' => 'слоновая кость','normalized_value' => 'молочный'],
            ['attribute_key' => 'color', 'raw_value' => 'айвори',      'normalized_value' => 'молочный'],
            ['attribute_key' => 'color', 'raw_value' => 'ivory',       'normalized_value' => 'молочный'],
            ['attribute_key' => 'color', 'raw_value' => 'cream',       'normalized_value' => 'молочный'],
            ['attribute_key' => 'color', 'raw_value' => 'мультиколор', 'normalized_value' => 'разноцветный'],
            ['attribute_key' => 'color', 'raw_value' => 'multicolor',  'normalized_value' => 'разноцветный'],
            ['attribute_key' => 'color', 'raw_value' => 'wine',        'normalized_value' => 'бордовый'],
            ['attribute_key' => 'color', 'raw_value' => 'бордо',       'normalized_value' => 'бордовый'],
            ['attribute_key' => 'color', 'raw_value' => 'винный',      'normalized_value' => 'бордовый'],
            ['attribute_key' => 'color', 'raw_value' => 'баклажан',    'normalized_value' => 'фиолетовый'],
            ['attribute_key' => 'color', 'raw_value' => 'grafite',     'normalized_value' => 'серый'],
            ['attribute_key' => 'color', 'raw_value' => 'графит',      'normalized_value' => 'серый'],
        ];

        foreach ($canon as $row) {
            DB::table('attribute_value_normalization')->insertOrIgnore(array_merge($row, [
                'created_at' => $now, 'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        DB::table('attribute_value_normalization')->truncate();
        DB::table('attribute_dictionary')->truncate();
    }
};
