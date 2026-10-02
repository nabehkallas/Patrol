import { Form, Head } from '@inertiajs/react';
import { LoaderCircle, MailCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({
    status,
    email,
}: {
    status?: string;
    email: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.verify_email.title')} />

            <div className="space-y-6 text-center">
                <div className="bg-muted mx-auto flex size-12 items-center justify-center rounded-full">
                    <MailCheck className="size-6" />
                </div>

                <p className="text-muted-foreground text-sm">
                    {t('auth.verify_email.sent_to')}{' '}
                    <bdi className="text-foreground font-medium">{email}</bdi>
                </p>

                {status === 'verification-link-sent' && (
                    <div className="text-sm font-medium text-emerald-600 dark:text-emerald-400">
                        {t('auth.verify_email.resent')}
                    </div>
                )}

                <Form {...send.form()}>
                    {({ processing }) => (
                        <Button
                            className="w-full"
                            disabled={processing}
                            data-test="resend-verification-button"
                        >
                            {processing && (
                                <LoaderCircle className="h-4 w-4 animate-spin" />
                            )}
                            {t('auth.verify_email.resend')}
                        </Button>
                    )}
                </Form>

                <TextLink href={logout()} className="mx-auto block text-sm">
                    {t('auth.verify_email.logout')}
                </TextLink>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'auth.verify_email.title',
    description: 'auth.verify_email.description',
};
