import { Head } from '@inertiajs/react';
import DefaultEntryDateTabs from '@/components/default-entry-date-tabs';
import Heading from '@/components/heading';
import { useTranslation } from '@/lib/i18n';
import { edit as editPreferences } from '@/routes/preferences';

export default function Preferences() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('settings.preferences.title')} />

            <h1 className="sr-only">{t('settings.preferences.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.preferences.title')}
                    description={t('settings.preferences.description')}
                />

                <div className="space-y-2">
                    <p className="text-sm font-medium">
                        {t('settings.preferences.default_entry_date')}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t(
                            'settings.preferences.default_entry_date_description',
                        )}
                    </p>
                    <DefaultEntryDateTabs />
                </div>
            </div>
        </>
    );
}

Preferences.layout = {
    breadcrumbs: [
        {
            title: 'settings.preferences.title',
            href: editPreferences(),
        },
    ],
};
