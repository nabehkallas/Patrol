import { Head, Link, router, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { logout } from '@/routes';
import {
    approve,
    create as createStation,
    reject,
} from '@/routes/platform/stations';

type Station = {
    id: string;
    name: string;
    onboarded: boolean;
    created_at: string;
};

type Registration = {
    id: string;
    name: string;
    status: 'pending_verification' | 'pending_approval';
    owner_name: string | null;
    owner_email: string | null;
    owner_phone: string | null;
    created_at: string;
};

type NewStationCredentials = {
    station: string;
    email: string;
    password: string;
};

type PageProps = {
    stations: Station[];
    registrations: Registration[];
    newStationCredentials: NewStationCredentials | null;
};

export default function StationsIndex() {
    const { stations, registrations, newStationCredentials } =
        usePage<PageProps>().props;

    const toReview = registrations.filter(
        (registration) => registration.status === 'pending_approval',
    ).length;

    function approveStation(registration: Registration) {
        if (
            confirm(
                `Approve ${registration.name}? The owner will be emailed that their station is live.`,
            )
        ) {
            router.post(
                approve.url(registration.id),
                {},
                { preserveScroll: true },
            );
        }
    }

    function rejectStation(registration: Registration) {
        if (
            confirm(
                `Reject ${registration.name}? The registration and its account are deleted permanently.`,
            )
        ) {
            router.delete(reject.url(registration.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <>
            <Head title="Stations" />

            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold">Stations</h1>
                        <p className="text-muted-foreground text-sm">
                            Platform admin — manage subscriber stations.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Link
                            href={createStation()}
                            className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium"
                        >
                            New station
                        </Link>
                        <Link
                            href={logout()}
                            as="button"
                            className="text-muted-foreground text-sm underline"
                        >
                            Log out
                        </Link>
                    </div>
                </div>

                {newStationCredentials && (
                    <Card className="border-primary">
                        <CardHeader>
                            <CardTitle>
                                {newStationCredentials.station} created
                            </CardTitle>
                            <CardDescription>
                                Save these credentials now — this is the only
                                time the password is shown. Relay them to the
                                station owner; they'll be asked to set a new
                                password on first login.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-1 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Email
                                </span>
                                <span className="font-mono">
                                    {newStationCredentials.email}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Temporary password
                                </span>
                                <span className="font-mono">
                                    {newStationCredentials.password}
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">
                        Pending registrations
                        {toReview > 0 && (
                            <Badge className="ms-2 bg-amber-500 text-white">
                                {toReview} to review
                            </Badge>
                        )}
                    </h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-start">
                                    <th className="px-4 py-3">Station</th>
                                    <th className="px-4 py-3">Owner</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Registered</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {registrations.map((registration) => (
                                    <tr
                                        key={registration.id}
                                        className="border-t"
                                        data-test="registration-row"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {registration.name}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div>{registration.owner_name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {registration.owner_email}
                                            </div>
                                            <div
                                                className="text-muted-foreground text-xs"
                                                dir="ltr"
                                            >
                                                {registration.owner_phone}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {registration.status ===
                                            'pending_approval' ? (
                                                <Badge className="bg-amber-500 text-white">
                                                    Awaiting approval
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    Email not verified
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {formatDate(
                                                registration.created_at,
                                            )}
                                        </td>
                                        <td className="space-x-2 whitespace-nowrap px-4 py-3 text-end">
                                            {registration.status ===
                                                'pending_approval' && (
                                                <Button
                                                    size="sm"
                                                    className="bg-emerald-600 text-white hover:bg-emerald-700"
                                                    onClick={() =>
                                                        approveStation(
                                                            registration,
                                                        )
                                                    }
                                                >
                                                    Approve
                                                </Button>
                                            )}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="border-rose-300 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10"
                                                onClick={() =>
                                                    rejectStation(registration)
                                                }
                                            >
                                                Reject
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {registrations.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="text-muted-foreground px-4 py-6 text-center"
                                        >
                                            No pending registrations.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <h2 className="text-base font-semibold">Active stations</h2>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-3">Name</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {stations.map((station) => (
                                <tr key={station.id} className="border-t">
                                    <td className="px-4 py-3">
                                        {station.name}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            variant={
                                                station.onboarded
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {station.onboarded
                                                ? 'Active'
                                                : 'Needs setup'}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        {formatDate(station.created_at)}
                                    </td>
                                </tr>
                            ))}
                            {stations.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="text-muted-foreground px-4 py-6 text-center"
                                    >
                                        No stations yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
