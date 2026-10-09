import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    Building2,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Clock,
    Hourglass,
    Inbox,
    KeyRound,
    Mail,
    MailWarning,
    MoreHorizontal,
    PauseCircle,
    Phone,
    PlayCircle,
    Plus,
    Search,
    Trash2,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { ConfirmActionDialog } from '@/components/platform/confirm-action-dialog';
import type { PendingAction } from '@/components/platform/confirm-action-dialog';
import { CopyButton } from '@/components/platform/copy-button';
import { StationUsersDialog } from '@/components/platform/station-users-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    approve,
    create as createStation,
    destroy,
    reactivate,
    reject,
    resetAdminPassword,
    subscription as setSubscription,
    suspend,
} from '@/routes/platform/stations';

type Station = {
    id: string;
    last_activity: string | null;
    subscription_ends_at: string | null;
    name: string;
    onboarded: boolean;
    created_at: string;
    users: number;
    admin_name: string | null;
    admin_email: string | null;
    owner_phone: string | null;
    suspended: boolean;
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
    // 'reset': a temporary password issued by "Reset admin password" instead of a new station.
    kind?: 'reset';
};

type PageProps = {
    stats: {
        active_stations: number;
        suspended_stations: number;
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
    hint,
}: {
    label: string;
    value: number;
    icon: LucideIcon;
    tone: string;
    hint?: string;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4">
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
                    {hint && (
                        <div className="text-xs text-rose-600 dark:text-rose-400">
                            {hint}
                        </div>
                    )}
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

const STATUS_FILTERS = ['all', 'live', 'suspended', 'expiring'] as const;
type StatusFilter = (typeof STATUS_FILTERS)[number];

/** A subscription ending within this many days (or already ended) is flagged. */
const EXPIRY_WARNING_DAYS = 14;

function daysUntil(date: string): number {
    return Math.ceil((new Date(date).getTime() - Date.now()) / 86_400_000);
}

function isExpiring(station: { subscription_ends_at: string | null }): boolean {
    return (
        station.subscription_ends_at !== null &&
        daysUntil(station.subscription_ends_at) <= EXPIRY_WARNING_DAYS
    );
}

const STATUS_MATCHES: Record<StatusFilter, (station: Station) => boolean> = {
    all: () => true,
    live: (station) => !station.suspended,
    suspended: (station) => station.suspended,
    expiring: (station) => !station.suspended && isExpiring(station),
};

/** When the station was last used, and when its subscription runs out (flagged near the end). */
function StationTimeline({ station }: { station: Station }) {
    const { t } = useTranslation();
    const ends = station.subscription_ends_at;
    const left = ends ? daysUntil(ends) : null;

    return (
        <div className="space-y-1.5 text-xs">
            <div className="text-muted-foreground flex items-center gap-1.5">
                <Activity className="size-3.5 shrink-0" />
                {station.last_activity
                    ? t('platform.last_activity').replace(
                          ':date',
                          formatDateTime(station.last_activity),
                      )
                    : t('platform.no_activity')}
            </div>
            <div
                className={cn(
                    'flex items-center gap-1.5',
                    left === null
                        ? 'text-muted-foreground'
                        : left < 0
                          ? 'font-medium text-rose-600 dark:text-rose-400'
                          : left <= EXPIRY_WARNING_DAYS
                            ? 'font-medium text-amber-700 dark:text-amber-400'
                            : 'text-muted-foreground',
                )}
            >
                <CalendarClock className="size-3.5 shrink-0" />
                {ends === null
                    ? t('platform.subscription.none')
                    : left !== null && left < 0
                      ? t('platform.subscription.ended').replace(
                            ':date',
                            formatDate(ends),
                        )
                      : t('platform.subscription.ends').replace(
                            ':date',
                            formatDate(ends),
                        )}
            </div>
        </div>
    );
}

export default function StationsIndex() {
    const { stats, stations, registrations, newStationCredentials } =
        usePage<PageProps>().props;
    const { t } = useTranslation();
    const [usersFor, setUsersFor] = useState<Station | null>(null);

    const [pendingAction, setPendingAction] = useState<PendingAction | null>(
        null,
    );
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');

    const needle = search.trim().toLowerCase();
    const shownStations = stations.filter((station) => {
        const matches =
            needle === '' ||
            [
                station.name,
                station.admin_name,
                station.admin_email,
                station.owner_phone,
            ]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(needle));

        return matches && STATUS_MATCHES[statusFilter](station);
    });

    function askSubscription(station: Station) {
        setPendingAction({
            title: t('platform.subscription.title').replace(
                ':station',
                station.name,
            ),
            message: t('platform.subscription.message'),
            confirmLabel: t('platform.subscription.save'),
            tone: 'success',
            icon: CalendarClock,
            url: setSubscription.url(station.id),
            method: 'post',
            dateField: {
                name: 'subscription_ends_at',
                label: t('platform.subscription.ends_on'),
                value: station.subscription_ends_at?.slice(0, 10) ?? '',
            },
        });
    }

    // Every station action goes through the password confirmation dialog.
    const ask = (
        kind:
            | 'approve'
            | 'reject'
            | 'suspend'
            | 'reactivate'
            | 'delete'
            | 'reset_password',
        target: { id: string; name: string },
    ) => {
        const fill = (key: TranslationKey) =>
            t(key).replace(':station', target.name);
        const spec = {
            approve: {
                url: approve.url(target.id),
                method: 'post',
                tone: 'success',
                icon: CheckCircle2,
            },
            reject: {
                url: reject.url(target.id),
                method: 'delete',
                tone: 'danger',
                icon: X,
            },
            suspend: {
                url: suspend.url(target.id),
                method: 'post',
                tone: 'warning',
                icon: PauseCircle,
            },
            reactivate: {
                url: reactivate.url(target.id),
                method: 'post',
                tone: 'success',
                icon: PlayCircle,
            },
            delete: {
                url: destroy.url(target.id),
                method: 'delete',
                tone: 'danger',
                icon: Trash2,
            },
            reset_password: {
                url: resetAdminPassword.url(target.id),
                method: 'post',
                tone: 'warning',
                icon: KeyRound,
            },
        } as const;

        setPendingAction({
            ...spec[kind],
            title: fill(`platform.confirm.${kind}_title` as TranslationKey),
            message: fill(`platform.confirm.${kind}_message` as TranslationKey),
            confirmLabel: t(
                `platform.confirm.${kind}_button` as TranslationKey,
            ),
        });
    };

    return (
        <>
            <Head title={t('platform.title')} />

            <div className="space-y-10">
                <PageHeader
                    title={t('platform.title')}
                    description={t('platform.description')}
                />

                <div className="card-grid">
                    <KpiCard
                        label={t('platform.kpi.active_stations')}
                        value={stats.active_stations}
                        icon={Building2}
                        hint={
                            stats.suspended_stations > 0
                                ? t('platform.kpi.suspended_hint').replace(
                                      ':count',
                                      String(stats.suspended_stations),
                                  )
                                : undefined
                        }
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
                        <CardContent className="space-y-3">
                            <div className="flex items-center gap-2 font-semibold">
                                <KeyRound className="text-primary size-5" />
                                {t(
                                    newStationCredentials.kind === 'reset'
                                        ? 'platform.credentials.reset_title'
                                        : 'platform.credentials.title',
                                ).replace(
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
                        <div className="panel-grid">
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
                                        <CardContent className="space-y-4">
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
                                                            ask(
                                                                'approve',
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
                                                        ask(
                                                            'reject',
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

                    {stations.length > 0 && (
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="relative w-full sm:w-72">
                                <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder={t(
                                        'platform.search_placeholder',
                                    )}
                                    className="ps-9"
                                    data-test="station-search"
                                />
                            </div>
                            <div className="bg-muted inline-flex rounded-lg p-1">
                                {STATUS_FILTERS.map((value) => (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => setStatusFilter(value)}
                                        className={cn(
                                            'rounded-md px-3 py-1 text-sm transition-colors',
                                            statusFilter === value
                                                ? 'bg-background shadow-xs font-medium'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {t(
                                            `platform.filter.${value}` as TranslationKey,
                                        )}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {stations.length === 0 ? (
                        <Card className="py-10">
                            <CardContent className="text-muted-foreground flex flex-col items-center gap-2 text-sm">
                                <Building2 className="size-8" />
                                {t('platform.active.empty')}
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="panel-grid">
                            {shownStations.map((station) => (
                                <Card
                                    key={station.id}
                                    className={cn(
                                        'gap-4 border-s-4 py-5',
                                        station.suspended
                                            ? 'border-s-rose-500 opacity-90'
                                            : 'border-s-emerald-500',
                                    )}
                                    data-test="station-card"
                                >
                                    <CardContent className="space-y-4">
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
                                            {station.suspended ? (
                                                <Badge className="shrink-0 gap-1 bg-rose-600 text-white">
                                                    <PauseCircle className="size-3" />
                                                    {t(
                                                        'platform.status.suspended',
                                                    )}
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant={
                                                        station.onboarded
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                    className="shrink-0"
                                                >
                                                    {station.onboarded
                                                        ? t(
                                                              'platform.status.live',
                                                          )
                                                        : t(
                                                              'platform.status.needs_setup',
                                                          )}
                                                </Badge>
                                            )}
                                        </div>

                                        <div className="bg-muted/50 rounded-lg px-3 py-2">
                                            <ContactDetails
                                                name={station.admin_name}
                                                email={station.admin_email}
                                                phone={station.owner_phone}
                                            />
                                        </div>

                                        <StationTimeline station={station} />

                                        <div className="flex items-center justify-between gap-2">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setUsersFor(station)
                                                }
                                                className="text-muted-foreground hover:border-primary hover:bg-primary/10 hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2"
                                                data-test="station-users-button"
                                            >
                                                <Users className="size-4" />
                                                {t(
                                                    'platform.users_count',
                                                ).replace(
                                                    ':count',
                                                    String(station.users),
                                                )}
                                                <ChevronRight className="size-3.5 opacity-60 rtl:rotate-180" />
                                            </button>

                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-8"
                                                        aria-label={t(
                                                            'platform.more_actions',
                                                        )}
                                                        title={t(
                                                            'platform.more_actions',
                                                        )}
                                                        data-test="station-actions"
                                                    >
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            askSubscription(
                                                                station,
                                                            )
                                                        }
                                                        data-test="station-subscription"
                                                    >
                                                        <CalendarClock />
                                                        {t(
                                                            'platform.subscription.menu',
                                                        )}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            ask(
                                                                'reset_password',
                                                                station,
                                                            )
                                                        }
                                                        data-test="station-reset-admin-password"
                                                    >
                                                        <KeyRound />
                                                        {t(
                                                            'platform.reset_admin_password',
                                                        )}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    {station.suspended ? (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                ask(
                                                                    'reactivate',
                                                                    station,
                                                                )
                                                            }
                                                            data-test="station-reactivate"
                                                        >
                                                            <PlayCircle />
                                                            {t(
                                                                'platform.reactivate',
                                                            )}
                                                        </DropdownMenuItem>
                                                    ) : (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                ask(
                                                                    'suspend',
                                                                    station,
                                                                )
                                                            }
                                                            data-test="station-suspend"
                                                        >
                                                            <PauseCircle />
                                                            {t(
                                                                'platform.suspend',
                                                            )}
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            ask(
                                                                'delete',
                                                                station,
                                                            )
                                                        }
                                                        data-test="station-delete"
                                                    >
                                                        <Trash2 />
                                                        {t('platform.delete')}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                            {shownStations.length === 0 && (
                                <p className="text-muted-foreground col-span-full py-6 text-center text-sm">
                                    {t('common.no_results')}
                                </p>
                            )}
                        </div>
                    )}
                </section>
            </div>

            <StationUsersDialog
                station={usersFor}
                onClose={() => setUsersFor(null)}
            />

            <ConfirmActionDialog
                action={pendingAction}
                onClose={() => setPendingAction(null)}
            />
        </>
    );
}
