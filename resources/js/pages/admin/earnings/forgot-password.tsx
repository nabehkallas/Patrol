import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import earnings from '@/routes/admin/earnings';

export default function EarningsForgotPassword() {
    const { t } = useTranslation();

    const form = useForm({});

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(earnings.forgotPassword.send.url());
    }

    return (
        <>
            <Head title={t('earnings.forgot_password')} />

            <div className="max-w-sm space-y-6">
                <Heading
                    variant="small"
                    title={t('earnings.forgot_password')}
                    description={t('earnings.forgot_password_description')}
                />

                <form onSubmit={submit}>
                    <Button type="submit" disabled={form.processing}>
                        {t('earnings.send_reset_link')}
                    </Button>
                </form>
            </div>
        </>
    );
}

EarningsForgotPassword.layout = {
    breadcrumbs: [
        { title: 'Earnings', href: earnings.index() },
        { title: 'Forgot password', href: '' },
    ],
};
