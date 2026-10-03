import { Head, Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    BarChart3,
    Building2,
    ChevronLeft,
    ChevronRight,
    Coins,
    Database,
    Eye,
    EyeOff,
    Fuel,
    Gauge,
    GripVertical,
    ImagePlus,
    Languages,
    LockKeyhole,
    Play,
    Receipt,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Truck,
    Users,
    Video,
    WifiOff,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { createContext, useContext, useEffect, useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import AppLogoIcon from '@/components/app-logo-icon';
import type {
    LandingContent,
    LandingSection,
    LandingText,
    SectionId,
} from '@/components/landing/content';
import { useLocale } from '@/hooks/use-locale';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { login, register } from '@/routes';

/* ------------------------------------------------------------------------------------------
 * Editor hooks. On the public page there is no editor: texts render as plain text and section
 * frames render nothing extra. The visual editor passes an EditorApi to make the very same
 * page editable in place.
 * ---------------------------------------------------------------------------------------- */

export type EditorApi = {
    /** Sets a value by dotted path from the content root, e.g. "text.en.heroTitle1". */
    set: (path: string, value: unknown) => void;
    move: (id: SectionId, delta: number) => void;
    /** Drag and drop: puts `id` where `targetId` currently is. */
    moveTo: (id: SectionId, targetId: SectionId) => void;
    toggle: (id: SectionId) => void;
    openMedia: (kind: 'video' | 'carousel') => void;
};

type EditorState = { editing: false } | ({ editing: true } & EditorApi);

const EditorContext = createContext<EditorState>({ editing: false });
const TextPathContext = createContext<(path: string) => string>((p) => p);

const DRAG_TYPE = 'application/x-landing-section';

/** A piece of page text. In the editor it can be clicked and typed over; Enter or clicking
 * away keeps the change, Escape cancels it. */
function E({
    path,
    value,
    root = false,
}: {
    /** Relative to the current language's texts, or to the content root when `root`. */
    path: string;
    value: string;
    root?: boolean;
}) {
    const editor = useContext(EditorContext);
    const textPath = useContext(TextPathContext);

    if (!editor.editing) {
        return <>{value}</>;
    }

    const fullPath = root ? path : textPath(path);

    return (
        <span
            contentEditable="plaintext-only"
            suppressContentEditableWarning
            spellCheck={false}
            data-edit={fullPath}
            className="cursor-text rounded-sm outline-dashed outline-1 outline-offset-2 outline-sky-400/0 transition-colors hover:outline-sky-400/80 focus:bg-sky-400/10 focus:outline-sky-400"
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
            }}
            onKeyDown={(event) => {
                const el = event.currentTarget;

                if (event.key === 'Enter') {
                    event.preventDefault();
                    el.blur();
                } else if (event.key === 'Escape') {
                    el.innerText = value;
                    el.blur();
                }
            }}
            onBlur={(event) => {
                const next = event.currentTarget.innerText
                    .replace(/\s+/g, ' ')
                    .trim();

                if (next !== '' && next !== value) {
                    editor.set(fullPath, next);
                } else {
                    event.currentTarget.innerText = value;
                }
            }}
        >
            {value}
        </span>
    );
}

/** A link on the public page; in the editor a plain element, so clicking edits its text instead
 * of navigating away. */
function Cta({
    href,
    className,
    children,
    test,
    inertia = true,
}: {
    href: string;
    className: string;
    children: ReactNode;
    test?: string;
    inertia?: boolean;
}) {
    const editor = useContext(EditorContext);

    if (editor.editing) {
        return (
            <span className={className} data-test={test}>
                {children}
            </span>
        );
    }

    return inertia ? (
        <Link href={href} className={className} data-test={test}>
            {children}
        </Link>
    ) : (
        <a href={href} className={className} data-test={test}>
            {children}
        </a>
    );
}

function SectionFrame({
    section,
    position,
    count,
    media,
    className,
    children,
}: {
    section: LandingSection;
    position: number;
    count: number;
    media?: 'video' | 'carousel';
    className?: string;
    children: ReactNode;
}) {
    const editor = useContext(EditorContext);
    const { t } = useTranslation();
    const [dropTarget, setDropTarget] = useState(false);

    if (!editor.editing) {
        return className ? (
            <div className={className}>{children}</div>
        ) : (
            <>{children}</>
        );
    }

    const button =
        'flex size-7 items-center justify-center rounded-md hover:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent';

    return (
        <div
            className={cn(
                'relative rounded-2xl outline-dashed outline-2 outline-offset-8 outline-transparent transition-[outline-color] hover:outline-sky-500/40',
                dropTarget && 'outline-sky-400',
                className,
            )}
            data-section={section.id}
            onDragOver={(event) => {
                if (event.dataTransfer.types.includes(DRAG_TYPE)) {
                    event.preventDefault();
                    setDropTarget(true);
                }
            }}
            onDragLeave={() => setDropTarget(false)}
            onDrop={(event) => {
                event.preventDefault();
                setDropTarget(false);
                const id = event.dataTransfer.getData(DRAG_TYPE) as SectionId;

                if (id && id !== section.id) {
                    editor.moveTo(id, section.id);
                }
            }}
        >
            <div
                className="absolute bottom-full start-0 z-30 mb-2 flex items-center gap-0.5 rounded-lg border border-sky-400/40 bg-[#0b1a2c] px-1 py-0.5 text-xs text-slate-200 shadow-lg shadow-black/40"
                data-test={`section-toolbar-${section.id}`}
            >
                <span
                    draggable
                    onDragStart={(event) => {
                        event.dataTransfer.setData(DRAG_TYPE, section.id);
                        event.dataTransfer.effectAllowed = 'move';
                    }}
                    className="flex size-7 cursor-grab items-center justify-center active:cursor-grabbing"
                    title={t('platform.landing.drag')}
                    data-test={`section-drag-${section.id}`}
                >
                    <GripVertical className="size-4" />
                </span>
                <span className="px-1 font-semibold">
                    {t(`platform.landing.section.${section.id}`)}
                </span>
                {!section.visible && (
                    <span className="rounded bg-amber-400/20 px-1.5 py-0.5 text-[10px] font-medium text-amber-300">
                        {t('platform.landing.hidden')}
                    </span>
                )}
                <button
                    type="button"
                    className={button}
                    disabled={position === 0}
                    onClick={() => editor.move(section.id, -1)}
                    title={t('platform.landing.move_up')}
                    data-test={`section-up-${section.id}`}
                >
                    <ArrowUp className="size-4" />
                </button>
                <button
                    type="button"
                    className={button}
                    disabled={position === count - 1}
                    onClick={() => editor.move(section.id, 1)}
                    title={t('platform.landing.move_down')}
                    data-test={`section-down-${section.id}`}
                >
                    <ArrowDown className="size-4" />
                </button>
                <button
                    type="button"
                    className={button}
                    onClick={() => editor.toggle(section.id)}
                    title={
                        section.visible
                            ? t('platform.landing.hide')
                            : t('platform.landing.show')
                    }
                    data-test={`section-toggle-${section.id}`}
                >
                    {section.visible ? (
                        <Eye className="size-4" />
                    ) : (
                        <EyeOff className="size-4 text-amber-300" />
                    )}
                </button>
                {media && (
                    <button
                        type="button"
                        className="flex h-7 items-center gap-1 rounded-md px-2 hover:bg-white/10"
                        onClick={() => editor.openMedia(media)}
                        data-test={`section-media-${section.id}`}
                    >
                        {media === 'video' ? (
                            <Video className="size-4" />
                        ) : (
                            <ImagePlus className="size-4" />
                        )}
                        {t('platform.landing.media')}
                    </button>
                )}
            </div>
            <div className={cn(!section.visible && 'opacity-35 grayscale')}>
                {children}
            </div>
        </div>
    );
}

/** In the editor, a click target over a media container that opens its media dialog. */
function MediaOverlay({
    kind,
    label,
}: {
    kind: 'video' | 'carousel';
    label: string;
}) {
    const editor = useContext(EditorContext);

    if (!editor.editing) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={() => editor.openMedia(kind)}
            className="absolute inset-0 z-10 flex items-center justify-center bg-black/0 text-sm font-semibold text-white opacity-0 transition hover:bg-black/55 hover:opacity-100"
            data-test={`media-overlay-${kind}`}
        >
            <span className="flex items-center gap-2 rounded-lg bg-sky-500 px-3 py-2 shadow-lg">
                {kind === 'video' ? (
                    <Video className="size-4" />
                ) : (
                    <ImagePlus className="size-4" />
                )}
                {label}
            </span>
        </button>
    );
}

/* ------------------------------------------------------------------------------------------
 * Interactive demo: simulated station numbers that tick up like a live dashboard.
 * ---------------------------------------------------------------------------------------- */

const FEATURE_ICONS: Record<string, LucideIcon> = {
    shield: ShieldCheck,
    database: Database,
    wifi: WifiOff,
    gauge: Gauge,
    truck: Truck,
    receipt: Receipt,
    coins: Coins,
    chart: BarChart3,
    refresh: RefreshCw,
};

const PRICE = { petrol: 11500, diesel: 10200 }; // SYP per liter, sample prices
const SHIFT_START_HOUR = 6;

/** Deterministic sample day: a morning and an evening rush. */
function sampleHours(): { hour: number; petrol: number; diesel: number }[] {
    return Array.from({ length: 15 }, (_, i) => {
        const hour = SHIFT_START_HOUR + i;
        const rush =
            Math.exp(-((hour - 8.5) ** 2) / 3) +
            1.15 * Math.exp(-((hour - 17.5) ** 2) / 4);

        return {
            hour,
            petrol: Math.round(90 + 260 * rush + ((i * 37) % 23)),
            diesel: Math.round(60 + 170 * rush + ((i * 53) % 19)),
        };
    });
}

const SAMPLE_HOURS = sampleHours();
const n0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
const compact = new Intl.NumberFormat('en-US', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

function useTicker() {
    // Adds a few liters every couple of seconds so the counters feel live.
    const [extra, setExtra] = useState({ petrol: 0, diesel: 0, ticks: 0 });

    useEffect(() => {
        const id = window.setInterval(() => {
            setExtra((e) => ({
                petrol: e.petrol + 6 + Math.round(Math.random() * 14),
                diesel: e.diesel + 3 + Math.round(Math.random() * 9),
                ticks: e.ticks + 1,
            }));
        }, 2200);

        return () => window.clearInterval(id);
    }, []);

    return extra;
}

function DemoDashboard({ c, locale }: { c: LandingText; locale: string }) {
    const [mode, setMode] = useState<'today' | 'shift'>('today');
    const extra = useTicker();

    // "Current shift" = the afternoon shift (14:00 onwards); "today" = the whole day.
    const visible =
        mode === 'today'
            ? SAMPLE_HOURS
            : SAMPLE_HOURS.filter((h) => h.hour >= 14);
    const series = visible.map((h, i) => {
        const last = i === visible.length - 1;
        const petrol = h.petrol + (last ? extra.petrol : 0);
        const diesel = h.diesel + (last ? extra.diesel : 0);

        return {
            label: `${String(h.hour).padStart(2, '0')}:00`,
            liters: petrol + diesel,
            revenue: petrol * PRICE.petrol + diesel * PRICE.diesel,
            petrol,
            diesel,
        };
    });

    const petrolL = series.reduce((s, r) => s + r.petrol, 0);
    const dieselL = series.reduce((s, r) => s + r.diesel, 0);
    const revenue = petrolL * PRICE.petrol + dieselL * PRICE.diesel;
    const activePumps = 3 + (extra.ticks % 2);

    const kpis: { key: string; value: ReactNode; tone: string }[] = [
        {
            key: 'liters',
            value: `${n0.format(petrolL + dieselL)} L`,
            tone: 'text-sky-300',
        },
        {
            key: 'cash',
            value: `${compact.format(revenue)} SYP`,
            tone: 'text-emerald-300',
        },
        {
            key: 'shiftStatus',
            value: (
                <>
                    <E path="demo.shiftOpen" value={c.demo.shiftOpen} />{' '}
                    {mode === 'today' ? '06:00' : '14:00'}
                </>
            ),
            tone: 'text-amber-300',
        },
        { key: 'pumps', value: `${activePumps} / 4`, tone: 'text-violet-300' },
    ];

    return (
        <div className="relative">
            <div className="pointer-events-none absolute -inset-6 rounded-[2rem] bg-sky-500/20 blur-3xl" />
            <div
                className="relative overflow-hidden rounded-2xl border border-white/10 bg-[#0d1f35]/80 p-4 shadow-2xl shadow-sky-950/50 backdrop-blur-xl sm:p-5"
                data-test="landing-demo"
            >
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="relative flex size-2.5">
                            <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span className="relative inline-flex size-2.5 rounded-full bg-emerald-400" />
                        </span>
                        <span className="font-medium text-white">
                            <E path="demo.live" value={c.demo.live} />
                        </span>
                        <span className="rounded-full border border-white/10 px-2 py-0.5 text-[11px] text-slate-400">
                            <E path="demo.sample" value={c.demo.sample} />
                        </span>
                    </div>
                    <div className="inline-flex rounded-lg bg-white/5 p-1 text-xs">
                        {(['today', 'shift'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                onClick={() => setMode(m)}
                                className={cn(
                                    'rounded-md px-3 py-1.5 font-medium transition-colors',
                                    mode === m
                                        ? 'bg-sky-500 text-white shadow'
                                        : 'text-slate-300 hover:text-white',
                                )}
                                data-test={`landing-demo-${m}`}
                            >
                                <E
                                    path={`demo.${m === 'today' ? 'today' : 'shift'}`}
                                    value={
                                        m === 'today'
                                            ? c.demo.today
                                            : c.demo.shift
                                    }
                                />
                            </button>
                        ))}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-2.5">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.key}
                            className="rounded-xl border border-white/5 bg-white/[0.04] p-3"
                        >
                            <div className="text-[11px] text-slate-400">
                                <E
                                    path={`demo.${kpi.key}`}
                                    value={c.demo[kpi.key]}
                                />
                            </div>
                            <div
                                className={cn(
                                    'mt-0.5 text-lg font-bold tabular-nums transition-all',
                                    kpi.tone,
                                )}
                                dir={
                                    kpi.key === 'shiftStatus'
                                        ? undefined
                                        : 'ltr'
                                }
                            >
                                {kpi.value}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="mt-4 rounded-xl border border-white/5 bg-white/[0.03] p-3">
                    <div className="mb-2 flex items-center justify-between text-xs">
                        <span className="font-medium text-slate-200">
                            {mode === 'today' ? (
                                <E
                                    path="demo.chartTitleToday"
                                    value={c.demo.chartTitleToday}
                                />
                            ) : (
                                <E
                                    path="demo.chartTitleShift"
                                    value={c.demo.chartTitleShift}
                                />
                            )}
                        </span>
                        <span className="flex items-center gap-3 text-slate-400">
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-full bg-sky-400" />
                                L
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-full bg-amber-400" />
                                <E path="demo.revenue" value={c.demo.revenue} />
                            </span>
                        </span>
                    </div>
                    <div className="h-40" dir="ltr">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart
                                data={series}
                                margin={{
                                    top: 4,
                                    right: 4,
                                    left: 0,
                                    bottom: 0,
                                }}
                            >
                                <defs>
                                    <linearGradient
                                        id="lpLiters"
                                        x1="0"
                                        y1="0"
                                        x2="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="0%"
                                            stopColor="#38bdf8"
                                            stopOpacity={0.5}
                                        />
                                        <stop
                                            offset="100%"
                                            stopColor="#38bdf8"
                                            stopOpacity={0}
                                        />
                                    </linearGradient>
                                    <linearGradient
                                        id="lpRevenue"
                                        x1="0"
                                        y1="0"
                                        x2="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="0%"
                                            stopColor="#fbbf24"
                                            stopOpacity={0.35}
                                        />
                                        <stop
                                            offset="100%"
                                            stopColor="#fbbf24"
                                            stopOpacity={0}
                                        />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid
                                    stroke="rgba(255,255,255,0.06)"
                                    vertical={false}
                                />
                                <XAxis
                                    dataKey="label"
                                    reversed={locale === 'ar'}
                                    tick={{ fontSize: 10, fill: '#94a3b8' }}
                                    tickLine={false}
                                    axisLine={false}
                                    interval="preserveStartEnd"
                                />
                                <YAxis
                                    yAxisId="l"
                                    tick={{ fontSize: 10, fill: '#94a3b8' }}
                                    tickLine={false}
                                    axisLine={false}
                                    width={36}
                                    tickFormatter={(v) =>
                                        compact.format(Number(v))
                                    }
                                />
                                <YAxis yAxisId="r" hide />
                                <Tooltip
                                    contentStyle={{
                                        background: '#0b1a2c',
                                        border: '1px solid rgba(255,255,255,0.1)',
                                        borderRadius: 10,
                                        color: '#e2e8f0',
                                        fontSize: 12,
                                    }}
                                    formatter={(value, name) =>
                                        name === 'revenue'
                                            ? [
                                                  `${compact.format(Number(value))} SYP`,
                                                  c.demo.revenue,
                                              ]
                                            : [
                                                  `${n0.format(Number(value))} L`,
                                                  c.demo.liters,
                                              ]
                                    }
                                />
                                <Area
                                    yAxisId="r"
                                    type="monotone"
                                    dataKey="revenue"
                                    stroke="#fbbf24"
                                    strokeWidth={1.5}
                                    fill="url(#lpRevenue)"
                                    isAnimationActive={false}
                                />
                                <Area
                                    yAxisId="l"
                                    type="monotone"
                                    dataKey="liters"
                                    stroke="#38bdf8"
                                    strokeWidth={2}
                                    fill="url(#lpLiters)"
                                    isAnimationActive={false}
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </div>

                <div className="mt-4 rounded-xl border border-white/5 bg-white/[0.03] p-3 text-xs">
                    <div className="mb-2 font-medium text-slate-200">
                        <E path="demo.calc" value={c.demo.calc} />
                    </div>
                    {[
                        {
                            key: 'petrol',
                            liters: petrolL,
                            price: PRICE.petrol,
                            dot: 'bg-amber-400',
                        },
                        {
                            key: 'diesel',
                            liters: dieselL,
                            price: PRICE.diesel,
                            dot: 'bg-sky-400',
                        },
                    ].map((row) => (
                        <div
                            key={row.key}
                            className="flex items-center justify-between gap-2 py-1 text-slate-300"
                        >
                            <span className="flex items-center gap-1.5">
                                <span
                                    className={cn(
                                        'size-2 rounded-full',
                                        row.dot,
                                    )}
                                />
                                <E
                                    path={`demo.${row.key}`}
                                    value={c.demo[row.key]}
                                />
                            </span>
                            <span dir="ltr" className="tabular-nums">
                                {n0.format(row.liters)} L ×{' '}
                                {n0.format(row.price)} ={' '}
                                <span className="font-semibold text-white">
                                    {n0.format(row.liters * row.price)} SYP
                                </span>
                            </span>
                        </div>
                    ))}
                    <div className="mt-1 flex justify-between border-t border-white/10 pt-2 font-semibold text-white">
                        <span>
                            <E path="demo.cash" value={c.demo.cash} />
                        </span>
                        <span
                            dir="ltr"
                            className="tabular-nums text-emerald-300"
                        >
                            {n0.format(revenue)} SYP
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------------------------------
 * Media blocks.
 * ---------------------------------------------------------------------------------------- */

function videoType(src: string): string {
    return src.toLowerCase().endsWith('.webm') ? 'video/webm' : 'video/mp4';
}

function VideoBlock({
    content,
    c,
}: {
    content: LandingContent;
    c: LandingText;
}) {
    const editor = useContext(EditorContext);
    const { t } = useTranslation();
    const { src, poster } = content.video;

    return (
        <figure className="overflow-hidden rounded-2xl border border-white/10 bg-[#0d1f35]/70 backdrop-blur">
            <div className="relative">
                {src ? (
                    <video
                        key={src}
                        className="aspect-video w-full bg-black"
                        controls
                        preload="none"
                        playsInline
                        muted
                        poster={poster ?? undefined}
                        data-test="landing-video"
                    >
                        <source src={src} type={videoType(src)} />
                    </video>
                ) : (
                    <div className="flex aspect-video w-full flex-col items-center justify-center gap-2 bg-black/60 text-sm text-slate-400">
                        <Video className="size-8" />
                        {editor.editing && t('platform.landing.no_video')}
                    </div>
                )}
                <MediaOverlay
                    kind="video"
                    label={t('platform.landing.manage_video')}
                />
            </div>
            <figcaption className="flex items-center gap-2 p-4 text-sm text-slate-300">
                <Play className="size-4 shrink-0 text-sky-400" />
                <E path="videoCaption" value={c.videoCaption} />
            </figcaption>
        </figure>
    );
}

function CarouselBlock({
    content,
    c,
    locale,
}: {
    content: LandingContent;
    c: LandingText;
    locale: string;
}) {
    const editor = useContext(EditorContext);
    const { t } = useTranslation();
    const [index, setIndex] = useState(0);
    const slides = content.slides;
    const total = slides.length + (content.showRoadmapSlide ? 1 : 0);
    const current = total === 0 ? 0 : index % total;
    const lang = locale === 'ar' ? 'ar' : 'en';

    useEffect(() => {
        // Auto-advance on the public page only; in the editor it would move away mid-edit.
        if (editor.editing || total <= 1) {
            return;
        }

        const id = window.setInterval(
            () => setIndex((i) => (i + 1) % total),
            6000,
        );

        return () => window.clearInterval(id);
    }, [total, index, editor.editing]);

    const go = (delta: number) => setIndex((i) => (i + delta + total) % total);
    const PrevIcon = locale === 'ar' ? ChevronRight : ChevronLeft;
    const NextIcon = locale === 'ar' ? ChevronLeft : ChevronRight;
    const slide = current < slides.length ? slides[current] : null;
    const slideIndex = current;

    return (
        <div
            className="overflow-hidden rounded-2xl border border-white/10 bg-[#0d1f35]/70 backdrop-blur"
            data-test="landing-carousel"
        >
            <div className="relative aspect-video bg-[#081525]">
                {slide ? (
                    <img
                        key={`${slide.id}-${lang}`}
                        src={slide[`image_${lang}`]}
                        alt={slide[`title_${lang}`]}
                        loading="lazy"
                        className="animate-in fade-in size-full object-cover object-top duration-500"
                    />
                ) : total > 0 ? (
                    <div className="flex size-full flex-col items-center justify-center gap-3 p-8 text-center">
                        <div className="flex size-14 items-center justify-center rounded-2xl bg-sky-500/15 text-sky-300">
                            <RefreshCw className="size-7" />
                        </div>
                        <span className="rounded-full bg-amber-400/15 px-2.5 py-0.5 text-xs font-medium text-amber-300">
                            <E path="soon" value={c.soon} />
                        </span>
                    </div>
                ) : (
                    <div className="flex size-full flex-col items-center justify-center gap-2 text-sm text-slate-400">
                        <ImagePlus className="size-8" />
                        {editor.editing && t('platform.landing.no_slides')}
                    </div>
                )}
                <MediaOverlay
                    kind="carousel"
                    label={t('platform.landing.manage_slides')}
                />
                {total > 1 && (
                    <>
                        <button
                            type="button"
                            onClick={() => go(-1)}
                            className="absolute start-3 top-1/2 z-20 flex size-9 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/40 text-white backdrop-blur hover:bg-black/60"
                            aria-label="Previous"
                        >
                            <PrevIcon className="size-5" />
                        </button>
                        <button
                            type="button"
                            onClick={() => go(1)}
                            className="absolute end-3 top-1/2 z-20 flex size-9 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/40 text-white backdrop-blur hover:bg-black/60"
                            aria-label="Next"
                            data-test="landing-carousel-next"
                        >
                            <NextIcon className="size-5" />
                        </button>
                    </>
                )}
            </div>
            {total > 0 && (
                <div className="flex items-start justify-between gap-4 p-4">
                    <div>
                        <div className="font-semibold text-white">
                            {slide ? (
                                <E
                                    root
                                    key={`t-${slide.id}-${lang}`}
                                    path={`slides.${slideIndex}.title_${lang}`}
                                    value={slide[`title_${lang}`]}
                                />
                            ) : (
                                <E path="rs485.title" value={c.rs485.title} />
                            )}
                        </div>
                        <div className="text-sm text-slate-400">
                            {slide ? (
                                <E
                                    root
                                    key={`x-${slide.id}-${lang}`}
                                    path={`slides.${slideIndex}.text_${lang}`}
                                    value={slide[`text_${lang}`]}
                                />
                            ) : (
                                <E path="rs485.text" value={c.rs485.text} />
                            )}
                        </div>
                    </div>
                    <div className="flex shrink-0 gap-1.5 pt-1.5">
                        {Array.from({ length: total }, (_, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => setIndex(i)}
                                aria-label={`${i + 1}`}
                                className={cn(
                                    'h-1.5 rounded-full transition-all',
                                    i === current
                                        ? 'w-6 bg-sky-400'
                                        : 'w-1.5 bg-white/25 hover:bg-white/40',
                                )}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

function MediaHeading({ c }: { c: LandingText }) {
    return (
        <div className="mb-8 max-w-2xl">
            <div className="text-sm font-semibold text-sky-400">
                <E path="mediaKicker" value={c.mediaKicker} />
            </div>
            <h2 className="mt-2 text-3xl font-bold text-white">
                <E path="mediaTitle" value={c.mediaTitle} />
            </h2>
            <p className="mt-2 text-slate-400">
                <E path="mediaText" value={c.mediaText} />
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------------------------------- */

function SoonBadge({ label }: { label: string }) {
    return (
        <span className="rounded-full bg-amber-400/15 px-2 py-0.5 text-[11px] font-medium text-amber-300">
            <E path="soon" value={label} />
        </span>
    );
}

/** A section that has nothing to show yet is left out of the public page. */
function hasContent(content: LandingContent, id: SectionId): boolean {
    if (id === 'video') {
        return content.video.src !== null;
    }

    if (id === 'carousel') {
        return content.slides.length > 0 || content.showRoadmapSlide;
    }

    return true;
}

export function LandingPage({
    content,
    editor,
}: {
    content: LandingContent;
    editor?: EditorApi;
}) {
    const { locale, updateLocale } = useLocale();
    const lang = locale === 'ar' ? 'ar' : 'en';
    const c = content.text[lang];
    const Arrow = locale === 'ar' ? ArrowLeft : ArrowRight;
    const editing = editor !== undefined;
    const editorState: EditorState = editor
        ? { editing: true, ...editor }
        : { editing: false };

    // In the editor every section stays on screen (hidden ones dimmed) so it can be shown again.
    const list = editing
        ? content.sections
        : content.sections.filter(
              (s) => s.visible && hasContent(content, s.id),
          );
    const position = (id: SectionId) =>
        content.sections.findIndex((s) => s.id === id);
    const count = content.sections.length;
    const firstMedia = list.find(
        (s) => s.id === 'video' || s.id === 'carousel',
    )?.id;

    const frame = (
        section: LandingSection,
        children: ReactNode,
        media?: 'video' | 'carousel',
        className?: string,
    ) => (
        <SectionFrame
            key={section.id}
            section={section}
            position={position(section.id)}
            count={count}
            media={media}
            className={className}
        >
            {children}
        </SectionFrame>
    );

    const heroContent = (
        <div>
            <span className="inline-flex items-center gap-1.5 rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-medium text-sky-300">
                <Sparkles className="size-3.5" />
                <E path="badge" value={c.badge} />
            </span>
            <h1 className="mt-5 text-4xl font-extrabold leading-tight tracking-tight text-white sm:text-5xl lg:text-6xl">
                <E path="heroTitle1" value={c.heroTitle1} />
                <br />
                <span className="bg-gradient-to-r from-sky-300 via-sky-400 to-cyan-300 bg-clip-text text-transparent">
                    <E path="heroTitle2" value={c.heroTitle2} />
                </span>
            </h1>
            <p className="mt-5 max-w-xl text-base leading-relaxed text-slate-400 sm:text-lg">
                <E path="heroText" value={c.heroText} />
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
                <Cta
                    href={register().url}
                    className="inline-flex items-center gap-2 rounded-xl bg-[#f59e0b] px-5 py-3 font-semibold text-[#1a1203] shadow-xl shadow-orange-500/25 transition hover:brightness-110"
                    test="landing-hero-register"
                >
                    <E path="heroPrimary" value={c.heroPrimary} />
                    <Arrow className="size-4" />
                </Cta>
                <Cta
                    href="#media"
                    inertia={false}
                    className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-5 py-3 font-semibold text-white backdrop-blur transition hover:bg-white/10"
                >
                    <Play className="size-4 text-sky-300" />
                    <E path="heroSecondary" value={c.heroSecondary} />
                </Cta>
            </div>
            <ul className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-400">
                {c.trust.map((item, i) => (
                    <li key={i} className="flex items-center gap-1.5">
                        <ShieldCheck className="size-4 text-emerald-400" />
                        <E path={`trust.${i}`} value={item} />
                    </li>
                ))}
            </ul>
        </div>
    );

    const renderSections = (): ReactNode[] => {
        const out: ReactNode[] = [];

        for (let i = 0; i < list.length; i++) {
            const section = list[i];
            const next = list[i + 1];
            const pairs = (a: SectionId, b: SectionId) =>
                section.id === a &&
                next?.id === b &&
                section.visible &&
                next.visible;

            if (pairs('hero', 'demo')) {
                out.push(
                    <section
                        key="hero-demo"
                        id="demo"
                        className="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-14 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:pt-20"
                    >
                        {frame(section, heroContent)}
                        {frame(next, <DemoDashboard c={c} locale={locale} />)}
                    </section>,
                );
                i++;
                continue;
            }

            if (pairs('video', 'carousel')) {
                out.push(
                    <section
                        key="media"
                        id="media"
                        className="mx-auto max-w-6xl scroll-mt-20 px-4 pb-24 sm:px-6"
                    >
                        <MediaHeading c={c} />
                        <div
                            className={cn(
                                'grid gap-6 lg:grid-cols-2',
                                editing && 'mt-14',
                            )}
                        >
                            {frame(
                                section,
                                <VideoBlock content={content} c={c} />,
                                'video',
                            )}
                            {frame(
                                next,
                                <CarouselBlock
                                    content={content}
                                    c={c}
                                    locale={locale}
                                />,
                                'carousel',
                            )}
                        </div>
                    </section>,
                );
                i++;
                continue;
            }

            switch (section.id) {
                case 'hero':
                    out.push(
                        <section
                            key="hero"
                            className="mx-auto max-w-6xl px-4 pb-20 pt-14 sm:px-6 lg:pt-20"
                        >
                            {frame(
                                section,
                                <div className="max-w-3xl">{heroContent}</div>,
                            )}
                        </section>,
                    );
                    break;
                case 'demo':
                    out.push(
                        <section
                            key="demo"
                            id="demo"
                            className="mx-auto max-w-3xl scroll-mt-20 px-4 pb-20 sm:px-6"
                        >
                            {frame(
                                section,
                                <DemoDashboard c={c} locale={locale} />,
                            )}
                        </section>,
                    );
                    break;
                case 'video':
                case 'carousel':
                    out.push(
                        <section
                            key={section.id}
                            id={firstMedia === section.id ? 'media' : undefined}
                            className="mx-auto max-w-4xl scroll-mt-20 px-4 pb-24 sm:px-6"
                        >
                            {frame(
                                section,
                                <>
                                    {firstMedia === section.id && (
                                        <MediaHeading c={c} />
                                    )}
                                    {section.id === 'video' ? (
                                        <VideoBlock content={content} c={c} />
                                    ) : (
                                        <CarouselBlock
                                            content={content}
                                            c={c}
                                            locale={locale}
                                        />
                                    )}
                                </>,
                                section.id,
                            )}
                        </section>,
                    );
                    break;
                case 'features':
                    out.push(
                        <section
                            key="features"
                            id="features"
                            className="mx-auto max-w-6xl scroll-mt-20 px-4 pb-24 sm:px-6"
                        >
                            {frame(
                                section,
                                <>
                                    <div className="mb-10 max-w-2xl">
                                        <div className="text-sm font-semibold text-sky-400">
                                            <E
                                                path="featuresKicker"
                                                value={c.featuresKicker}
                                            />
                                        </div>
                                        <h2 className="mt-2 text-3xl font-bold text-white">
                                            <E
                                                path="featuresTitle"
                                                value={c.featuresTitle}
                                            />
                                        </h2>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        {c.features.map((feature, f) => {
                                            const Icon =
                                                FEATURE_ICONS[feature.icon] ??
                                                Fuel;

                                            return (
                                                <div
                                                    key={f}
                                                    className="group rounded-2xl border border-white/10 bg-white/[0.03] p-5 backdrop-blur transition hover:border-sky-400/30 hover:bg-white/[0.05]"
                                                    data-test="landing-feature"
                                                >
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="flex size-11 items-center justify-center rounded-xl bg-sky-400/10 text-sky-300 ring-1 ring-sky-400/20 transition group-hover:bg-sky-400/15">
                                                            <Icon className="size-5" />
                                                        </div>
                                                        {feature.soon && (
                                                            <SoonBadge
                                                                label={c.soon}
                                                            />
                                                        )}
                                                    </div>
                                                    <h3 className="mt-4 font-semibold text-white">
                                                        <E
                                                            path={`features.${f}.title`}
                                                            value={
                                                                feature.title
                                                            }
                                                        />
                                                    </h3>
                                                    <p className="mt-1.5 text-sm leading-relaxed text-slate-400">
                                                        <E
                                                            path={`features.${f}.text`}
                                                            value={feature.text}
                                                        />
                                                    </p>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </>,
                            )}
                        </section>,
                    );
                    break;
                case 'cta':
                    out.push(
                        <section
                            key="cta"
                            className="mx-auto max-w-6xl px-4 pb-24 sm:px-6"
                        >
                            {frame(
                                section,
                                <div className="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-[#10243a] via-[#0d1f35] to-[#0a2440] p-8 text-center sm:p-12">
                                    <div className="pointer-events-none absolute -top-24 start-1/2 h-64 w-[40rem] -translate-x-1/2 rounded-full bg-sky-500/20 blur-3xl rtl:translate-x-1/2" />
                                    <div className="relative">
                                        <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-[#f59e0b]/15 text-[#f59e0b]">
                                            <Building2 className="size-6" />
                                        </div>
                                        <h2 className="mt-4 text-3xl font-bold text-white">
                                            <E
                                                path="ctaTitle"
                                                value={c.ctaTitle}
                                            />
                                        </h2>
                                        <p className="mx-auto mt-3 max-w-xl text-slate-400">
                                            <E
                                                path="ctaText"
                                                value={c.ctaText}
                                            />
                                        </p>
                                        <div className="mt-7 flex flex-wrap justify-center gap-3">
                                            <Cta
                                                href={register().url}
                                                className="inline-flex items-center gap-2 rounded-xl bg-[#f59e0b] px-6 py-3 font-semibold text-[#1a1203] shadow-xl shadow-orange-500/25 transition hover:brightness-110"
                                                test="landing-cta-register"
                                            >
                                                <E
                                                    path="ctaButton"
                                                    value={c.ctaButton}
                                                />
                                                <Arrow className="size-4" />
                                            </Cta>
                                            <Cta
                                                href={login().url}
                                                className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-6 py-3 font-semibold text-white transition hover:bg-white/10"
                                            >
                                                <LockKeyhole className="size-4 text-sky-300" />
                                                <E
                                                    path="ctaLogin"
                                                    value={c.ctaLogin}
                                                />
                                            </Cta>
                                        </div>
                                    </div>
                                </div>,
                            )}
                        </section>,
                    );
                    break;
            }
        }

        return out;
    };

    return (
        <EditorContext.Provider value={editorState}>
            <TextPathContext.Provider value={(p) => `text.${lang}.${p}`}>
                {/* The landing page is always the dark navy theme, whatever the visitor's app setting. */}
                <div className="dark min-h-screen overflow-x-clip bg-[#06111f] text-slate-200 antialiased">
                    {!editing && (
                        <Head title={c.metaTitle}>
                            <meta
                                name="description"
                                content={c.metaDescription}
                            />
                        </Head>
                    )}

                    {/* Background glow */}
                    <div className="pointer-events-none fixed inset-0 overflow-hidden">
                        <div className="absolute -top-40 start-1/2 h-[36rem] w-[60rem] -translate-x-1/2 rounded-full bg-sky-500/10 blur-3xl rtl:translate-x-1/2" />
                        <div className="absolute -start-40 top-[40rem] h-96 w-96 rounded-full bg-orange-500/10 blur-3xl" />
                        <div
                            className="absolute inset-0 opacity-[0.07]"
                            style={{
                                backgroundImage:
                                    'linear-gradient(rgba(148,163,184,.5) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,.5) 1px, transparent 1px)',
                                backgroundSize: '48px 48px',
                                maskImage:
                                    'radial-gradient(ellipse at top, black 30%, transparent 75%)',
                            }}
                        />
                    </div>

                    <header
                        className={cn(
                            'z-40 border-b border-white/5 bg-[#06111f]/70 backdrop-blur-xl',
                            editing ? 'relative' : 'sticky top-0',
                        )}
                    >
                        <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
                            <a
                                href="#top"
                                className="flex items-center gap-2.5"
                            >
                                <div className="flex size-9 items-center justify-center rounded-lg bg-[#f59e0b] shadow-lg shadow-orange-500/20">
                                    <AppLogoIcon className="size-5 fill-current text-white" />
                                </div>
                                <span className="text-lg font-bold text-white">
                                    Patrol
                                </span>
                            </a>

                            <nav className="ms-6 hidden items-center gap-6 text-sm text-slate-400 md:flex">
                                <Cta
                                    href="#demo"
                                    inertia={false}
                                    className="hover:text-white"
                                >
                                    <E path="nav.demo" value={c.nav.demo} />
                                </Cta>
                                <Cta
                                    href="#media"
                                    inertia={false}
                                    className="hover:text-white"
                                >
                                    <E path="nav.media" value={c.nav.media} />
                                </Cta>
                                <Cta
                                    href="#features"
                                    inertia={false}
                                    className="hover:text-white"
                                >
                                    <E
                                        path="nav.features"
                                        value={c.nav.features}
                                    />
                                </Cta>
                            </nav>

                            <div className="ms-auto flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        updateLocale(
                                            locale === 'ar' ? 'en' : 'ar',
                                        )
                                    }
                                    className="flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm text-slate-300 hover:bg-white/5 hover:text-white"
                                    data-test="landing-locale-toggle"
                                >
                                    <Languages className="size-4" />
                                    {locale === 'ar' ? 'English' : 'العربية'}
                                </button>
                                <Cta
                                    href={login().url}
                                    className="hidden rounded-lg px-3 py-2 text-sm font-medium text-slate-200 hover:bg-white/5 sm:block"
                                >
                                    <E path="login" value={c.login} />
                                </Cta>
                                <Cta
                                    href={register().url}
                                    className="rounded-lg bg-[#f59e0b] px-3.5 py-2 text-sm font-semibold text-[#1a1203] shadow-lg shadow-orange-500/20 transition hover:brightness-110"
                                    test="landing-header-register"
                                >
                                    <E path="register" value={c.register} />
                                </Cta>
                            </div>
                        </div>
                    </header>

                    <main
                        id="top"
                        className={cn('relative', editing && 'pt-10')}
                    >
                        {renderSections()}
                    </main>

                    <footer className="relative border-t border-white/5">
                        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-sm text-slate-500 sm:px-6">
                            <span className="flex items-center gap-2">
                                <Users className="size-4" />
                                Patrol · <E path="footer" value={c.footer} />
                            </span>
                            <span>© {new Date().getFullYear()}</span>
                        </div>
                    </footer>
                </div>
            </TextPathContext.Provider>
        </EditorContext.Provider>
    );
}
