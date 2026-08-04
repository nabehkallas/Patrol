import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import LocaleTabs from '@/components/locale-tabs';
import { useTranslation } from '@/lib/i18n';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('settings.appearance.title')} />

            <h1 className="sr-only">{t('settings.appearance.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.appearance.title')}
                    description={t('settings.appearance.description')}
                />
                <AppearanceTabs />

                <Heading
                    variant="small"
                    title={t('settings.appearance.language')}
                    description={t('settings.appearance.language_description')}
                />
                <LocaleTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'settings.appearance.title',
            href: editAppearance(),
        },
    ],
};
