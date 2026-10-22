import { useEffect, useRef } from 'react';

type TurnstileApi = {
    render: (element: HTMLElement, options: Record<string, unknown>) => string;
    remove: (widgetId: string) => void;
};

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

const SCRIPT_URL =
    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let loading: Promise<void> | null = null;

function loadScript(): Promise<void> {
    if (window.turnstile) {
        return Promise.resolve();
    }

    loading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            loading = null;
            reject(new Error('Turnstile failed to load'));
        };
        document.head.appendChild(script);
    });

    return loading;
}

/**
 * Cloudflare Turnstile, the captcha on the public sign-up form. It puts its token in a hidden
 * `cf-turnstile-response` field of the surrounding form, which the server checks
 * (App\Services\Turnstile). A token works once: change `resetKey` after a failed submit to get
 * a fresh one.
 */
export function Turnstile({
    siteKey,
    resetKey = 0,
}: {
    siteKey: string;
    resetKey?: number;
}) {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let widgetId: string | undefined;
        let cancelled = false;

        loadScript()
            .then(() => {
                if (!cancelled && container.current && window.turnstile) {
                    widgetId = window.turnstile.render(container.current, {
                        sitekey: siteKey,
                        theme: 'auto',
                    });
                }
            })
            .catch(() => {
                // The server refuses the form without a token, so the error shows there.
            });

        return () => {
            cancelled = true;

            if (widgetId && window.turnstile) {
                window.turnstile.remove(widgetId);
            }
        };
    }, [siteKey, resetKey]);

    return (
        <div
            ref={container}
            className="flex min-h-[65px] justify-center"
            data-test="turnstile"
        />
    );
}
