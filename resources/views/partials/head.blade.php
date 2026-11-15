<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php($appName = \App\Models\SiteSetting::current()->display_name)
<title>
    {{ filled($title ?? null) ? $title.' - '.$appName : $appName }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
<script>
    // Por defecto el sistema usa modo claro; solo cambia si el usuario lo elige en Configuración → Apariencia.
    (() => {
        if (! window.Flux || ! window.Flux.applyAppearance) return;

        const original = window.Flux.applyAppearance;
        const key = 'app.appearance.chosen';

        const initial = window.localStorage.getItem('flux.appearance');
        if (! initial && ! window.localStorage.getItem(key)) {
            original('light');
        }

        window.Flux.applyAppearance = (appearance) => {
            window.localStorage.setItem(key, '1');
            original(appearance);
        };

        document.addEventListener('livewire:navigated', () => {
            const saved = window.localStorage.getItem('flux.appearance');
            if (saved) return original(saved);
            if (window.localStorage.getItem(key)) return original('system');
            original('light');
        });
    })();
</script>
