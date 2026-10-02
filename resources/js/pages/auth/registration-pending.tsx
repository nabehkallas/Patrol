import { Head } from '@inertiajs/react';
import { Hourglass } from 'lucide-react';
import TextLink from '@/components/text-link';
import { useTranslation } from '@/lib/i18n';
import { logout } from '@/routes';

export default function RegistrationPending({
    stationName,
}: {
    stationName: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.pending.title')} />

            <div className="space-y-6 text-center">
                <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">
                    <Hourglass className="size-6" />
                </div>

                <div className="space-y-2">
                    <p className="font-medium">
                        <bdi>{stationName}</bdi>
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t('auth.pending.message')}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t('auth.pending.next')}
                    </p>
                </div>

                <TextLink href={logout()} className="mx-auto block text-sm">
                    {t('auth.verify_email.logout')}
                </TextLink>
            </div>
        </>
    );
}

RegistrationPending.layout = {
    title: 'auth.pending.title',
    description: 'auth.pending.description',
};
