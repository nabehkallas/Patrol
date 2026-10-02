import { Head, Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowLeft,
    ArrowRight,
    BarChart3,
    Building2,
    ChevronLeft,
    ChevronRight,
    Coins,
    Database,
    Fuel,
    Gauge,
    Languages,
    LockKeyhole,
    Play,
    Receipt,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Truck,
    Users,
    WifiOff,
} from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { login, register } from '@/routes';

/* ------------------------------------------------------------------------------------------
 * Copy. Kept with the page (rather than the app-wide dictionary) since it is marketing text
 * that only this page uses.
 * ---------------------------------------------------------------------------------------- */

const COPY = {
    en: {
        metaTitle: 'Fuel station management',
        metaDescription:
            'Run your fuel station from one place: pump counters, multi-currency cash boxes, tanks, debts, Sadcop and reports.',
        nav: { demo: 'Live demo', media: 'Screenshots', features: 'Features' },
        login: 'Log in',
        register: 'Register your station',
        badge: 'Smart Gas Station Management System',
        heroTitle1: 'Your whole station,',
        heroTitle2: 'in one dashboard',
        heroText:
            'Record pump counters, close the cash box, track every tank and debt, and see your real profit, from any device.',
        heroPrimary: 'Register your station now',
        heroSecondary: 'Watch the demo',
        trust: [
            'Multi-currency & multi-cashbox support',
            'Sadcop ledger built in',
        ],
        demo: {
            live: 'Live demo',
            sample: 'Sample data',
            today: "Today's sales",
            shift: 'Current shift',
            liters: 'Liters sold',
            cash: 'Cash box total',
            shiftStatus: 'Shift status',
            shiftOpen: 'Open since',
            pumps: 'Active pumps',
            chartTitleToday: 'Hourly fuel throughput',
            chartTitleShift: 'Throughput this shift',
            revenue: 'Revenue',
            calc: 'How the total is calculated',
            petrol: 'Petrol',
            diesel: 'Diesel',
            perLiter: '/ L',
        },
        mediaKicker: 'See it in action',
        mediaTitle: 'The real app, not a mock-up',
        mediaText:
            'A short walkthrough and screenshots taken from a working station.',
        videoCaption:
            'Walkthrough: statistics, cash box, pump counters and inventory',
        slides: [
            {
                key: 'statistics',
                title: 'Statistics & reports',
                text: 'Income, expenses, liters sold and deliveries for any period, with PDF and Excel export.',
            },
            {
                key: 'cash-box',
                title: 'Cash box closing',
                text: 'Opening balance, income, expenses and the closing balance per currency, every day.',
            },
            {
                key: 'pump-counters',
                title: 'Pump counters',
                text: 'Enter every pump’s counter at once; liters and sales are worked out for you.',
            },
            {
                key: 'inventory',
                title: 'Tanks & inventory',
                text: 'Expected vs. measured stock in each tank, with deliveries and transfers.',
            },
        ],
        rs485: {
            title: 'RS485 pump gateways',
            text: 'Reading counters straight from the pumps is on our roadmap.',
        },
        soon: 'Coming soon',
        featuresKicker: 'Features',
        featuresTitle: 'Everything a station runs on',
        features: [
            {
                icon: 'shield',
                title: 'Shift & cash box security',
                text: 'Admin and attendant roles, a password-protected earnings area, and a cash box every movement passes through.',
            },
            {
                icon: 'database',
                title: 'Multi-tenant data isolation',
                text: 'Each station has its own separate database. No station can ever see another’s data.',
            },
            {
                icon: 'wifi',
                title: 'Offline operations & auto-sync',
                text: 'Keep recording when the internet drops and sync automatically when it’s back.',
                soon: true,
            },
            {
                icon: 'gauge',
                title: 'Pump counters & tanks',
                text: 'Daily counter entry for all pumps, tank levels, top-ups and transfers.',
            },
            {
                icon: 'truck',
                title: 'Sadcop ledger',
                text: 'Deposits and deliveries tracked against your Sadcop balance automatically.',
            },
            {
                icon: 'receipt',
                title: 'Debts & debtors',
                text: 'Who owes what, partial payments, and governmental sales, all in one place.',
            },
            {
                icon: 'coins',
                title: 'Multi-currency & multi-cashbox',
                text: 'Work in any currency you choose, each with its own cash box balance, converted into one total at your own exchange rates.',
            },
            {
                icon: 'chart',
                title: 'Real profit',
                text: 'Earnings per fuel type and shop item, based on what each liter actually cost you.',
            },
            {
                icon: 'refresh',
                title: 'RS485 pump gateways',
                text: 'Read counters directly from the dispensers, no manual entry.',
                soon: true,
            },
        ],
        ctaTitle: 'Ready to run your station properly?',
        ctaText:
            'Register in a minute. We review every station and activate it, usually the same day.',
        ctaButton: 'Register your station',
        ctaLogin: 'I already have an account',
        footer: 'Fuel station management',
    },
    ar: {
        metaTitle: 'إدارة محطات الوقود',
        metaDescription:
            'أدر محطتك من مكان واحد: عدادات المضخات، صناديق نقد متعددة العملات، الخزانات، الديون، سادكوب والتقارير.',
        nav: { demo: 'عرض تفاعلي', media: 'لقطات الشاشة', features: 'المزايا' },
        login: 'تسجيل الدخول',
        register: 'سجّل محطتك الآن',
        badge: 'نظام إدارة محطات الوقود الذكي',
        heroTitle1: 'محطتك بالكامل،',
        heroTitle2: 'في لوحة تحكم واحدة',
        heroText:
            'سجّل عدادات المضخات، أغلق صندوق النقد، تابع كل خزان وكل دين، واعرف ربحك الحقيقي، من أي جهاز.',
        heroPrimary: 'سجّل محطتك الآن',
        heroSecondary: 'شاهد العرض',
        trust: ['دعم شامل لتعدد العملات والصناديق', 'دفتر سادكوب مدمج'],
        demo: {
            live: 'عرض مباشر',
            sample: 'بيانات تجريبية',
            today: 'مبيعات اليوم',
            shift: 'الوردية الحالية',
            liters: 'اللترات المباعة',
            cash: 'إجمالي صندوق النقد',
            shiftStatus: 'حالة الوردية',
            shiftOpen: 'مفتوحة منذ',
            pumps: 'المضخات النشطة',
            chartTitleToday: 'كمية الوقود المباعة كل ساعة',
            chartTitleShift: 'المبيعات خلال الوردية',
            revenue: 'الإيرادات',
            calc: 'كيف يُحسب الإجمالي',
            petrol: 'بنزين',
            diesel: 'مازوت',
            perLiter: '/ لتر',
        },
        mediaKicker: 'شاهده يعمل',
        mediaTitle: 'التطبيق الحقيقي، وليس نموذجاً',
        mediaText: 'جولة قصيرة ولقطات شاشة مأخوذة من محطة تعمل.',
        videoCaption: 'جولة: الإحصائيات، صندوق النقد، عدادات المضخات والمخزون',
        slides: [
            {
                key: 'statistics',
                title: 'الإحصائيات والتقارير',
                text: 'الإيرادات والمصروفات واللترات المباعة والتوريدات لأي فترة، مع تصدير PDF وإكسل.',
            },
            {
                key: 'cash-box',
                title: 'إغلاق صندوق النقد',
                text: 'الرصيد الافتتاحي والإيرادات والمصروفات والرصيد الختامي لكل عملة، يومياً.',
            },
            {
                key: 'pump-counters',
                title: 'عدادات المضخات',
                text: 'أدخل عدادات جميع المضخات دفعة واحدة، ويتم حساب اللترات والمبيعات تلقائياً.',
            },
            {
                key: 'inventory',
                title: 'الخزانات والمخزون',
                text: 'المخزون المتوقع مقابل المقاس في كل خزان، مع التوريدات والتحويلات.',
            },
        ],
        rs485: {
            title: 'بوابات المضخات RS485',
            text: 'قراءة العدادات مباشرة من المضخات ضمن خطتنا القادمة.',
        },
        soon: 'قريباً',
        featuresKicker: 'المزايا',
        featuresTitle: 'كل ما تحتاجه المحطة',
        features: [
            {
                icon: 'shield',
                title: 'أمان الورديات وصندوق النقد',
                text: 'أدوار المدير والموظف، منطقة أرباح محمية بكلمة مرور، وصندوق نقد تمر عبره كل حركة.',
            },
            {
                icon: 'database',
                title: 'عزل كامل لبيانات كل محطة',
                text: 'لكل محطة قاعدة بيانات مستقلة، ولا يمكن لأي محطة رؤية بيانات محطة أخرى.',
            },
            {
                icon: 'wifi',
                title: 'العمل دون اتصال والمزامنة التلقائية',
                text: 'تابع التسجيل عند انقطاع الإنترنت وتتم المزامنة تلقائياً عند عودته.',
                soon: true,
            },
            {
                icon: 'gauge',
                title: 'عدادات المضخات والخزانات',
                text: 'إدخال يومي لعدادات جميع المضخات، ومستويات الخزانات، والتعبئة والتحويلات.',
            },
            {
                icon: 'truck',
                title: 'دفتر سادكوب',
                text: 'متابعة الإيداعات والتوريدات مقابل رصيد سادكوب تلقائياً.',
            },
            {
                icon: 'receipt',
                title: 'الديون والمدينون',
                text: 'من عليه ماذا، والدفعات الجزئية، والمبيعات الحكومية، في مكان واحد.',
            },
            {
                icon: 'coins',
                title: 'تعدد العملات والصناديق',
                text: 'تعامل بأي عملة تختارها، ولكل عملة رصيد صندوق خاص بها، مع إجمالي موحّد بأسعار صرفك.',
            },
            {
                icon: 'chart',
                title: 'الربح الحقيقي',
                text: 'الأرباح لكل نوع وقود ولكل منتج في المتجر، حسب التكلفة الفعلية لكل لتر.',
            },
            {
                icon: 'refresh',
                title: 'بوابات المضخات RS485',
                text: 'قراءة العدادات مباشرة من المضخات دون إدخال يدوي.',
                soon: true,
            },
        ],
        ctaTitle: 'جاهز لإدارة محطتك باحتراف؟',
        ctaText:
            'سجّل خلال دقيقة. نراجع كل محطة ونفعّلها، عادةً في اليوم نفسه.',
        ctaButton: 'سجّل محطتك الآن',
        ctaLogin: 'لدي حساب بالفعل',
        footer: 'إدارة محطات الوقود',
    },
} as const;

type Copy = (typeof COPY)['en'] | (typeof COPY)['ar'];

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

/* ------------------------------------------------------------------------------------------
 * Interactive demo: simulated station numbers that tick up like a live dashboard.
 * ---------------------------------------------------------------------------------------- */

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

const nf = (locale: string, digits = 0) =>
    new Intl.NumberFormat('en-US', {
        maximumFractionDigits: digits,
        minimumFractionDigits: digits,
    });

function useTicker(active: boolean) {
    // Adds a few liters every couple of seconds so the counters feel live.
    const [extra, setExtra] = useState({ petrol: 0, diesel: 0, ticks: 0 });

    useEffect(() => {
        if (!active) {
            return;
        }

        const id = window.setInterval(() => {
            setExtra((e) => ({
                petrol: e.petrol + 6 + Math.round(Math.random() * 14),
                diesel: e.diesel + 3 + Math.round(Math.random() * 9),
                ticks: e.ticks + 1,
            }));
        }, 2200);

        return () => window.clearInterval(id);
    }, [active]);

    return extra;
}

function DemoDashboard({ c, locale }: { c: Copy; locale: string }) {
    const [mode, setMode] = useState<'today' | 'shift'>('today');
    const extra = useTicker(true);
    const hours = SAMPLE_HOURS;

    // "Current shift" = the afternoon shift (14:00 onwards); "today" = the whole day.
    const visible =
        mode === 'today' ? hours : hours.filter((h) => h.hour >= 14);
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

    const n0 = nf(locale);
    const compact = new Intl.NumberFormat('en-US', {
        notation: 'compact',
        maximumFractionDigits: 1,
    });

    const kpis: { label: string; value: string; sub?: string; tone: string }[] =
        [
            {
                label: c.demo.liters,
                value: `${n0.format(petrolL + dieselL)} L`,
                tone: 'text-sky-300',
            },
            {
                label: c.demo.cash,
                value: `${compact.format(revenue)} SYP`,
                tone: 'text-emerald-300',
            },
            {
                label: c.demo.shiftStatus,
                value: `${c.demo.shiftOpen} ${mode === 'today' ? '06:00' : '14:00'}`,
                tone: 'text-amber-300',
            },
            {
                label: c.demo.pumps,
                value: `${activePumps} / 4`,
                tone: 'text-violet-300',
            },
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
                            {c.demo.live}
                        </span>
                        <span className="rounded-full border border-white/10 px-2 py-0.5 text-[11px] text-slate-400">
                            {c.demo.sample}
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
                                {m === 'today' ? c.demo.today : c.demo.shift}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-2.5">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.label}
                            className="rounded-xl border border-white/5 bg-white/[0.04] p-3"
                        >
                            <div className="text-[11px] text-slate-400">
                                {kpi.label}
                            </div>
                            <div
                                className={cn(
                                    'mt-0.5 text-lg font-bold tabular-nums transition-all',
                                    kpi.tone,
                                )}
                                dir="ltr"
                            >
                                {kpi.value}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="mt-4 rounded-xl border border-white/5 bg-white/[0.03] p-3">
                    <div className="mb-2 flex items-center justify-between text-xs">
                        <span className="font-medium text-slate-200">
                            {mode === 'today'
                                ? c.demo.chartTitleToday
                                : c.demo.chartTitleShift}
                        </span>
                        <span className="flex items-center gap-3 text-slate-400">
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-full bg-sky-400" />
                                L
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-full bg-amber-400" />
                                {c.demo.revenue}
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
                        {c.demo.calc}
                    </div>
                    {[
                        {
                            name: c.demo.petrol,
                            liters: petrolL,
                            price: PRICE.petrol,
                            dot: 'bg-amber-400',
                        },
                        {
                            name: c.demo.diesel,
                            liters: dieselL,
                            price: PRICE.diesel,
                            dot: 'bg-sky-400',
                        },
                    ].map((row) => (
                        <div
                            key={row.name}
                            className="flex items-center justify-between gap-2 py-1 text-slate-300"
                        >
                            <span className="flex items-center gap-1.5">
                                <span
                                    className={cn(
                                        'size-2 rounded-full',
                                        row.dot,
                                    )}
                                />
                                {row.name}
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
                        <span>{c.demo.cash}</span>
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
 * Media: walkthrough video + screenshot carousel (screenshots taken from the real app).
 * ---------------------------------------------------------------------------------------- */

function MediaShowcase({ c, locale }: { c: Copy; locale: string }) {
    const [index, setIndex] = useState(0);
    const slides = c.slides;
    const total = slides.length + 1; // + the RS485 "coming soon" card
    const rtl = locale === 'ar';

    useEffect(() => {
        const id = window.setInterval(
            () => setIndex((i) => (i + 1) % total),
            6000,
        );

        return () => window.clearInterval(id);
    }, [total, index]);

    const go = (delta: number) => setIndex((i) => (i + delta + total) % total);
    const PrevIcon = rtl ? ChevronRight : ChevronLeft;
    const NextIcon = rtl ? ChevronLeft : ChevronRight;
    const slide = index < slides.length ? slides[index] : null;

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <figure className="overflow-hidden rounded-2xl border border-white/10 bg-[#0d1f35]/70 backdrop-blur">
                <video
                    className="aspect-video w-full bg-black"
                    controls
                    preload="none"
                    playsInline
                    muted
                    poster="/landing/demo-poster.jpg"
                    data-test="landing-video"
                >
                    <source
                        src="/landing/demo-walkthrough.webm"
                        type="video/webm"
                    />
                </video>
                <figcaption className="flex items-center gap-2 p-4 text-sm text-slate-300">
                    <Play className="size-4 text-sky-400" />
                    {c.videoCaption}
                </figcaption>
            </figure>

            <div
                className="overflow-hidden rounded-2xl border border-white/10 bg-[#0d1f35]/70 backdrop-blur"
                data-test="landing-carousel"
            >
                <div className="relative aspect-video bg-[#081525]">
                    {slide ? (
                        <img
                            key={`${slide.key}-${locale}`}
                            src={`/landing/${slide.key}-${locale}.jpg`}
                            alt={slide.title}
                            loading="lazy"
                            className="animate-in fade-in size-full object-cover object-top duration-500"
                        />
                    ) : (
                        <div className="flex size-full flex-col items-center justify-center gap-3 p-8 text-center">
                            <div className="flex size-14 items-center justify-center rounded-2xl bg-sky-500/15 text-sky-300">
                                <RefreshCw className="size-7" />
                            </div>
                            <span className="rounded-full bg-amber-400/15 px-2.5 py-0.5 text-xs font-medium text-amber-300">
                                {c.soon}
                            </span>
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={() => go(-1)}
                        className="absolute start-3 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/40 text-white backdrop-blur hover:bg-black/60"
                        aria-label="Previous"
                    >
                        <PrevIcon className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => go(1)}
                        className="absolute end-3 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/40 text-white backdrop-blur hover:bg-black/60"
                        aria-label="Next"
                        data-test="landing-carousel-next"
                    >
                        <NextIcon className="size-5" />
                    </button>
                </div>
                <div className="flex items-start justify-between gap-4 p-4">
                    <div>
                        <div className="font-semibold text-white">
                            {slide ? slide.title : c.rs485.title}
                        </div>
                        <div className="text-sm text-slate-400">
                            {slide ? slide.text : c.rs485.text}
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
                                    i === index
                                        ? 'w-6 bg-sky-400'
                                        : 'w-1.5 bg-white/25 hover:bg-white/40',
                                )}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------------------------------- */

function SoonBadge({ label }: { label: string }) {
    return (
        <span className="rounded-full bg-amber-400/15 px-2 py-0.5 text-[11px] font-medium text-amber-300">
            {label}
        </span>
    );
}

export default function Welcome() {
    const { locale, updateLocale } = useLocale();
    const c: Copy = COPY[locale === 'ar' ? 'ar' : 'en'];
    const Arrow = locale === 'ar' ? ArrowLeft : ArrowRight;

    return (
        // The landing page is always the dark navy theme, whatever the visitor's app setting.
        <div className="dark min-h-screen overflow-x-clip bg-[#06111f] text-slate-200 antialiased">
            <Head title={c.metaTitle}>
                <meta name="description" content={c.metaDescription} />
            </Head>

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

            <header className="sticky top-0 z-40 border-b border-white/5 bg-[#06111f]/70 backdrop-blur-xl">
                <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
                    <a href="#top" className="flex items-center gap-2.5">
                        <div className="flex size-9 items-center justify-center rounded-lg bg-[#f59e0b] shadow-lg shadow-orange-500/20">
                            <AppLogoIcon className="size-5 fill-current text-white" />
                        </div>
                        <span className="text-lg font-bold text-white">
                            Patrol
                        </span>
                    </a>

                    <nav className="ms-6 hidden items-center gap-6 text-sm text-slate-400 md:flex">
                        <a href="#demo" className="hover:text-white">
                            {c.nav.demo}
                        </a>
                        <a href="#media" className="hover:text-white">
                            {c.nav.media}
                        </a>
                        <a href="#features" className="hover:text-white">
                            {c.nav.features}
                        </a>
                    </nav>

                    <div className="ms-auto flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                updateLocale(locale === 'ar' ? 'en' : 'ar')
                            }
                            className="flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm text-slate-300 hover:bg-white/5 hover:text-white"
                            data-test="landing-locale-toggle"
                        >
                            <Languages className="size-4" />
                            {locale === 'ar' ? 'English' : 'العربية'}
                        </button>
                        <Link
                            href={login()}
                            className="hidden rounded-lg px-3 py-2 text-sm font-medium text-slate-200 hover:bg-white/5 sm:block"
                        >
                            {c.login}
                        </Link>
                        <Link
                            href={register()}
                            className="rounded-lg bg-[#f59e0b] px-3.5 py-2 text-sm font-semibold text-[#1a1203] shadow-lg shadow-orange-500/20 transition hover:brightness-110"
                            data-test="landing-header-register"
                        >
                            {c.register}
                        </Link>
                    </div>
                </div>
            </header>

            <main id="top" className="relative">
                {/* Hero */}
                <section
                    id="demo"
                    className="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-14 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:pt-20"
                >
                    <div>
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-medium text-sky-300">
                            <Sparkles className="size-3.5" />
                            {c.badge}
                        </span>
                        <h1 className="mt-5 text-4xl font-extrabold leading-tight tracking-tight text-white sm:text-5xl lg:text-6xl">
                            {c.heroTitle1}
                            <br />
                            <span className="bg-gradient-to-r from-sky-300 via-sky-400 to-cyan-300 bg-clip-text text-transparent">
                                {c.heroTitle2}
                            </span>
                        </h1>
                        <p className="mt-5 max-w-xl text-base leading-relaxed text-slate-400 sm:text-lg">
                            {c.heroText}
                        </p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link
                                href={register()}
                                className="inline-flex items-center gap-2 rounded-xl bg-[#f59e0b] px-5 py-3 font-semibold text-[#1a1203] shadow-xl shadow-orange-500/25 transition hover:brightness-110"
                                data-test="landing-hero-register"
                            >
                                {c.heroPrimary}
                                <Arrow className="size-4" />
                            </Link>
                            <a
                                href="#media"
                                className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-5 py-3 font-semibold text-white backdrop-blur transition hover:bg-white/10"
                            >
                                <Play className="size-4 text-sky-300" />
                                {c.heroSecondary}
                            </a>
                        </div>
                        <ul className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-400">
                            {c.trust.map((item) => (
                                <li
                                    key={item}
                                    className="flex items-center gap-1.5"
                                >
                                    <ShieldCheck className="size-4 text-emerald-400" />
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <DemoDashboard c={c} locale={locale} />
                </section>

                {/* Media */}
                <section
                    id="media"
                    className="mx-auto max-w-6xl scroll-mt-20 px-4 pb-24 sm:px-6"
                >
                    <div className="mb-8 max-w-2xl">
                        <div className="text-sm font-semibold text-sky-400">
                            {c.mediaKicker}
                        </div>
                        <h2 className="mt-2 text-3xl font-bold text-white">
                            {c.mediaTitle}
                        </h2>
                        <p className="mt-2 text-slate-400">{c.mediaText}</p>
                    </div>
                    <MediaShowcase c={c} locale={locale} />
                </section>

                {/* Features */}
                <section
                    id="features"
                    className="mx-auto max-w-6xl scroll-mt-20 px-4 pb-24 sm:px-6"
                >
                    <div className="mb-10 max-w-2xl">
                        <div className="text-sm font-semibold text-sky-400">
                            {c.featuresKicker}
                        </div>
                        <h2 className="mt-2 text-3xl font-bold text-white">
                            {c.featuresTitle}
                        </h2>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {c.features.map((feature) => {
                            const Icon = FEATURE_ICONS[feature.icon] ?? Fuel;
                            const soon = 'soon' in feature && feature.soon;

                            return (
                                <div
                                    key={feature.title}
                                    className="group rounded-2xl border border-white/10 bg-white/[0.03] p-5 backdrop-blur transition hover:border-sky-400/30 hover:bg-white/[0.05]"
                                    data-test="landing-feature"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex size-11 items-center justify-center rounded-xl bg-sky-400/10 text-sky-300 ring-1 ring-sky-400/20 transition group-hover:bg-sky-400/15">
                                            <Icon className="size-5" />
                                        </div>
                                        {soon && <SoonBadge label={c.soon} />}
                                    </div>
                                    <h3 className="mt-4 font-semibold text-white">
                                        {feature.title}
                                    </h3>
                                    <p className="mt-1.5 text-sm leading-relaxed text-slate-400">
                                        {feature.text}
                                    </p>
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* CTA */}
                <section className="mx-auto max-w-6xl px-4 pb-24 sm:px-6">
                    <div className="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-[#10243a] via-[#0d1f35] to-[#0a2440] p-8 text-center sm:p-12">
                        <div className="pointer-events-none absolute -top-24 start-1/2 h-64 w-[40rem] -translate-x-1/2 rounded-full bg-sky-500/20 blur-3xl rtl:translate-x-1/2" />
                        <div className="relative">
                            <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-[#f59e0b]/15 text-[#f59e0b]">
                                <Building2 className="size-6" />
                            </div>
                            <h2 className="mt-4 text-3xl font-bold text-white">
                                {c.ctaTitle}
                            </h2>
                            <p className="mx-auto mt-3 max-w-xl text-slate-400">
                                {c.ctaText}
                            </p>
                            <div className="mt-7 flex flex-wrap justify-center gap-3">
                                <Link
                                    href={register()}
                                    className="inline-flex items-center gap-2 rounded-xl bg-[#f59e0b] px-6 py-3 font-semibold text-[#1a1203] shadow-xl shadow-orange-500/25 transition hover:brightness-110"
                                    data-test="landing-cta-register"
                                >
                                    {c.ctaButton}
                                    <Arrow className="size-4" />
                                </Link>
                                <Link
                                    href={login()}
                                    className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-6 py-3 font-semibold text-white transition hover:bg-white/10"
                                >
                                    <LockKeyhole className="size-4 text-sky-300" />
                                    {c.ctaLogin}
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <footer className="relative border-t border-white/5">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-sm text-slate-500 sm:px-6">
                    <span className="flex items-center gap-2">
                        <Users className="size-4" />
                        Patrol · {c.footer}
                    </span>
                    <span>© {new Date().getFullYear()}</span>
                </div>
            </footer>
        </div>
    );
}
