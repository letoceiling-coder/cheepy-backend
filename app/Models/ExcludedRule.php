<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExcludedRule extends Model
{
    protected $fillable = [
        'pattern', 'type', 'action', 'replacement', 'scope',
        'category_id', 'product_type', 'apply_to_fields',
        'expires_at', 'is_active', 'priority', 'comment',
    ];

    protected $casts = [
        'apply_to_fields' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Применить все активные правила к тексту.
     * Возвращает ['text' => ..., 'flagged' => bool, 'hide' => bool, 'delete' => bool]
     */
    public static function applyRules(string $text, string $field = 'title', ?int $categoryId = null): array
    {
        $result = ['text' => $text, 'flagged' => false, 'hide' => false, 'delete' => false];

        $query = static::where('is_active', true)
            ->where(function ($q) use ($categoryId) {
                $q->where('scope', 'global');
                if ($categoryId) {
                    $q->orWhere(function ($q2) use ($categoryId) {
                        $q2->where('scope', 'category')->where('category_id', $categoryId);
                    });
                }
            })
            ->where(function ($q) use ($field) {
                $q->whereNull('apply_to_fields')
                    ->orWhereJsonContains('apply_to_fields', $field);
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('priority', 'desc');

        foreach ($query->get() as $rule) {
            $matched = false;

            if ($rule->type === 'regex') {
                $matched = (bool) preg_match($rule->pattern, $text);
            } elseif ($rule->type === 'phrase') {
                $matched = str_contains(mb_strtolower($text), mb_strtolower($rule->pattern));
            } else {
                $matched = (bool) preg_match('/\b' . preg_quote($rule->pattern, '/') . '\b/ui', $text);
            }

            if (!$matched) continue;

            match ($rule->action) {
                'delete' => $result['delete'] = true,
                'hide'   => $result['hide'] = true,
                'flag'   => $result['flagged'] = true,
                'replace' => $result['text'] = preg_replace(
                    '/' . preg_quote($rule->pattern, '/') . '/ui',
                    $rule->replacement ?? '',
                    $result['text']
                ),
                default  => null,
            };
        }

        return $result;
    }
}
