import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Building2 } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/hooks/use-locale';
import { useTranslation } from '@/lib/i18n';
import { home } from '@/routes/platform';
import { store } from '@/routes/platform/stations';

export default function StationCreate() {
    const { t } = useTranslation();
    const { direction } = useLocale();
    const BackIcon = direction === 'rtl' ? ArrowRight : ArrowLeft;

    const form = useForm({
        station_name: '',
        admin_name: '',
        admin_email: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url());
    }

    return (
        <>
            <Head title={t('platform.create.title')} />

            <div className="mx-auto max-w-xl space-y-6">
                <Link
                    href={home()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <BackIcon className="size-4" />
                    {t('platform.create.back')}
                </Link>

                <div className="flex items-start gap-3">
                    <div className="bg-primary/15 text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                        <Building2 className="size-6" />
                    </div>
                    <div>
                        <h1 className="text-xl font-bold">
                            {t('platform.create.title')}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {t('platform.create.description')}
                        </p>
                    </div>
                </div>

                <Card>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-5">
                            <div className="grid gap-2">
                                <Label htmlFor="station_name">
                                    {t('auth.register.station_name')}
                                </Label>
                                <Input
                                    id="station_name"
                                    value={form.data.station_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'station_name',
                                            e.target.value,
                                        )
                                    }
                                    autoFocus
                                />
                                <InputError
                                    message={form.errors.station_name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="admin_name">
                                    {t('platform.create.admin_name')}
                                </Label>
                                <Input
                                    id="admin_name"
                                    value={form.data.admin_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'admin_name',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.admin_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="admin_email">
                                    {t('platform.create.admin_email')}
                                </Label>
                                <Input
                                    id="admin_email"
                                    type="email"
                                    dir="ltr"
                                    value={form.data.admin_email}
                                    onChange={(e) =>
                                        form.setData(
                                            'admin_email',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.admin_email} />
                            </div>

                            <div className="flex items-center gap-3 pt-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {t('platform.create.submit')}
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={home()}>
                                        {t('common.cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
