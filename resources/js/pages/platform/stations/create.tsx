import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes/platform';
import { store } from '@/routes/platform/stations';

export default function StationCreate() {
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
            <Head title="New station" />

            <div className="mx-auto max-w-xl space-y-6 p-6">
                <div>
                    <h1 className="text-lg font-semibold">New station</h1>
                    <p className="text-sm text-muted-foreground">
                        Creates the station's database and its first admin
                        account with a generated temporary password.
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="station_name">Station name</Label>
                        <Input
                            id="station_name"
                            value={form.data.station_name}
                            onChange={(e) =>
                                form.setData('station_name', e.target.value)
                            }
                            autoFocus
                        />
                        <InputError message={form.errors.station_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="admin_name">Admin name</Label>
                        <Input
                            id="admin_name"
                            value={form.data.admin_name}
                            onChange={(e) =>
                                form.setData('admin_name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.admin_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="admin_email">Admin email</Label>
                        <Input
                            id="admin_email"
                            type="email"
                            value={form.data.admin_email}
                            onChange={(e) =>
                                form.setData('admin_email', e.target.value)
                            }
                        />
                        <InputError message={form.errors.admin_email} />
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Create station
                        </Button>
                        <Link
                            href={home()}
                            className="text-sm text-muted-foreground underline"
                        >
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </>
    );
}
