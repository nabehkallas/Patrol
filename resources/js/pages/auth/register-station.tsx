import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/register';

export default function RegisterStation({
    passwordRules,
}: {
    passwordRules: string;
}) {
    const { t } = useTranslation();

    const field = (
        name: string,
        label: string,
        props: React.ComponentProps<typeof Input>,
        error?: string,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} name={name} required {...props} />
            <InputError message={error} />
        </div>
    );

    return (
        <>
            <Head title={t('auth.register.title')} />

            <Form
                {...store.form()}
                resetOnError={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-5">
                        {field(
                            'station_name',
                            t('auth.register.station_name'),
                            { autoFocus: true, autoComplete: 'organization' },
                            errors.station_name,
                        )}
                        {field(
                            'owner_name',
                            t('auth.register.owner_name'),
                            { autoComplete: 'name' },
                            errors.owner_name,
                        )}
                        {field(
                            'email',
                            t('common.email_address'),
                            {
                                type: 'email',
                                autoComplete: 'email',
                                placeholder: 'email@example.com',
                                dir: 'ltr',
                            },
                            errors.email,
                        )}
                        {field(
                            'phone',
                            t('auth.register.phone'),
                            {
                                type: 'tel',
                                autoComplete: 'tel',
                                placeholder: '+963 9xx xxx xxx',
                                dir: 'ltr',
                            },
                            errors.phone,
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('common.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="new-password"
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
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-2 w-full"
                            disabled={processing}
                            data-test="register-station-button"
                        >
                            {processing && <Spinner />}
                            {t('auth.register.submit')}
                        </Button>

                        <p className="text-muted-foreground text-center text-sm">
                            {t('auth.register.have_account')}{' '}
                            <TextLink href={login()}>
                                {t('auth.log_in_link')}
                            </TextLink>
                        </p>
                    </div>
                )}
            </Form>
        </>
    );
}

RegisterStation.layout = {
    title: 'auth.register.title',
    description: 'auth.register.description',
};
