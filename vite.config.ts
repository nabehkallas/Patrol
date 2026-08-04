import { networkInterfaces } from 'node:os';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

/**
 * Picks the machine's LAN IP (preferring Wi-Fi) so the Vite dev server's HMR
 * client script points somewhere a phone on the same network can actually reach
 * — "localhost" in the generated script tag would otherwise resolve to the phone itself.
 */
function lanHost(): string | undefined {
    const interfaces = networkInterfaces();
    const entries = Object.entries(interfaces);
    const preferred = entries.find(([name]) => /wi-?fi/i.test(name));
    const candidates = preferred ? [preferred] : entries;

    for (const [, addresses] of candidates) {
        const match = addresses?.find(
            (addr) => addr.family === 'IPv4' && !addr.internal,
        );

        if (match) {
            return match.address;
        }
    }

    return undefined;
}

const host = lanHost();

export default defineConfig({
    server: host
        ? {
              host: true,
              hmr: { host },
              // laravel-vite-plugin narrows Vite's normally-permissive default CORS
              // down to localhost/127.0.0.1/*.test/APP_URL, which blocks the page
              // (served from the LAN IP) from loading module scripts off the Vite
              // dev server (also the LAN IP, different port) when browsing over
              // Wi-Fi from another device. Fully open CORS is fine for a dev server.
              cors: true,
          }
        : undefined,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        // SSR isn't wired up for this project (no resources/js/ssr.tsx, and
        // app.tsx uses `window` at module scope), so don't let the plugin try
        // to warm up an SSR module graph against app.tsx — it just errors.
        inertia({ ssr: false }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
