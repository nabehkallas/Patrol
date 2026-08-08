import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { initializeLocale } from '@/hooks/use-locale';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('platform/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// This will set the document language / direction on load...
initializeLocale();

// The browser can restore a page from its back-forward cache (e.g. after
// navigating away and pressing back) without re-running any Inertia visit,
// leaving stale props on screen (e.g. dashboard totals) until a manual
// refresh. Force a fresh visit whenever that happens.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        router.reload();
    }
});

// A browser tab left open across a deploy still has the *previous* build's page
// components in memory, referencing JS chunk filenames that no longer exist (Vite
// fingerprints every file per build). Navigating to one of those pages then fails to
// load its chunk and would otherwise render a blank screen — Vite fires this event in
// that case, so force a full reload to pick up the current build instead.
window.addEventListener('vite:preloadError', () => {
    window.location.reload();
});
