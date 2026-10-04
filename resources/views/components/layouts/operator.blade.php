<!DOCTYPE html>
<html lang="ru" class="scheme-light dark:scheme-dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Операторская панель — {{ config('app.name') }}</title>
        <script>
            let operatorTheme;
            try {
                operatorTheme = localStorage.getItem('operator-theme');
            } catch {}
            document.documentElement.classList.toggle('dark', operatorTheme === 'dark'
                || (operatorTheme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches));
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
