<!DOCTYPE html>
<html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100">
        <main class="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-16">
            <section class="space-y-4">
                <p class="text-sm font-semibold uppercase tracking-widest text-emerald-400">M-Social</p>
                <h1 class="text-4xl font-semibold tracking-tight">Поддержка промо-акции</h1>
                <p class="max-w-xl text-lg text-slate-300">
                    Базовый интерфейс Laravel, Blade, Livewire и Tailwind CSS готов к реализации MVP.
                </p>
            </section>
        </main>

        @livewireScripts
    </body>
</html>
