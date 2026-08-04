import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import earnings from '@/routes/admin/earnings';

type PageProps = {
    token: string;
    valid: boolean;
};

export default function EarningsResetPassword() {
    const { token, valid } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        token,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(earnings.resetPassword.update.url());
    }

    if (!valid) {
        return (
            <>
                <Head title={t('earnings.reset_password')} />

                <div className="max-w-sm space-y-4">
                    <Heading
                        variant="small"
                        title={t('earnings.reset_password')}
                    />
                    <p className="text-sm text-muted-foreground">
                        {t('earnings.reset_link_invalid')}
                    </p>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('earnings.reset_password')} />

            <div className="max-w-sm space-y-6">
                <Heading variant="small" title={t('earnings.reset_password')} />

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="password">
                            {t('earnings.new_password')}
                        </Label>
                        <PasswordInput
                            id="password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                            autoFocus
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            {t('common.password_confirmation')}
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                        />
                        <InputError
                            message={form.errors.password_confirmation}
                        />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        {t('earnings.reset_password')}
                    </Button>
                </form>
            </div>
        </>
    );
}

EarningsResetPassword.layout = {
    breadcrumbs: [{ title: 'Earnings', href: earnings.index() }],
};
