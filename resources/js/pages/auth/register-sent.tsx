import { Head } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { useTranslation } from '@/lib/i18n';
import { login } from '@/routes';

/** After signing up: the station is created once the emailed link is opened. */
export default function RegisterSent({
    email,
    hours,
}: {
    email: string | null;
    hours: number;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.register_sent.title')} />

            <div className="space-y-6 text-center">
                <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                    <MailCheck className="size-6" />
                </div>

                <div className="space-y-2">
                    {email && (
                        <p className="font-medium" dir="ltr">
                            <bdi>{email}</bdi>
                        </p>
                    )}
                    <p className="text-muted-foreground text-sm">
                        {t('auth.register_sent.message')}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t('auth.register_sent.expires', { hours })}
                    </p>
                </div>

                <TextLink href={login()} className="mx-auto block text-sm">
                    {t('auth.log_in_link')}
                </TextLink>
            </div>
        </>
    );
}

RegisterSent.layout = {
    title: 'auth.register_sent.title',
    description: 'auth.register_sent.description',
};
