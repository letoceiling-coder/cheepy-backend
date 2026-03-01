<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Садовод База — каталог товаров</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Manrope', 'sans-serif'] },
                    colors: {
                        primary: { 50: '#fef7ee', 100: '#fdedd6', 200: '#f9d7ad', 300: '#f4ba78', 400: '#ee9242', 500: '#ea771e', 600: '#db5d14', 700: '#b54512', 800: '#903817', 900: '#742f16' }
                    }
                }
            }
        }
    </script>
    <style>
        .scrollbar-thin::-webkit-scrollbar { width: 6px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: #f1f5f9; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen">

<header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
        <a href="/" class="text-xl font-bold text-primary-600 hover:text-primary-700 whitespace-nowrap">Садовод База</a>
        <form action="/" method="GET" class="flex-1 max-w-md hidden md:flex">
            @if($categorySlug)
                <input type="hidden" name="category" value="{{ $categorySlug }}">
            @endif
            <input type="search" name="q" placeholder="Поиск по каталогу..."
                class="w-full border border-slate-200 rounded-l-lg px-3 py-2 text-sm focus:outline-none focus:border-primary-400">
            <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-r-lg text-sm hover:bg-primary-700">Найти</button>
        </form>
        <div class="flex items-center gap-2">
            @if($productsData['source'] ?? '' === 'live')
                <span class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full">Live</span>
            @elseif(($productsData['source'] ?? '') === 'db')
                <span class="text-xs text-blue-600 bg-blue-50 px-2 py-1 rounded-full">БД</span>
            @endif
            <a href="/admin" class="text-xs text-slate-400 hover:text-slate-600">Admin</a>
            <button type="button" id="menuToggle" class="lg:hidden p-2 rounded-lg border border-slate-200">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>
    </div>
</header>

{{-- Mobile menu --}}
<div id="mobileMenu" class="fixed inset-0 z-40 lg:hidden hidden">
    <div id="mobileMenuBackdrop" class="absolute inset-0 bg-black/50"></div>
    <aside class="absolute left-0 top-0 bottom-0 w-72 bg-white shadow-xl overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center">
            <span class="font-semibold">Категории</span>
            <button type="button" id="menuClose" class="p-2 rounded-lg hover:bg-slate-100">✕</button>
        </div>
        <ul class="py-2">
            <li><a href="/" class="block px-4 py-3 {{ $categorySlug === '' ? 'bg-primary-50 text-primary-700' : 'text-slate-600' }}">Все товары</a></li>
            @foreach($categories as $cat)
                <li>
                    <a href="/?category={{ $cat['slug'] ?? '' }}" class="block px-4 py-3 {{ ($cat['slug'] ?? '') === $categorySlug ? 'bg-primary-50 text-primary-700' : 'text-slate-600' }}">
                        {{ $cat['title'] ?? '' }}
                    </a>
                    @if(!empty($cat['children']))
                        @foreach($cat['children'] as $ch)
                            <a href="/?category={{ $ch['slug'] ?? '' }}" class="block pl-8 py-2 text-sm {{ ($ch['slug'] ?? '') === $categorySlug ? 'text-primary-600' : 'text-slate-500' }}">
                                {{ $ch['title'] ?? '' }}
                            </a>
                        @endforeach
                    @endif
                </li>
            @endforeach
        </ul>
    </aside>
</div>

<div class="max-w-7xl mx-auto px-4 py-6 flex gap-6">
    {{-- Sidebar --}}
    <aside class="w-64 flex-shrink-0 hidden lg:block">
        <nav class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto scrollbar-thin">
            <div class="p-3 border-b border-slate-100">
                <h2 class="font-semibold text-slate-700">Категории</h2>
            </div>
            <ul class="py-2">
                <li>
                    <a href="/" class="block px-4 py-2 text-sm {{ $categorySlug === '' ? 'bg-primary-50 text-primary-700 font-medium' : 'text-slate-600 hover:bg-slate-50' }}">Все товары</a>
                </li>
                @foreach($categories as $cat)
                    <li class="border-b border-slate-50 last:border-0">
                        <a href="/?category={{ $cat['slug'] ?? '' }}" class="block px-4 py-2.5 text-sm {{ ($cat['slug'] ?? '') === $categorySlug ? 'bg-primary-50 text-primary-700 font-medium' : 'text-slate-600 hover:bg-slate-50' }}">
                            {{ $cat['title'] ?? '' }}
                        </a>
                        @if(!empty($cat['children']))
                            <ul class="pl-4 pb-1">
                                @foreach(array_slice($cat['children'], 0, 10) as $ch)
                                    <li>
                                        <a href="/?category={{ $ch['slug'] ?? '' }}" class="block py-1.5 text-xs {{ ($ch['slug'] ?? '') === $categorySlug ? 'text-primary-600 font-medium' : 'text-slate-500 hover:text-slate-700' }}">
                                            {{ $ch['title'] ?? '' }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    </aside>

    {{-- Main content --}}
    <main class="flex-1 min-w-0">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold text-slate-800">{{ $currentCategoryTitle }}</h1>
            @if(!empty($productsData['total']))
                <span class="text-sm text-slate-500">{{ number_format($productsData['total']) }} товаров</span>
            @endif
        </div>

        @if(!empty($productsData['error']))
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-amber-800 text-sm mb-4">
                ⚠ Ошибка загрузки: {{ $productsData['error'] }}
            </div>
        @endif

        @if(empty($productsData['items']))
            <div class="bg-white rounded-xl border border-slate-200 p-12 text-center text-slate-500">
                @if($categorySlug)
                    Нет товаров в этой категории. Запустите парсинг в
                    <a href="/admin" class="text-primary-600 hover:underline">Admin Panel</a>.
                @else
                    Нет товаров. Выберите категорию или запустите парсинг в
                    <a href="/admin" class="text-primary-600 hover:underline">Admin Panel</a>.
                @endif
            </div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($productsData['items'] as $p)
                    @php
                        $pTitle = $p['title'] ?? ('Товар ' . ($p['id'] ?? ''));
                        $pPrice = $p['price'] ?? '';
                        $pPhoto = $p['photo'] ?? ($p['photos'][0] ?? null);
                        $pId = $p['id'] ?? '';
                    @endphp
                    <a href="/product/{{ $pId }}" class="group bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md hover:border-primary-200 transition-all duration-200">
                        <div class="aspect-square bg-slate-100 overflow-hidden">
                            @if($pPhoto)
                                <img src="{{ $pPhoto }}" alt="{{ $pTitle }}"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                    loading="lazy"
                                    onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center text-4xl text-slate-300\'>📦</div>'">
                            @else
                                <div class="w-full h-full flex items-center justify-center text-slate-400 text-4xl">📦</div>
                            @endif
                        </div>
                        <div class="p-3">
                            <h3 class="font-medium text-slate-800 text-sm line-clamp-2 min-h-[2.5rem] group-hover:text-primary-600">{{ $pTitle }}</h3>
                            @if($pPrice)
                                <p class="mt-1 text-primary-600 font-semibold text-sm">{{ $pPrice }}</p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Pagination --}}
            @if(($productsData['total_pages'] ?? 1) > 1)
                <div class="mt-8 flex flex-wrap items-center justify-center gap-2">
                    @if($page > 1)
                        <a href="/?category={{ $categorySlug }}&page={{ $page - 1 }}" class="px-4 py-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">← Назад</a>
                    @endif
                    @php $totalPages = $productsData['total_pages'] ?? 1; @endphp
                    @for($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++)
                        <a href="/?category={{ $categorySlug }}&page={{ $i }}"
                           class="px-4 py-2 rounded-lg {{ $i === $page ? 'bg-primary-600 text-white' : 'border border-slate-200 text-slate-600 hover:bg-slate-50' }}">
                            {{ $i }}
                        </a>
                    @endfor
                    @if($page < $totalPages)
                        <a href="/?category={{ $categorySlug }}&page={{ $page + 1 }}" class="px-4 py-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Вперёд →</a>
                    @endif
                </div>
                <p class="mt-2 text-center text-sm text-slate-500">Страница {{ $page }} из {{ $totalPages }} (всего {{ number_format($productsData['total']) }} товаров)</p>
            @endif
        @endif
    </main>
</div>

<footer class="border-t border-slate-200 mt-12 py-6 text-center text-sm text-slate-500">
    Данные с агрегатора <a href="https://sadovodbaza.ru" class="text-primary-600 hover:underline" target="_blank" rel="noopener">sadovodbaza.ru</a>.
    | <a href="/admin" class="hover:text-primary-600">Admin Panel</a>
    | <a href="/api/v1/public/menu" class="hover:text-primary-600">API</a>
</footer>

<script>
    document.getElementById('menuToggle')?.addEventListener('click', () => document.getElementById('mobileMenu').classList.remove('hidden'));
    document.getElementById('menuClose')?.addEventListener('click', () => document.getElementById('mobileMenu').classList.add('hidden'));
    document.getElementById('mobileMenuBackdrop')?.addEventListener('click', () => document.getElementById('mobileMenu').classList.add('hidden'));
</script>
</body>
</html>
