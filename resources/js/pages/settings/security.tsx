import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
};

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('settings.security.title')} />

            <h1 className="sr-only">{t('settings.security.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.security.heading')}
                    description={t('settings.security.description')}
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    {t('settings.security.current_password')}
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder={t('settings.security.current_password')}
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">{t('common.new_password')}</Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder={t('common.new_password')}
                                    passwordrules={props.passwordRules}
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
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder={t('common.password_confirmation')}
                                    passwordrules={props.passwordRules}
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    {t('common.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'settings.security.title',
            href: edit(),
        },
    ],
};
