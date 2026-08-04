import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import type { TranslationKey } from '@/lib/i18n';
import { useTranslation } from '@/lib/i18n';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { t } = useTranslation();
    const translatedTitle = title ? t(title as TranslationKey) : '';
    const translatedDescription = description ? t(description as TranslationKey) : '';

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                            </div>
                            <span className="sr-only">{translatedTitle}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{translatedTitle}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {translatedDescription}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
