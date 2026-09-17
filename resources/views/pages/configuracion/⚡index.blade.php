<?php

use App\Models\SiteSetting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Configuración')] class extends Component {
    use WithFileUploads;

    public string $app_name = '';

    public $logo;

    public function mount(): void
    {
        $this->app_name = SiteSetting::current()->app_name ?? config('app.name');
    }

    public function guardarNombre(): void
    {
        $this->validate(['app_name' => 'required|string|max:60']);

        SiteSetting::current()->update(['app_name' => $this->app_name]);

        Flux::toast(variant: 'success', text: __('Nombre actualizado.'));
    }

    public function subirLogo(): void
    {
        $this->validate(['logo' => 'required|image|max:1024']);

        $settings = SiteSetting::current();

        if ($settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
        }

        $path = $this->logo->store('logo', 'public');
        $settings->update(['logo_path' => $path]);

        $this->reset('logo');

        Flux::toast(variant: 'success', text: __('Logo actualizado.'));
    }

    public function quitarLogo(): void
    {
        $settings = SiteSetting::current();

        if ($settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
            $settings->update(['logo_path' => null]);
        }

        Flux::toast(variant: 'success', text: __('Logo eliminado, se usará el ícono por defecto.'));
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Configuración') }}</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">{{ __('Personaliza el nombre y el logo del sistema.') }}</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-5 flex items-center gap-2">
                <flux:icon.identification class="w-5 h-5 text-teal-500" />
                {{ __('Nombre del sistema') }}
            </h2>

            <form wire:submit="guardarNombre" class="flex flex-col gap-4">
                <flux:input wire:model="app_name" :label="__('Nombre')" />
                <div>
                    <button type="submit" class="px-5 py-2.5 bg-teal-600 hover:bg-teal-500 text-white text-sm font-semibold rounded-lg shadow transition">
                        {{ __('Guardar') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-5 flex items-center gap-2">
                <flux:icon.photo class="w-5 h-5 text-teal-500" />
                {{ __('Logo') }}
            </h2>

            <div class="flex items-center gap-4 mb-5">
                <div class="flex items-center justify-center w-16 h-16 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 overflow-hidden">
                    @if ($logo)
                        <img src="{{ $logo->temporaryUrl() }}" class="size-full object-contain">
                    @elseif (SiteSetting::current()->logo_url)
                        <img src="{{ SiteSetting::current()->logo_url }}" class="size-full object-contain">
                    @else
                        <flux:icon.photo class="w-6 h-6 text-zinc-300" />
                    @endif
                </div>

                <form wire:submit="subirLogo" class="flex-1 flex flex-col gap-3">
                    <input type="file" wire:model="logo" accept="image/*"
                           class="block w-full text-sm text-zinc-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 dark:file:bg-zinc-800 file:text-zinc-700 dark:file:text-zinc-300 hover:file:bg-zinc-200 dark:hover:file:bg-zinc-700">
                    @error('logo') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

                    <div class="flex gap-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="logo,subirLogo"
                                class="px-4 py-2 bg-teal-600 hover:bg-teal-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-70">
                            {{ __('Subir logo') }}
                        </button>
                        @if (SiteSetting::current()->logo_path)
                            <button type="button" wire:click="quitarLogo" wire:confirm="{{ __('¿Quitar el logo actual?') }}"
                                    class="px-4 py-2 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition">
                                {{ __('Quitar') }}
                            </button>
                        @endif
                    </div>
                </form>
            </div>
            <p class="text-xs text-zinc-400">{{ __('PNG, JPG o SVG. Máximo 1 MB. Se recomienda un logo cuadrado.') }}</p>
        </div>
    </div>
</div>
