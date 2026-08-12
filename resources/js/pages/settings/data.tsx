import { Form, Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useRef } from 'react';
import StationDataController from '@/actions/App/Http/Controllers/Settings/StationDataController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { backup, edit } from '@/routes/data';

export default function StationData() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('settings.data.title')} />

            <h1 className="sr-only">{t('settings.data.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.data.title')}
                    description={t('settings.data.description')}
                />

                <div className="space-y-4 rounded-lg border p-4">
                    <div className="space-y-0.5">
                        <p className="font-medium">
                            {t('settings.data.backup_title')}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t('settings.data.backup_description')}
                        </p>
                    </div>

                    <Button variant="outline" asChild>
                        <a href={backup.url()}>
                            <Download className="size-4" />
                            {t('settings.data.backup_button')}
                        </a>
                    </Button>
                </div>
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.data.danger_title')}
                    description={t('settings.data.danger_description')}
                />
                <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                    <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                        <p className="font-medium">
                            {t('settings.data.warning_title')}
                        </p>
                        <p className="text-sm">
                            {t('settings.data.warning_description')}
                        </p>
                    </div>

                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive">
                                {t('settings.data.reset_button')}
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                {t('settings.data.confirm_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('settings.data.confirm_description')}
                            </DialogDescription>

                            <Form
                                {...StationDataController.reset.form()}
                                options={{
                                    preserveScroll: true,
                                }}
                                onError={() => passwordInput.current?.focus()}
                                resetOnSuccess
                                className="space-y-6"
                            >
                                {({
                                    resetAndClearErrors,
                                    processing,
                                    errors,
                                }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor="password"
                                                className="sr-only"
                                            >
                                                {t('common.password')}
                                            </Label>

                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                ref={passwordInput}
                                                placeholder={t(
                                                    'common.password',
                                                )}
                                                autoComplete="current-password"
                                            />

                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    variant="secondary"
                                                    onClick={() =>
                                                        resetAndClearErrors()
                                                    }
                                                >
                                                    {t('common.cancel')}
                                                </Button>
                                            </DialogClose>

                                            <Button
                                                variant="destructive"
                                                disabled={processing}
                                                asChild
                                            >
                                                <button type="submit">
                                                    {t(
                                                        'settings.data.reset_button',
                                                    )}
                                                </button>
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </>
    );
}

StationData.layout = {
    breadcrumbs: [
        {
            title: 'settings.data.title',
            href: edit(),
        },
    ],
};
