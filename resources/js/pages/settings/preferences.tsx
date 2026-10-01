import { Head, router, usePage } from '@inertiajs/react';
import PreferencesController from '@/actions/App/Http/Controllers/Settings/PreferencesController';
import DefaultEntryDateTabs from '@/components/default-entry-date-tabs';
import Heading from '@/components/heading';
import { TimezoneSelect } from '@/components/timezone-select';
import type { TimezoneOption } from '@/components/timezone-select';
import { useTranslation } from '@/lib/i18n';
import { edit as editPreferences } from '@/routes/preferences';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
    timezone: string;
    timezones: TimezoneOption[];
};

export default function Preferences() {
    const { auth, timezone, timezones } = usePage<PageProps>().props;
    const { t } = useTranslation();

    function changeTimezone(value: string) {
        router.patch(
            PreferencesController.updateTimezone.url(),
            { timezone: value },
            { preserveScroll: true },
        );
    }

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

                <div className="space-y-2">
                    <p className="text-sm font-medium">
                        {t('settings.preferences.timezone')}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t('settings.preferences.timezone_description')}
                    </p>
                    <TimezoneSelect
                        value={timezone}
                        options={timezones}
                        onChange={changeTimezone}
                        disabled={!auth.isAdmin}
                    />
                    {!auth.isAdmin && (
                        <p className="text-muted-foreground text-xs">
                            {t('settings.preferences.timezone_admin_only')}
                        </p>
                    )}
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
