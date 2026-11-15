@php($titulo = __('Acceso restringido'))

@auth
    <x-layouts::app :title="$titulo">
        <div class="mx-auto max-w-md py-16 text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100 dark:bg-amber-900/30">
                <flux:icon.lock-closed class="h-7 w-7 text-amber-600 dark:text-amber-400" />
            </div>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $titulo }}</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Tu cuenta no tiene permiso para ver esta sección. Pide a un administrador que te asigne un rol con el acceso necesario.') }}
            </p>
            <a href="{{ route('dashboard') }}" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-teal-500">
                <flux:icon.home class="h-4 w-4" />
                {{ __('Volver al panel') }}
            </a>
        </div>
    </x-layouts::app>
@else
    <x-layouts::auth :title="$titulo">
        <div class="text-center">
            <h1 class="text-xl font-bold">{{ $titulo }}</h1>
            <a href="{{ route('login') }}" class="mt-4 inline-block text-sm underline">{{ __('Iniciar sesión') }}</a>
        </div>
    </x-layouts::auth>
@endauth
