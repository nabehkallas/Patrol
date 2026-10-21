import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { OpeningCashCard } from '@/components/opening-cash';
import type { OpeningCash as OpeningCashRow } from '@/components/opening-cash';
import { useTranslation } from '@/lib/i18n';
import { index } from '@/routes/opening-cash';

/** Settings > "Cash when you started using the app" (admins). */
export default function OpeningCash() {
    const { openings } = usePage<{ openings: OpeningCashRow[] }>().props;
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('opening_cash.title')} />
            <h1 className="sr-only">{t('opening_cash.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('opening_cash.title')}
                    description={t('cash_box.opening_cash_description')}
                />

                <OpeningCashCard openings={openings} />
            </div>
        </>
    );
}

OpeningCash.layout = {
    breadcrumbs: [
        {
            title: 'opening_cash.title',
            href: index(),
        },
    ],
};
