<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('parser_settings', 'incremental_tail_pages')) {
                // Глубина early-exit для режима «только новые»: N страниц подряд, на которых
                // все товары уже в БД → выходим из категории. Раньше было захардкожено 2,
                // при 2 часто пропускали новые товары, вытесненные на 3-4 страницу при
                // активных продавцах. Диапазон 1..10 в UI.
                $table->unsignedTinyInteger('incremental_tail_pages')->default(3)->after('update_existing');
            }
            if (! Schema::hasColumn('parser_settings', 'update_availability_only')) {
                // Режим «Обновление: только доступность». Активен, когда update_existing=true.
                // true  → для existing external_id: batch UPDATE is_relevant=true, relevance_checked_at=NOW()
                //         (плюс цена/остаток из листинга). БЕЗ HTTP на детали товара. ~10x быстрее.
                //         После прохода категории товары, которых мы не увидели, помечаются
                //         is_relevant=false (без смены status).
                // false → старое поведение: полный upsert с деталями для каждого товара
                //         (медленно, нужно редко — для ручного re-extract характеристик).
                $table->boolean('update_availability_only')->default(true)->after('incremental_tail_pages');
            }
            if (! Schema::hasColumn('parser_settings', 'daemon_interval_seconds')) {
                // Интервал между проверками ParserDaemonJob. Раньше было захардкожено 60с:
                // демон просыпался каждую минуту → full-прогон 4 мин + 1 мин пауза = 12/час.
                // Это 288 обходов донора в сутки — избыточно для частоты появления новых
                // объявлений, повышает риск rate-limit. Дефолт 180с (6/ч) — свежесть 3-5 мин.
                // Clamp 30..600 (UI валидирует). Применяется и при «run already in progress»,
                // и при «queue has pending», и при успешном старте нового ParserJob.
                $table->unsignedSmallInteger('daemon_interval_seconds')->default(180)->after('update_availability_only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            foreach (['daemon_interval_seconds', 'update_availability_only', 'incremental_tail_pages'] as $col) {
                if (Schema::hasColumn('parser_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
