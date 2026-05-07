<?php

namespace Database\Seeders;

use App\Models\MailIntegration;
use App\Models\MarketingEmailTemplate;
use Illuminate\Database\Seeder;

class MarketingMailSeeder extends Seeder
{
    /** Плейсхолдеры подставляет CRM при предпросмотре и отправке. */
    private const PLACEHOLDERS =
        '{{customer_name}}, {{marketplace_name}}, {{support_email}}, {{support_phone}}, {{site_url}}, '
        .'{{logo_url}}, {{logo_block}}, {{order_number}}, {{order_total}}, {{order_link}}, {{recovery_link}}, '
        .'{{promo_summary}}, {{products_block}}, {{promotions_block}}, {{news_block}}';

    private function wrap(string $bodyHtml): string
    {
        return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"/>'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"/></head>'
            .'<body style="margin:0;background:#eef0fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px;color:#24243a">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#fff;border-radius:16px;padding:28px 24px">'
            .'<tr><td>'.$bodyHtml.'</td></tr></table>'
            .'<p style="max-width:560px;margin:16px auto 0;text-align:center;font-size:12px;color:#8b8ba8">{{marketplace_name}} · '
            .'<a href="{{site_url}}" style="color:#5b53e8">{{site_url}}</a>'
            .' · поддержка <a href="mailto:{{support_email}}" style="color:#5b53e8">{{support_email}}</a></p>'
            .'</body></html>';
    }

    public function run(): void
    {
        MailIntegration::query()->firstOrCreate(
            ['name' => 'smtp'],
            ['is_active' => false, 'config' => []]
        );

        $templates = [
            [
                'slug' => 'welcome_registration',
                'title' => 'Добро пожаловать (регистрация)',
                'send_trigger' => 'registration',
                'subject' => 'Добро пожаловать в {{marketplace_name}}',
                'is_automatic' => true,
                'body' => $this->wrap(
                    '{{logo_block}}'
                    .'<p style="font-size:17px;line-height:1.4">Здравствуйте, {{customer_name}}!</p>'
                    .'<p>Создан ваш аккаунт на маркетплейсе <strong>{{marketplace_name}}</strong>. Спасибо, что вы с нами.</p>'
                    .'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px auto"><tr>'
                    .'<td style="border-radius:10px;background:#5b53e8"><a href="{{site_url}}" '
                    .'style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:600">Перейти на сайт</a>'
                    .'</td></tr></table>'
                    .'<p style="color:#616187;font-size:14px">Поддержка: {{support_email}}</p>'
                ),
            ],
            [
                'slug' => 'order_created',
                'title' => 'Заказ оформлен',
                'send_trigger' => 'order_created',
                'subject' => 'Заказ {{order_number}} оформлен — {{marketplace_name}}',
                'is_automatic' => true,
                'body' => $this->wrap(
                    '{{logo_block}}'
                    .'<p>Здравствуйте, {{customer_name}}!</p>'
                    .'<p>Мы получили ваш заказ <strong>{{order_number}}</strong>.</p>'
                    .'<div style="margin:16px 0">{{products_block}}</div>'
                    .'<p style="font-size:18px;margin:18px 0">Сумма к оплате: <strong>{{order_total}}</strong></p>'
                    .'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:12px auto"><tr>'
                    .'<td style="border-radius:10px;background:#5b53e8"><a href="{{order_link}}" '
                    .'style="display:inline-block;padding:12px 22px;color:#fff;text-decoration:none;font-weight:600">Перейти к заказу</a>'
                    .'</td></tr></table>'
                    .'<p style="color:#616187;font-size:14px">Если это были не вы, напишите в поддержку: {{support_email}}.</p>'
                ),
            ],
            [
                'slug' => 'cart_abandon',
                'title' => 'Не забыть корзину',
                'send_trigger' => 'cart_abandon',
                'subject' => 'У вас остались товары в корзине · {{marketplace_name}}',
                'is_automatic' => true,
                'body' => $this->wrap(
                    '{{logo_block}}'
                    .'<p>{{customer_name}}, вы положили товары в корзину на {{marketplace_name}}.</p>'
                    .'<div style="margin:14px 0">{{products_block}}</div>'
                    .'<p>Запасы могут закончиться — заберите заказ пока есть размер и цвет.</p>'
                    .'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px auto"><tr>'
                    .'<td style="border-radius:10px;background:#e84d6b"><a href="{{recovery_link}}" '
                    .'style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:600">Перейти в корзину</a>'
                    .'</td></tr></table>'
                ),
            ],
            [
                'slug' => 'promo_news',
                'title' => 'Акции и новости',
                'send_trigger' => 'promotions',
                'subject' => 'Акции и выгода для вас — {{marketplace_name}}',
                'is_automatic' => false,
                'body' => $this->wrap(
                    '{{logo_block}}'
                    .'<p>Здравствуйте, {{customer_name}}!</p>'
                    .'<div style="background:#faf7ff;padding:14px;border-radius:12px;border:1px solid #ece7ff;margin:12px 0">'
                    .'<strong>Активные промокоды</strong>{{promotions_block}}'
                    .'</div>'
                    .'<div style="margin:12px 0">{{news_block}}</div>'
                    .'<p style="margin-top:14px;line-height:1.5;color:#616187">{{promo_summary}}</p>'
                    .'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px auto"><tr>'
                    .'<td style="border-radius:10px;background:#5b53e8"><a href="{{site_url}}" '
                    .'style="display:inline-block;padding:12px 22px;color:#fff;text-decoration:none;font-weight:600">Смотреть акции</a>'
                    .'</td></tr></table>'
                ),
            ],
            [
                'slug' => 'preference_stock',
                'title' => 'Новые поступления по интересам',
                'send_trigger' => 'preference_new_products',
                'subject' => 'Новинки могут быть вам по душе — {{marketplace_name}}',
                'is_automatic' => false,
                'body' => $this->wrap(
                    '{{logo_block}}'
                    .'<p>{{customer_name}}, для вас подобрали обновление каталога.</p>'
                    .'<div style="margin:12px 0;line-height:1.5">{{products_block}}</div>'
                    .'<div style="margin:16px 0">{{promotions_block}}</div>'
                    .'<div style="margin:12px 0">{{news_block}}</div>'
                    .'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px auto"><tr>'
                    .'<td style="border-radius:10px;background:#5b53e8"><a href="{{site_url}}" '
                    .'style="display:inline-block;padding:12px 22px;color:#fff;text-decoration:none;font-weight:600">В каталог</a>'
                    .'</td></tr></table>'
                ),
            ],
        ];

        foreach ($templates as $row) {
            MarketingEmailTemplate::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'title' => $row['title'],
                    'send_trigger' => $row['send_trigger'],
                    'subject' => $row['subject'],
                    'body_html' => $row['body'],
                    'is_automatic' => $row['is_automatic'],
                    'is_active' => true,
                    'placeholder_hint' => self::PLACEHOLDERS,
                ]
            );
        }
    }
}
