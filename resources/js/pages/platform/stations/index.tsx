import { Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { logout } from '@/routes';
import { create as createStation } from '@/routes/platform/stations';

type Station = {
    id: string;
    name: string;
    onboarded: boolean;
    created_at: string;
};

type NewStationCredentials = {
    station: string;
    email: string;
    password: string;
};

type PageProps = {
    stations: Station[];
    newStationCredentials: NewStationCredentials | null;
};

export default function StationsIndex() {
    const { stations, newStationCredentials } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Stations" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold">Stations</h1>
                        <p className="text-sm text-muted-foreground">
                            Platform admin — manage subscriber stations.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Link
                            href={createStation()}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                        >
                            New station
                        </Link>
                        <Link
                            href={logout()}
                            as="button"
                            className="text-sm text-muted-foreground underline"
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

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {stations.map((station) => (
                                <tr key={station.id} className="border-t">
                                    <td className="px-4 py-2">
                                        {station.name}
                                    </td>
                                    <td className="px-4 py-2">
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
                                    <td className="px-4 py-2">
                                        {formatDate(station.created_at)}
                                    </td>
                                </tr>
                            ))}
                            {stations.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="px-4 py-6 text-center text-muted-foreground"
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
