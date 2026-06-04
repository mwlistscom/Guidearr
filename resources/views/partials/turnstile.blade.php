@if (config('services.turnstile.key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=guidearrTurnstile&render=explicit" async defer></script>
    <script>
        window.guidearrTurnstile = function () {
            document.querySelectorAll('.cf-turnstile:not([data-rendered])').forEach(function (el) {
                try {
                    window.turnstile.render(el, { sitekey: el.dataset.sitekey });
                    el.setAttribute('data-rendered', '1');
                } catch (e) {}
            });
        };
        document.addEventListener('livewire:navigated', function () {
            if (window.turnstile) { window.guidearrTurnstile(); }
        });
    </script>
@endif
