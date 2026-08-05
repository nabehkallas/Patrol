import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import ForcePasswordChangeController from '@/actions/App/Http/Controllers/ForcePasswordChangeController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';

type Props = {
    passwordRules: string;
};

export default function ForcePasswordChange(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.force_password_change.title')} />

            <Form
                {...ForcePasswordChangeController.update.form()}
                resetOnError={[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]}
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
                                autoComplete="current-password"
                                autoFocus
                            />
                            <InputError message={errors.current_password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('common.new_password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                ref={passwordInput}
                                name="password"
                                autoComplete="new-password"
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
                                autoComplete="new-password"
                                passwordrules={props.passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            className="w-full"
                            disabled={processing}
                            data-test="force-password-change-button"
                        >
                            {t('common.save')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

ForcePasswordChange.layout = {
    title: 'auth.force_password_change.title',
    description: 'auth.force_password_change.description',
};
