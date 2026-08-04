import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/lib/i18n';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.reset_password.title')} />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('common.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                            />
                            <InputError
                                message={errors.email}
                                className="mt-2"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">{t('common.password')}</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder={t('common.password')}
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                {t('common.password_confirmation')}
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                placeholder={t('common.password_confirmation')}
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                                className="mt-2"
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            {t('auth.reset_password.submit')}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'auth.reset_password.title',
    description: 'auth.reset_password.description',
};
