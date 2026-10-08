{{--
    Laravel Echo for the chat when `realtime.echo` = 'plugin': Filament already ships
    Echo + pusher-js (window.EchoFactory, a core script), so nothing is bundled here.
    Runs before Livewire starts (DOMContentLoaded), so components find window.Echo when
    they subscribe. An Echo created by the app or by Filament's own config is left alone.
--}}
@use('Asignua\FilamentChat\Support\ChatConfig')
@use('Illuminate\Support\Facades\Route')
@if (auth()->check() && ChatConfig::realtime() && ChatConfig::echoSource() === ChatConfig::ECHO_PLUGIN)
    <script data-navigate-once>
        (() => {
            if (window.Echo || ! window.EchoFactory) {
                return;
            }

            {{-- Semicolons everywhere: a Blade directive (@js) swallows the line break after it. --}}
            const options = @js(ChatConfig::echoOptions());
            const scheme = options.scheme || window.location.protocol.replace(':', '');
            const tls = scheme === 'https';
            const port = options.port || (tls ? 443 : 80);
            const config = {
                broadcaster: options.broadcaster,
                key: options.key,
                forceTLS: tls,
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
                // The app's own route (maybe under a prefix) when there is one; otherwise the one the plugin registers.
                authEndpoint: @js(Route::has('broadcasting.auth') ? route('broadcasting.auth') : url('/broadcasting/auth')),
            };

            // Pusher Channels (cloud) is addressed by its cluster; Reverb and self-hosted
            // Pusher-compatible servers by host and port.
            if (options.broadcaster === 'pusher' && options.cluster && ! options.host) {
                config.cluster = options.cluster;
            } else {
                Object.assign(config, { wsHost: options.host || window.location.hostname, wsPort: port, wssPort: port });
            }

            window.Echo = new window.EchoFactory(config);
            window.dispatchEvent(new CustomEvent('EchoLoaded'));
        })();
    </script>
@endif
