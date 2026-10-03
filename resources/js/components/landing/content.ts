/**
 * The landing page's content: texts (per language), section order and visibility, the video and
 * the screenshot carousel. DEFAULT_CONTENT is the built-in page; what a platform admin publishes
 * from the visual editor is stored as a full LandingContent and merged over it, so new texts or
 * sections added here later still show up on top of an older published version.
 */

export type LandingFeature = {
    icon: string;
    title: string;
    text: string;
    soon?: boolean;
};

export type LandingText = {
    metaTitle: string;
    metaDescription: string;
    nav: { demo: string; media: string; features: string };
    login: string;
    register: string;
    badge: string;
    heroTitle1: string;
    heroTitle2: string;
    heroText: string;
    heroPrimary: string;
    heroSecondary: string;
    trust: string[];
    demo: Record<string, string>;
    mediaKicker: string;
    mediaTitle: string;
    mediaText: string;
    videoCaption: string;
    rs485: { title: string; text: string };
    soon: string;
    featuresKicker: string;
    featuresTitle: string;
    features: LandingFeature[];
    ctaTitle: string;
    ctaText: string;
    ctaButton: string;
    ctaLogin: string;
    footer: string;
};

export type SectionId =
    | 'hero'
    | 'demo'
    | 'video'
    | 'carousel'
    | 'features'
    | 'cta';

export type LandingSection = { id: SectionId; visible: boolean };

export type LandingSlide = {
    id: string;
    image_en: string;
    image_ar: string;
    title_en: string;
    title_ar: string;
    text_en: string;
    text_ar: string;
};

export type LandingContent = {
    sections: LandingSection[];
    text: { en: LandingText; ar: LandingText };
    video: { src: string | null; poster: string | null };
    slides: LandingSlide[];
    /** The "coming soon" RS485 card at the end of the carousel. */
    showRoadmapSlide: boolean;
};

export const SECTION_IDS: SectionId[] = [
    'hero',
    'demo',
    'video',
    'carousel',
    'features',
    'cta',
];

export const DEFAULT_CONTENT: LandingContent = {
    sections: [
        {
            id: 'hero',
            visible: true,
        },
        {
            id: 'demo',
            visible: true,
        },
        {
            id: 'video',
            visible: true,
        },
        {
            id: 'carousel',
            visible: true,
        },
        {
            id: 'features',
            visible: true,
        },
        {
            id: 'cta',
            visible: true,
        },
    ],
    text: {
        en: {
            metaTitle: 'Fuel station management',
            metaDescription:
                'Run your fuel station from one place: pump counters, multi-currency cash boxes, tanks, debts, Sadcop and reports.',
            nav: {
                demo: 'Live demo',
                media: 'Screenshots',
                features: 'Features',
            },
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
            nav: {
                demo: 'عرض تفاعلي',
                media: 'لقطات الشاشة',
                features: 'المزايا',
            },
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
            videoCaption:
                'جولة: الإحصائيات، صندوق النقد، عدادات المضخات والمخزون',
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
    },
    video: {
        src: '/landing/demo-walkthrough.webm',
        poster: '/landing/demo-poster.jpg',
    },
    slides: [
        {
            id: 'statistics',
            image_en: '/landing/statistics-en.jpg',
            image_ar: '/landing/statistics-ar.jpg',
            title_en: 'Statistics & reports',
            title_ar: 'الإحصائيات والتقارير',
            text_en:
                'Income, expenses, liters sold and deliveries for any period, with PDF and Excel export.',
            text_ar:
                'الإيرادات والمصروفات واللترات المباعة والتوريدات لأي فترة، مع تصدير PDF وإكسل.',
        },
        {
            id: 'cash-box',
            image_en: '/landing/cash-box-en.jpg',
            image_ar: '/landing/cash-box-ar.jpg',
            title_en: 'Cash box closing',
            title_ar: 'إغلاق صندوق النقد',
            text_en:
                'Opening balance, income, expenses and the closing balance per currency, every day.',
            text_ar:
                'الرصيد الافتتاحي والإيرادات والمصروفات والرصيد الختامي لكل عملة، يومياً.',
        },
        {
            id: 'pump-counters',
            image_en: '/landing/pump-counters-en.jpg',
            image_ar: '/landing/pump-counters-ar.jpg',
            title_en: 'Pump counters',
            title_ar: 'عدادات المضخات',
            text_en:
                'Enter every pump’s counter at once; liters and sales are worked out for you.',
            text_ar:
                'أدخل عدادات جميع المضخات دفعة واحدة، ويتم حساب اللترات والمبيعات تلقائياً.',
        },
        {
            id: 'inventory',
            image_en: '/landing/inventory-en.jpg',
            image_ar: '/landing/inventory-ar.jpg',
            title_en: 'Tanks & inventory',
            title_ar: 'الخزانات والمخزون',
            text_en:
                'Expected vs. measured stock in each tank, with deliveries and transfers.',
            text_ar:
                'المخزون المتوقع مقابل المقاس في كل خزان، مع التوريدات والتحويلات.',
        },
    ],
    showRoadmapSlide: true,
};

/* ------------------------------------------------------------------------------------------
 * Merging a published version over the defaults, and editing by path.
 * ---------------------------------------------------------------------------------------- */

const isObject = (v: unknown): v is Record<string, unknown> =>
    typeof v === 'object' && v !== null && !Array.isArray(v);

/** Overlays `over` on `base`, keeping base's shape: unknown keys and wrong types are ignored. */
function overlay(base: unknown, over: unknown): unknown {
    if (Array.isArray(base)) {
        if (!Array.isArray(over)) {
            return structuredClone(base);
        }

        // Lists of objects (features) merge item by item; lists of strings are replaced.
        if (base.length > 0 && isObject(base[0])) {
            return over
                .filter(isObject)
                .map((item, i) =>
                    overlay(base[i] ?? base[base.length - 1], item),
                );
        }

        return over.filter((item) => typeof item === 'string');
    }

    if (isObject(base)) {
        const result: Record<string, unknown> = {};
        const source = isObject(over) ? over : {};

        for (const key of new Set([
            ...Object.keys(base),
            ...Object.keys(source),
        ])) {
            if (key in base) {
                result[key] = overlay(base[key], source[key]);
            } else if (['string', 'boolean'].includes(typeof source[key])) {
                result[key] = source[key];
            }
        }

        return result;
    }

    if (base === undefined) {
        return over;
    }

    return typeof over === typeof base ? over : base;
}

export function mergeContent(stored: unknown): LandingContent {
    const base = structuredClone(DEFAULT_CONTENT);

    if (!isObject(stored)) {
        return base;
    }

    const seen = new Set<SectionId>();
    const sections: LandingSection[] = [];

    if (Array.isArray(stored.sections)) {
        for (const s of stored.sections) {
            if (
                isObject(s) &&
                SECTION_IDS.includes(s.id as SectionId) &&
                !seen.has(s.id as SectionId)
            ) {
                seen.add(s.id as SectionId);
                sections.push({
                    id: s.id as SectionId,
                    visible: s.visible !== false,
                });
            }
        }
    }

    for (const id of SECTION_IDS) {
        if (!seen.has(id)) {
            sections.push({ id, visible: true });
        }
    }

    const text = isObject(stored.text) ? stored.text : {};
    const video = isObject(stored.video) ? stored.video : null;

    return {
        sections,
        text: {
            en: overlay(base.text.en, text.en) as LandingText,
            ar: overlay(base.text.ar, text.ar) as LandingText,
        },
        video: video
            ? {
                  src: typeof video.src === 'string' ? video.src : null,
                  poster:
                      typeof video.poster === 'string' ? video.poster : null,
              }
            : base.video,
        slides: Array.isArray(stored.slides)
            ? stored.slides
                  .filter(isObject)
                  .map((s) => overlay(base.slides[0], s) as LandingSlide)
            : base.slides,
        showRoadmapSlide:
            typeof stored.showRoadmapSlide === 'boolean'
                ? stored.showRoadmapSlide
                : base.showRoadmapSlide,
    };
}

/** Returns a copy of `content` with the value at a dotted path ("text.en.features.2.title") replaced. */
export function setAtPath<T>(content: T, path: string, value: unknown): T {
    const copy = structuredClone(content) as Record<string, unknown>;
    const keys = path.split('.');
    let node: Record<string, unknown> | unknown[] = copy;

    keys.slice(0, -1).forEach((key) => {
        node = (node as Record<string, unknown>)[key] as Record<
            string,
            unknown
        >;
    });
    (node as Record<string, unknown>)[keys[keys.length - 1]] = value;

    return copy as T;
}
