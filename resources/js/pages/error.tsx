import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import type { TranslationKey } from '@/lib/i18n';
import { useTranslation } from '@/lib/i18n';

type PageProps = {
    status: 403 | 404 | 500 | 503;
    auth?: { user: unknown; isSuperAdmin?: boolean };
};

const TEXTS: Record<
    PageProps['status'],
    { title: TranslationKey; body: TranslationKey }
> = {
    403: { title: 'error.403.title', body: 'error.403.body' },
    404: { title: 'error.404.title', body: 'error.404.body' },
    500: { title: 'error.500.title', body: 'error.500.body' },
    503: { title: 'error.503.title', body: 'error.503.body' },
};

/**
 * The page shown for "not allowed", "not found" and server errors, in the app's look and the
 * user's language. A page that doesn't exist never ran the session middleware, so the signed-in
 * user may be unknown here: the way back then is the home page, which sends a signed-in user on.
 */
export default function ErrorPage() {
    const { status, auth } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const text = TEXTS[status] ?? TEXTS[500];

    const back = auth?.user
        ? auth.isSuperAdmin
            ? { href: '/platform', label: t('error.back_platform') }
            : { href: '/cash-box', label: t('error.back_app') }
        : { href: '/', label: t('error.back_home') };

    return (
        <>
            <Head title={t(text.title)} />

            <main className="bg-background flex min-h-svh items-center justify-center px-4 py-12">
                <div className="flex max-w-md flex-col items-center gap-6 text-center">
                    <div className="flex size-14 items-center justify-center rounded-2xl bg-slate-800 text-white">
                        <AppLogoIcon className="size-8" />
                    </div>

                    <div className="space-y-3">
                        <p className="text-muted-foreground font-mono text-sm tracking-widest">
                            {status}
                        </p>
                        <h1 className="text-balance text-2xl font-semibold">
                            {t(text.title)}
                        </h1>
                        <p className="text-muted-foreground text-pretty">
                            {t(text.body)}
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={back.href}>
                            <ArrowLeft className="rtl:-scale-x-100" />
                            {back.label}
                        </Link>
                    </Button>
                </div>
            </main>
        </>
    );
}
