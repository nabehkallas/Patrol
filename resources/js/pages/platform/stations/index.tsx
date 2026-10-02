import { Head, Link, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Clock,
    Hourglass,
    Inbox,
    KeyRound,
    Mail,
    MailWarning,
    Phone,
    Plus,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { CopyButton } from '@/components/platform/copy-button';
import { StationUsersDialog } from '@/components/platform/station-users-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDate, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
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
    users: number;
    admin_name: string | null;
    admin_email: string | null;
    owner_phone: string | null;
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
    stats: {
        active_stations: number;
        pending_approval: number;
        total_users: number;
    };
    stations: Station[];
    registrations: Registration[];
    newStationCredentials: NewStationCredentials | null;
};

function KpiCard({
    label,
    value,
    icon: Icon,
    tone,
}: {
    label: string;
    value: number;
    icon: LucideIcon;
    tone: string;
}) {
    return (
        <Card className="py-5">
            <CardContent className="flex items-center gap-4 px-5">
                <div
                    className={cn(
                        'flex size-12 shrink-0 items-center justify-center rounded-xl',
                        tone,
                    )}
                >
                    <Icon className="size-6" />
                </div>
                <div>
                    <div className="text-muted-foreground text-sm">{label}</div>
                    <div className="text-2xl font-bold tabular-nums">
                        {formatNumber(value, 0)}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/** Owner/contact lines: name, email with copy button, labelled phone. */
function ContactDetails({
    name,
    email,
    phone,
}: {
    name: string | null;
    email: string | null;
    phone: string | null;
}) {
    const { t } = useTranslation();

    const row = (icon: LucideIcon, content: ReactNode) => {
        const Icon = icon;

        return (
            <div className="flex min-h-7 items-center gap-2 text-sm">
                <Icon className="text-muted-foreground size-4 shrink-0" />
                {content}
            </div>
        );
    };

    return (
        <div className="space-y-0.5">
            {row(UserRound, <span className="font-medium">{name ?? '—'}</span>)}
            {row(
                Mail,
                email ? (
                    <>
                        <bdi className="truncate" dir="ltr">
                            {email}
                        </bdi>
                        <CopyButton value={email} />
                    </>
                ) : (
                    '—'
                ),
            )}
            {row(
                Phone,
                <span>
                    <span className="text-muted-foreground">
                        {t('platform.phone')}:
                    </span>{' '}
                    <bdi dir="ltr">{phone ?? '—'}</bdi>
                </span>,
            )}
        </div>
    );
}

function SectionHeading({
    title,
    count,
    action,
}: {
    title: string;
    count?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="mb-4 flex flex-wrap items-center gap-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            {count}
            <div className="ms-auto">{action}</div>
        </div>
    );
}

export default function StationsIndex() {
    const { stats, stations, registrations, newStationCredentials } =
        usePage<PageProps>().props;
    const { t } = useTranslation();
    const [usersFor, setUsersFor] = useState<Station | null>(null);

    function approveStation(registration: Registration) {
        if (
            confirm(
                t('platform.confirm_approve').replace(
                    ':station',
                    registration.name,
                ),
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
                t('platform.confirm_reject').replace(
                    ':station',
                    registration.name,
                ),
            )
        ) {
            router.delete(reject.url(registration.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <>
            <Head title={t('platform.title')} />

            <div className="space-y-10">
                <div>
                    <h1 className="text-2xl font-bold">
                        {t('platform.title')}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {t('platform.description')}
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <KpiCard
                        label={t('platform.kpi.active_stations')}
                        value={stats.active_stations}
                        icon={Building2}
                        tone="bg-emerald-500/15 text-emerald-600 dark:text-emerald-400"
                    />
                    <KpiCard
                        label={t('platform.kpi.pending_approval')}
                        value={stats.pending_approval}
                        icon={Hourglass}
                        tone="bg-amber-500/15 text-amber-600 dark:text-amber-400"
                    />
                    <KpiCard
                        label={t('platform.kpi.total_users')}
                        value={stats.total_users}
                        icon={Users}
                        tone="bg-sky-500/15 text-sky-600 dark:text-sky-400"
                    />
                </div>

                {newStationCredentials && (
                    <Card className="border-primary">
                        <CardContent className="space-y-3 px-5">
                            <div className="flex items-center gap-2 font-semibold">
                                <KeyRound className="text-primary size-5" />
                                {t('platform.credentials.title').replace(
                                    ':station',
                                    newStationCredentials.station,
                                )}
                            </div>
                            <p className="text-muted-foreground text-sm">
                                {t('platform.credentials.description')}
                            </p>
                            <div className="bg-muted grid gap-2 rounded-lg p-3 text-sm sm:grid-cols-2">
                                <div>
                                    <div className="text-muted-foreground text-xs">
                                        {t('common.email_address')}
                                    </div>
                                    <div className="font-mono" dir="ltr">
                                        {newStationCredentials.email}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground text-xs">
                                        {t('platform.credentials.password')}
                                    </div>
                                    <div className="font-mono" dir="ltr">
                                        {newStationCredentials.password}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <section>
                    <SectionHeading
                        title={t('platform.pending.title')}
                        count={
                            stats.pending_approval > 0 && (
                                <Badge className="bg-amber-500 text-white">
                                    {t('platform.pending.to_review').replace(
                                        ':count',
                                        String(stats.pending_approval),
                                    )}
                                </Badge>
                            )
                        }
                    />

                    {registrations.length === 0 ? (
                        <Card className="py-10">
                            <CardContent className="text-muted-foreground flex flex-col items-center gap-2 text-sm">
                                <Inbox className="size-8" />
                                {t('platform.pending.empty')}
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            {registrations.map((registration) => {
                                const awaiting =
                                    registration.status === 'pending_approval';

                                return (
                                    <Card
                                        key={registration.id}
                                        className={cn(
                                            'gap-4 border-s-4 py-5',
                                            awaiting
                                                ? 'border-s-amber-500'
                                                : 'border-s-slate-400',
                                        )}
                                        data-test="registration-row"
                                    >
                                        <CardContent className="space-y-4 px-5">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="text-base font-semibold">
                                                        {registration.name}
                                                    </div>
                                                    <div className="text-muted-foreground flex items-center gap-1 text-xs">
                                                        <CalendarDays className="size-3.5" />
                                                        {t(
                                                            'platform.registered_on',
                                                        )}{' '}
                                                        {formatDate(
                                                            registration.created_at,
                                                        )}
                                                    </div>
                                                </div>
                                                {awaiting ? (
                                                    <Badge className="shrink-0 gap-1 bg-amber-500 text-white">
                                                        <Clock className="size-3" />
                                                        {t(
                                                            'platform.status.pending_approval',
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        className="shrink-0 gap-1"
                                                    >
                                                        <MailWarning className="size-3" />
                                                        {t(
                                                            'platform.status.pending_verification',
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>

                                            <div className="bg-muted/50 rounded-lg px-3 py-2">
                                                <ContactDetails
                                                    name={
                                                        registration.owner_name
                                                    }
                                                    email={
                                                        registration.owner_email
                                                    }
                                                    phone={
                                                        registration.owner_phone
                                                    }
                                                />
                                            </div>

                                            <div className="flex gap-2">
                                                {awaiting && (
                                                    <Button
                                                        className="flex-1 bg-emerald-600 text-white hover:bg-emerald-700"
                                                        onClick={() =>
                                                            approveStation(
                                                                registration,
                                                            )
                                                        }
                                                    >
                                                        <CheckCircle2 className="size-4" />
                                                        {t('platform.approve')}
                                                    </Button>
                                                )}
                                                <Button
                                                    variant="outline"
                                                    className={cn(
                                                        'border-rose-300 text-rose-600 hover:bg-rose-50 hover:text-rose-700 dark:border-rose-500/40 dark:text-rose-400 dark:hover:bg-rose-500/10',
                                                        awaiting
                                                            ? 'flex-1'
                                                            : 'w-full',
                                                    )}
                                                    onClick={() =>
                                                        rejectStation(
                                                            registration,
                                                        )
                                                    }
                                                >
                                                    <X className="size-4" />
                                                    {t('platform.reject')}
                                                </Button>
                                            </div>
                                            {!awaiting && (
                                                <p className="text-muted-foreground text-xs">
                                                    {t(
                                                        'platform.pending.unverified_hint',
                                                    )}
                                                </p>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </section>

                <section>
                    <SectionHeading
                        title={t('platform.active.title')}
                        action={
                            <Button asChild>
                                <Link href={createStation()}>
                                    <Plus className="size-4" />
                                    {t('platform.new_station')}
                                </Link>
                            </Button>
                        }
                    />

                    {stations.length === 0 ? (
                        <Card className="py-10">
                            <CardContent className="text-muted-foreground flex flex-col items-center gap-2 text-sm">
                                <Building2 className="size-8" />
                                {t('platform.active.empty')}
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {stations.map((station) => (
                                <Card
                                    key={station.id}
                                    className="gap-4 border-s-4 border-s-emerald-500 py-5"
                                    data-test="station-card"
                                >
                                    <CardContent className="space-y-4 px-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <div className="text-base font-semibold">
                                                    {station.name}
                                                </div>
                                                <div className="text-muted-foreground flex items-center gap-1 text-xs">
                                                    <CalendarDays className="size-3.5" />
                                                    {t('platform.created_on')}{' '}
                                                    {formatDate(
                                                        station.created_at,
                                                    )}
                                                </div>
                                            </div>
                                            <Badge
                                                variant={
                                                    station.onboarded
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                className="shrink-0"
                                            >
                                                {station.onboarded
                                                    ? t('platform.status.live')
                                                    : t(
                                                          'platform.status.needs_setup',
                                                      )}
                                            </Badge>
                                        </div>

                                        <div className="bg-muted/50 rounded-lg px-3 py-2">
                                            <ContactDetails
                                                name={station.admin_name}
                                                email={station.admin_email}
                                                phone={station.owner_phone}
                                            />
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => setUsersFor(station)}
                                            className="text-muted-foreground hover:border-primary hover:bg-primary/10 hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2"
                                            data-test="station-users-button"
                                        >
                                            <Users className="size-4" />
                                            {t('platform.users_count').replace(
                                                ':count',
                                                String(station.users),
                                            )}
                                            <ChevronRight className="size-3.5 opacity-60 rtl:rotate-180" />
                                        </button>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </section>
            </div>

            <StationUsersDialog
                station={usersFor}
                onClose={() => setUsersFor(null)}
            />
        </>
    );
}
