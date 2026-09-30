{{--
    Unread count in the tab: "(3) Orders" and a red dot on the favicon. The
    number comes from the dock component (x-effect on $wire.unread), which is
    refreshed by the socket, by polling and after reading.
    Drawn on a canvas from the current favicon (same origin — the canvas stays clean).
--}}
@auth
    <script>
        window.filamentChatBadge = (() => {
            let original = null;
            let image = null;

            const links = () => [...document.querySelectorAll('link[rel~="icon"]')];

            const restore = () => {
                if (original) links().forEach((link) => (link.href = original.get(link) ?? link.href));
            };

            const draw = (count) => {
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = 64;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(image, 0, 0, 64, 64);

                const label = count > 99 ? '99+' : String(count);
                const radius = label.length > 2 ? 22 : 18;
                ctx.beginPath();
                ctx.arc(64 - radius, radius, radius, 0, 2 * Math.PI);
                ctx.fillStyle = '#dc2626';
                ctx.fill();
                ctx.fillStyle = '#ffffff';
                ctx.font = `bold ${label.length > 2 ? 20 : 26}px system-ui, sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(label, 64 - radius, radius + 1);

                try {
                    const url = canvas.toDataURL('image/png');
                    links().forEach((link) => (link.href = url));
                } catch (e) {
                    // A cross-origin favicon taints the canvas — the title still shows the count.
                }
            };

            return (count) => {
                count = Number(count) || 0;

                // Filament sets its own title on every page — take it without our prefix.
                const baseTitle = document.title.replace(/^\(\d+\+?\)\s/, '');
                document.title = count > 0 ? `(${count > 99 ? '99+' : count}) ${baseTitle}` : baseTitle;

                if (! original) {
                    original = new Map(links().map((link) => [link, link.href]));
                }

                if (count === 0) {
                    restore();

                    return;
                }

                if (image?.complete && image.naturalWidth > 0) {
                    draw(count);

                    return;
                }

                const source = document.querySelector('link[rel="icon"][type="image/png"]') ?? links()[0];
                if (! source) return;

                image = new Image();
                image.onload = () => draw(count);
                image.src = original.get(source) ?? source.href;
            };
        })();
    </script>
@endauth
