{{--
    Real-time catch-up. A ChatUpdated sent while the websocket was down (or the tab slept) is
    lost, and with a socket the polls are off or slow. window.filamentChatReconnect(el, refresh, hiddenMs)
    runs `refresh` after a real reconnect of the Echo pusher connection (not the first connect) and
    when the tab comes back after being hidden longer than `hiddenMs`. Bound once per element;
    handlers unbind themselves once the element has left the DOM (wire:navigate), so none pile up.
    No Echo, or a non-pusher connector: nothing happens. Reading is still decided by the component.
--}}
<script data-navigate-once>
    window.filamentChatReconnect ||= (el, refresh, hiddenMs) => {
        if (el.__fchatReconnect) {
            return;
        }

        el.__fchatReconnect = true;

        let connection = null;
        let wasDown = false;
        let hiddenAt = document.hidden ? Date.now() : null;

        const onState = (states) => {
            if (! el.isConnected) {
                return unbind();
            }

            if (states.current !== 'connected') {
                wasDown = true;
            } else if (wasDown) {
                wasDown = false;
                refresh();
            }
        };

        const onVisibility = () => {
            if (! el.isConnected) {
                return unbind();
            }

            if (document.hidden) {
                hiddenAt = Date.now();
            } else if (hiddenAt !== null && Date.now() - hiddenAt > hiddenMs) {
                hiddenAt = null;
                refresh();
            } else {
                hiddenAt = null;
            }
        };

        const unbind = () => {
            connection?.unbind('state_change', onState);
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('EchoLoaded', bind);
        };

        const bind = () => {
            connection = window.Echo?.connector?.pusher?.connection ?? null;

            if (connection) {
                // Whatever state it is in now, the first connect is not a reconnect.
                connection.bind('state_change', onState);
            }
        };

        document.addEventListener('visibilitychange', onVisibility);
        window.Echo ? bind() : window.addEventListener('EchoLoaded', bind, { once: true });
    };
</script>
