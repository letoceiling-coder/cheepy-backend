<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $product['title'] ?? 'Товар' }} — Садовод База</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope', 'sans-serif'] }, colors: { primary: { 50:'#fef7ee',100:'#fdedd6',200:'#f9d7ad',300:'#f4ba78',400:'#ee9242',500:'#ea771e',600:'#db5d14',700:'#b54512',800:'#903817',900:'#742f16' } } } } }
    </script>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen">

<header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
    <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-4">
        @php $backSlug = $product['category_slugs'][0] ?? ''; @endphp
        <a href="{{ $backSlug ? '/?category=' . $backSlug : '/' }}" class="text-slate-500 hover:text-primary-600 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Каталог
        </a>
        <a href="/" class="text-lg font-bold text-primary-600 hover:text-primary-700">Садовод База</a>
    </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-8">
    @php
        $p = $product;
        $photos = $p['photos'] ?? [];
        $characteristics = $p['characteristics'] ?? [];
        $description = $p['description'] ?? '';
        $seller = $p['seller'] ?? [];
        $category = $p['category'] ?? [];

        // Очистить характеристики от мусора
        $cleanChars = [];
        if (is_array($characteristics)) {
            foreach ($characteristics as $k => $v) {
                if (is_string($v) && mb_strlen($v) < 200 && !preg_match('/Добавить в корзину|Позвонить|Смотреть все|В корзину/ui', $v)) {
                    $cleanChars[$k] = $v;
                }
            }
        }
    @endphp

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="grid md:grid-cols-2 gap-8 p-6 md:p-8">

            {{-- Фото галерея --}}
            <div class="space-y-3">
                @if(!empty($photos))
                    <div class="aspect-square rounded-xl overflow-hidden bg-slate-100">
                        <img id="mainPhoto" src="{{ $photos[0] }}" alt="{{ $p['title'] ?? '' }}"
                            class="w-full h-full object-cover"
                            onerror="this.src='data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><text y=\'.9em\' font-size=\'90\'>📦</text></svg>'">
                    </div>
                    @if(count($photos) > 1)
                        <div class="flex gap-2 overflow-x-auto pb-2">
                            @foreach(array_slice($photos, 0, 12) as $i => $src)
                                <button type="button"
                                    onclick="document.getElementById('mainPhoto').src='{{ addslashes($src) }}'; this.parentElement.querySelectorAll('button').forEach(b=>b.classList.remove('border-primary-500')); this.classList.add('border-primary-500')"
                                    class="flex-shrink-0 w-16 h-16 rounded-lg overflow-hidden border-2 {{ $i === 0 ? 'border-primary-500' : 'border-transparent' }} hover:border-primary-300 transition-colors">
                                    <img src="{{ $src }}" alt="" class="w-full h-full object-cover" loading="lazy">
                                </button>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="aspect-square rounded-xl bg-slate-100 flex items-center justify-center text-6xl text-slate-300">📦</div>
                @endif
            </div>

            {{-- Инфо --}}
            <div>
                <h1 class="text-xl md:text-2xl font-bold text-slate-800 mb-3 leading-tight">{{ $p['title'] ?? 'Товар' }}</h1>

                @if(!empty($p['price']))
                    <p class="text-2xl font-bold text-primary-600 mb-4">{{ $p['price'] }}</p>
                @endif

                @if(!empty($category['title']))
                    <p class="text-sm text-slate-500 mb-3">
                        Категория:
                        <a href="/?category={{ $backSlug }}" class="text-primary-600 hover:underline">{{ $category['title'] }}</a>
                    </p>
                @endif

                {{-- Характеристики --}}
                @if(!empty($cleanChars))
                    <div class="border-t border-slate-100 pt-4 mt-4">
                        <h3 class="font-semibold text-slate-700 mb-3">Характеристики</h3>
                        <dl class="space-y-2">
                            @foreach($cleanChars as $key => $val)
                                <div class="flex gap-2 text-sm">
                                    <dt class="text-slate-500 capitalize min-w-0 flex-shrink-0">{{ $key }}:</dt>
                                    <dd class="text-slate-800">{{ $val }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                {{-- Продавец --}}
                @if(!empty($seller['name']) && $seller['name'] !== 'Смотреть все')
                    <div class="border-t border-slate-100 pt-4 mt-4 bg-slate-50 rounded-lg p-4">
                        <h3 class="font-semibold text-slate-700 mb-2">🏪 Продавец</h3>
                        <p class="font-medium text-slate-800">{{ $seller['name'] }}</p>
                        @if(!empty($seller['pavilion']))
                            <p class="text-sm text-slate-500 mt-1">📍 {{ $seller['pavilion'] }}</p>
                        @endif
                        @if(!empty($seller['phone']))
                            <a href="tel:{{ preg_replace('/\D/', '', $seller['phone']) }}"
                               class="inline-flex items-center gap-1 mt-2 text-primary-600 hover:underline font-medium">
                                📞 {{ $seller['phone'] }}
                            </a>
                        @endif
                        @if(!empty($seller['whatsapp']))
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $seller['whatsapp']) }}"
                               target="_blank" rel="noopener"
                               class="flex items-center gap-2 mt-2 bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600 transition-colors text-sm font-medium w-fit">
                                WhatsApp
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Описание --}}
        @if(trim($description) !== '')
            @php
                // Обрезаем мусор в описании
                $cleanDesc = preg_replace('/\.(shop-contact|btn-add|mySwiper|pavilion2|product-price)[^}]+\}/s', '', $description);
                $cleanDesc = preg_replace('/Популярные категории.*/su', '', $cleanDesc);
                $cleanDesc = mb_substr(trim($cleanDesc), 0, 2000);
            @endphp
            @if(trim($cleanDesc) !== '')
                <div class="border-t border-slate-200 p-6 md:p-8">
                    <h3 class="font-semibold text-slate-700 mb-3">О товаре</h3>
                    <div class="text-slate-600 text-sm whitespace-pre-line leading-relaxed max-h-80 overflow-y-auto">{{ $cleanDesc }}</div>
                </div>
            @endif
        @endif
    </div>
</main>

<footer class="border-t border-slate-200 mt-12 py-6 text-center text-sm text-slate-500">
    <a href="https://sadovodbaza.ru" class="text-primary-600 hover:underline" target="_blank" rel="noopener">sadovodbaza.ru</a>
    | <a href="/admin" class="hover:text-primary-600">Admin</a>
</footer>
</body>
</html>
