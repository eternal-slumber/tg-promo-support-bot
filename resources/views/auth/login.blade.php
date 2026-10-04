<x-layouts.operator>
    <main class="mx-auto flex min-h-screen max-w-md items-center px-6 py-12">
        <section class="w-full rounded-xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-700 dark:bg-slate-900">
            <h1 class="mt-2 text-2xl font-semibold">Вход оператора</h1>

            <form method="POST" action="{{ route('operator.login.store') }}" x-data="operatorLogin" x-on:submit="normalizePassword()" class="mt-6 space-y-4">
                @csrf
                <label class="block text-sm font-medium">
                    Email
                    <input name="email" type="email" value="{{ old('email') }}" required autofocus class="mt-1 w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 dark:border-slate-600 dark:bg-slate-800">
                </label>
                @error('email')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
                <label class="block text-sm font-medium">
                    Пароль
                    <input name="password" type="password" x-ref="password" x-on:input="normalizePassword()" required class="mt-1 w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 dark:border-slate-600 dark:bg-slate-800">
                </label>
                @error('password')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
                <button type="submit" class="w-full rounded-md bg-emerald-500 px-4 py-2 font-medium text-slate-950 hover:bg-emerald-400">Войти</button>
            </form>
        </section>
    </main>
</x-layouts.operator>
