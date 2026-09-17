@props([
    'sidebar' => false,
])

@php
    $settings = \App\Models\SiteSetting::current();
    $name = $settings->display_name;
@endphp

@if($sidebar)
    <flux:sidebar.brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md {{ $settings->logo_url ? '' : 'bg-accent-content text-accent-foreground' }} overflow-hidden">
            @if ($settings->logo_url)
                <img src="{{ $settings->logo_url }}" alt="{{ $name }}" class="size-full object-contain">
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$name" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md {{ $settings->logo_url ? '' : 'bg-accent-content text-accent-foreground' }} overflow-hidden">
            @if ($settings->logo_url)
                <img src="{{ $settings->logo_url }}" alt="{{ $name }}" class="size-full object-contain">
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:brand>
@endif
