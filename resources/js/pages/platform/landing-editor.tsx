import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    ExternalLink,
    FileText,
    ImagePlus,
    Languages,
    LoaderCircle,
    RotateCcw,
    Send,
    Trash2,
    Undo2,
    Upload,
    Video,
} from 'lucide-react';
import type { ChangeEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type {
    LandingContent,
    LandingSlide,
    SectionId,
} from '@/components/landing/content';
import {
    DEFAULT_CONTENT,
    mergeContent,
    setAtPath,
} from '@/components/landing/content';
import type { EditorApi } from '@/components/landing/landing-page';
import { LandingPage } from '@/components/landing/landing-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useLocale } from '@/hooks/use-locale';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { home } from '@/routes/platform';
import { publish, upload } from '@/routes/platform/landing';

/* ------------------------------------------------------------------------------------------
 * Uploading media (XHR rather than fetch so large videos can show progress).
 * ---------------------------------------------------------------------------------------- */

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function uploadMedia(
    file: File,
    onProgress: (percent: number) => void,
): Promise<string> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const body = new FormData();
        body.append('file', file);

        xhr.open('POST', upload.url());
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        };
        xhr.onload = () => {
            try {
                const data = JSON.parse(xhr.responseText);

                if (xhr.status >= 200 && xhr.status < 300 && data.url) {
                    resolve(data.url as string);
                } else {
                    reject(
                        new Error(
                            data.errors?.file?.[0] ??
                                data.message ??
                                `HTTP ${xhr.status}`,
                        ),
                    );
                }
            } catch {
                reject(
                    new Error(
                        xhr.status === 413
                            ? 'File too large'
                            : `HTTP ${xhr.status}`,
                    ),
                );
            }
        };
        xhr.onerror = () => reject(new Error('Network error'));
        xhr.send(body);
    });
}

function useUploader() {
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const run = async (
        file: File | undefined,
        onDone: (url: string) => void,
    ) => {
        if (!file) {
            return;
        }

        setError(null);
        setProgress(0);

        try {
            onDone(await uploadMedia(file, setProgress));
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setProgress(null);
        }
    };

    return { progress, error, run };
}

function FilePicker({
    accept,
    label,
    onFile,
    disabled,
    variant = 'outline',
    test,
}: {
    accept: string;
    label: string;
    onFile: (file: File | undefined) => void;
    disabled?: boolean;
    variant?: 'outline' | 'default';
    test?: string;
}) {
    const input = useRef<HTMLInputElement>(null);

    return (
        <>
            <input
                ref={input}
                type="file"
                accept={accept}
                className="sr-only"
                data-test={test}
                onChange={(e: ChangeEvent<HTMLInputElement>) => {
                    onFile(e.target.files?.[0]);
                    e.target.value = '';
                }}
            />
            <Button
                type="button"
                variant={variant}
                size="sm"
                disabled={disabled}
                onClick={() => input.current?.click()}
            >
                <Upload className="size-4" />
                {label}
            </Button>
        </>
    );
}

function UploadStatus({
    progress,
    error,
}: {
    progress: number | null;
    error: string | null;
}) {
    const { t } = useTranslation();

    return (
        <>
            {progress !== null && (
                <div className="space-y-1">
                    <div className="text-muted-foreground flex items-center gap-2 text-xs">
                        <LoaderCircle className="size-3.5 animate-spin" />
                        {t('platform.landing.uploading')} {progress}%
                    </div>
                    <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                        <div
                            className="h-full bg-sky-500 transition-all"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                </div>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </>
    );
}

/* ------------------------------------------------------------------------------------------
 * Media dialogs.
 * ---------------------------------------------------------------------------------------- */

function VideoDialog({
    content,
    onChange,
}: {
    content: LandingContent;
    onChange: (video: LandingContent['video']) => void;
}) {
    const { t } = useTranslation();
    const uploader = useUploader();
    const { src, poster } = content.video;
    const busy = uploader.progress !== null;

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Video className="size-5" />
                    {t('platform.landing.video_title')}
                </DialogTitle>
                <DialogDescription>
                    {t('platform.landing.video_help')}
                </DialogDescription>
            </DialogHeader>

            <div className="overflow-hidden rounded-lg border bg-black">
                {src ? (
                    <video
                        key={src}
                        src={src}
                        poster={poster ?? undefined}
                        controls
                        muted
                        className="aspect-video w-full"
                    />
                ) : (
                    <div className="flex aspect-video items-center justify-center text-sm text-slate-400">
                        {t('platform.landing.no_video')}
                    </div>
                )}
            </div>

            <UploadStatus progress={uploader.progress} error={uploader.error} />

            <div className="flex flex-wrap gap-2">
                <FilePicker
                    accept="video/mp4,video/webm"
                    label={
                        src
                            ? t('platform.landing.replace_video')
                            : t('platform.landing.upload_video')
                    }
                    variant="default"
                    disabled={busy}
                    test="video-file-input"
                    onFile={(file) =>
                        uploader.run(file, (url) =>
                            onChange({
                                src: url,
                                poster: content.video.poster,
                            }),
                        )
                    }
                />
                <FilePicker
                    accept="image/jpeg,image/png,image/webp"
                    label={t('platform.landing.upload_poster')}
                    disabled={busy}
                    onFile={(file) =>
                        uploader.run(file, (url) =>
                            onChange({ src: content.video.src, poster: url }),
                        )
                    }
                />
                {src && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="text-destructive"
                        onClick={() => onChange({ src: null, poster: null })}
                        data-test="video-remove"
                    >
                        <Trash2 className="size-4" />
                        {t('platform.landing.remove_video')}
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        onChange(structuredClone(DEFAULT_CONTENT.video))
                    }
                >
                    <RotateCcw className="size-4" />
                    {t('platform.landing.restore_original')}
                </Button>
            </div>
        </>
    );
}

function CarouselDialog({
    content,
    lang,
    onChange,
}: {
    content: LandingContent;
    lang: 'en' | 'ar';
    onChange: (
        patch: Partial<Pick<LandingContent, 'slides' | 'showRoadmapSlide'>>,
    ) => void;
}) {
    const { t } = useTranslation();
    const uploader = useUploader();
    const busy = uploader.progress !== null;
    const slides = content.slides;

    const update = (next: LandingSlide[]) => onChange({ slides: next });
    const move = (i: number, delta: number) => {
        const next = [...slides];
        const [item] = next.splice(i, 1);
        next.splice(i + delta, 0, item);
        update(next);
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ImagePlus className="size-5" />
                    {t('platform.landing.slides_title')}
                </DialogTitle>
                <DialogDescription>
                    {t('platform.landing.slides_help')}
                </DialogDescription>
            </DialogHeader>

            <div
                className="max-h-[45vh] space-y-2 overflow-y-auto pe-1"
                data-test="slides-list"
            >
                {slides.map((slide, i) => (
                    <div
                        key={slide.id}
                        className="flex items-center gap-3 rounded-lg border p-2"
                        data-test="slide-row"
                    >
                        <img
                            src={slide[`image_${lang}`]}
                            alt=""
                            className="h-14 w-24 shrink-0 rounded object-cover object-top"
                        />
                        <div className="min-w-0 flex-1 truncate text-sm font-medium">
                            {slide[`title_${lang}`]}
                        </div>
                        <div className="flex shrink-0 items-center gap-1">
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                disabled={i === 0}
                                onClick={() => move(i, -1)}
                            >
                                <ArrowUp className="size-4" />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                disabled={i === slides.length - 1}
                                onClick={() => move(i, 1)}
                            >
                                <ArrowDown className="size-4" />
                            </Button>
                            <FilePicker
                                accept="image/jpeg,image/png,image/webp"
                                label={t('platform.landing.replace')}
                                disabled={busy}
                                onFile={(file) =>
                                    uploader.run(file, (url) =>
                                        update(
                                            slides.map((s, j) =>
                                                j === i
                                                    ? {
                                                          ...s,
                                                          image_en: url,
                                                          image_ar: url,
                                                      }
                                                    : s,
                                            ),
                                        ),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() =>
                                    update(slides.filter((_, j) => j !== i))
                                }
                                data-test="slide-delete"
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    </div>
                ))}
                {slides.length === 0 && (
                    <p className="text-muted-foreground py-6 text-center text-sm">
                        {t('platform.landing.no_slides')}
                    </p>
                )}
            </div>

            <UploadStatus progress={uploader.progress} error={uploader.error} />

            <div className="flex flex-wrap items-center gap-2">
                <FilePicker
                    accept="image/jpeg,image/png,image/webp"
                    label={t('platform.landing.add_slide')}
                    variant="default"
                    disabled={busy}
                    test="slide-file-input"
                    onFile={(file) =>
                        uploader.run(file, (url) =>
                            update([
                                ...slides,
                                {
                                    id: Math.random().toString(36).slice(2, 10),
                                    image_en: url,
                                    image_ar: url,
                                    title_en: 'New screenshot',
                                    title_ar: 'لقطة جديدة',
                                    text_en:
                                        'Click to describe this screenshot.',
                                    text_ar: 'انقر لكتابة وصف لهذه اللقطة.',
                                },
                            ]),
                        )
                    }
                />
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        update(structuredClone(DEFAULT_CONTENT.slides))
                    }
                >
                    <RotateCcw className="size-4" />
                    {t('platform.landing.restore_original')}
                </Button>
            </div>

            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={content.showRoadmapSlide}
                    onCheckedChange={(v) =>
                        onChange({ showRoadmapSlide: v === true })
                    }
                />
                {t('platform.landing.roadmap_card')}
            </label>
        </>
    );
}

/* ------------------------------------------------------------------------------------------
 * The editor.
 * ---------------------------------------------------------------------------------------- */

export default function LandingEditor({
    content: published,
}: {
    content: unknown;
}) {
    const { t } = useTranslation();
    const { locale, updateLocale } = useLocale();
    const lang = locale === 'ar' ? 'ar' : 'en';

    const [content, setContent] = useState<LandingContent>(() =>
        mergeContent(published),
    );
    const [saved, setSaved] = useState(() =>
        JSON.stringify(mergeContent(published)),
    );
    const [media, setMedia] = useState<'video' | 'carousel' | null>(null);
    const [pageInfoOpen, setPageInfoOpen] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const dirty = JSON.stringify(content) !== saved;

    // Don't lose unpublished work by closing the tab or navigating away.
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (dirty) {
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', warn);
        const off = router.on('before', (event) => {
            if (
                dirty &&
                event.detail.visit.method === 'get' &&
                !window.confirm(t('platform.landing.leave_confirm'))
            ) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            off();
        };
    }, [dirty, t]);

    const api: EditorApi = useMemo(
        () => ({
            set: (path, value) => setContent((c) => setAtPath(c, path, value)),
            move: (id, delta) =>
                setContent((c) => {
                    const sections = [...c.sections];
                    const from = sections.findIndex((s) => s.id === id);
                    const to = from + delta;

                    if (to < 0 || to >= sections.length) {
                        return c;
                    }

                    const [item] = sections.splice(from, 1);
                    sections.splice(to, 0, item);

                    return { ...c, sections };
                }),
            moveTo: (id: SectionId, targetId: SectionId) =>
                setContent((c) => {
                    const sections = [...c.sections];
                    const from = sections.findIndex((s) => s.id === id);
                    const [item] = sections.splice(from, 1);
                    const to = sections.findIndex((s) => s.id === targetId);
                    sections.splice(from <= to ? to + 1 : to, 0, item);

                    return { ...c, sections };
                }),
            toggle: (id) =>
                setContent((c) => ({
                    ...c,
                    sections: c.sections.map((s) =>
                        s.id === id ? { ...s, visible: !s.visible } : s,
                    ),
                })),
            openMedia: (kind) => setMedia(kind),
        }),
        [],
    );

    function doPublish() {
        setPublishing(true);
        router.post(
            publish.url(),
            { content },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const fresh = mergeContent(
                        (page.props as { content?: unknown }).content,
                    );
                    setContent(fresh);
                    setSaved(JSON.stringify(fresh));
                },
                onFinish: () => setPublishing(false),
            },
        );
    }

    return (
        <>
            <Head title={t('platform.landing.title')} />

            <div
                className="sticky top-0 z-50 border-b border-white/10 bg-[#0b1a2c]/95 text-slate-200 shadow-lg backdrop-blur"
                dir={locale === 'ar' ? 'rtl' : 'ltr'}
            >
                <div className="flex flex-wrap items-center gap-2 px-4 py-2.5">
                    <Link
                        href={home()}
                        className="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-slate-300 hover:bg-white/10"
                    >
                        <ArrowLeft className="size-4 rtl:rotate-180" />
                        {t('platform.landing.back')}
                    </Link>
                    <div className="me-auto ps-2">
                        <div className="text-sm font-semibold text-white">
                            {t('platform.landing.title')}
                        </div>
                        <div className="text-xs text-slate-400">
                            {t('platform.landing.subtitle')}
                        </div>
                    </div>

                    <span
                        className={cn(
                            'rounded-full px-2.5 py-1 text-xs font-medium',
                            dirty
                                ? 'bg-amber-400/15 text-amber-300'
                                : 'bg-emerald-400/15 text-emerald-300',
                        )}
                        data-test="editor-status"
                    >
                        {dirty
                            ? t('platform.landing.unsaved')
                            : t('platform.landing.all_published')}
                    </span>

                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-slate-200 hover:bg-white/10 hover:text-white"
                        onClick={() =>
                            updateLocale(locale === 'ar' ? 'en' : 'ar')
                        }
                        title={t('platform.landing.editing_language')}
                    >
                        <Languages className="size-4" />
                        {t('platform.landing.editing_in')}{' '}
                        {lang === 'ar' ? 'العربية' : 'English'}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-slate-200 hover:bg-white/10 hover:text-white"
                        onClick={() => setPageInfoOpen(true)}
                    >
                        <FileText className="size-4" />
                        {t('platform.landing.page_info')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-slate-200 hover:bg-white/10 hover:text-white"
                        asChild
                    >
                        <a href="/" target="_blank" rel="noreferrer">
                            <ExternalLink className="size-4" />
                            {t('platform.landing.view_live')}
                        </a>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-slate-200 hover:bg-white/10 hover:text-white"
                        disabled={!dirty}
                        onClick={() => setContent(JSON.parse(saved))}
                        data-test="editor-discard"
                    >
                        <Undo2 className="size-4" />
                        {t('platform.landing.discard')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-slate-200 hover:bg-white/10 hover:text-white"
                        onClick={() =>
                            window.confirm(
                                t('platform.landing.reset_confirm'),
                            ) && setContent(structuredClone(DEFAULT_CONTENT))
                        }
                    >
                        <RotateCcw className="size-4" />
                        {t('platform.landing.reset')}
                    </Button>
                    <Button
                        type="button"
                        className="bg-[#f59e0b] font-semibold text-[#1a1203] shadow-lg shadow-orange-500/25 hover:bg-[#f59e0b] hover:brightness-110"
                        disabled={!dirty || publishing}
                        onClick={doPublish}
                        data-test="editor-publish"
                    >
                        {publishing ? (
                            <LoaderCircle className="size-4 animate-spin" />
                        ) : (
                            <Send className="size-4 rtl:rotate-180" />
                        )}
                        {t('platform.landing.publish')}
                    </Button>
                </div>
                <div className="border-t border-white/5 bg-sky-500/10 px-4 py-1.5 text-xs text-sky-200">
                    {t('platform.landing.hint')}
                </div>
            </div>

            <LandingPage content={content} editor={api} />

            <Dialog
                open={media !== null}
                onOpenChange={(open) => !open && setMedia(null)}
            >
                <DialogContent className="sm:max-w-2xl">
                    {media === 'video' && (
                        <VideoDialog
                            content={content}
                            onChange={(video) =>
                                setContent((c) => ({ ...c, video }))
                            }
                        />
                    )}
                    {media === 'carousel' && (
                        <CarouselDialog
                            content={content}
                            lang={lang}
                            onChange={(patch) =>
                                setContent((c) => ({ ...c, ...patch }))
                            }
                        />
                    )}
                    <DialogFooter>
                        <Button type="button" onClick={() => setMedia(null)}>
                            {t('platform.landing.done')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={pageInfoOpen} onOpenChange={setPageInfoOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {t('platform.landing.page_info')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('platform.landing.page_info_help')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="meta_title">
                            {t('platform.landing.meta_title')}
                        </Label>
                        <Input
                            id="meta_title"
                            value={content.text[lang].metaTitle}
                            onChange={(e) =>
                                setContent((c) =>
                                    setAtPath(
                                        c,
                                        `text.${lang}.metaTitle`,
                                        e.target.value,
                                    ),
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="meta_description">
                            {t('platform.landing.meta_description')}
                        </Label>
                        <Textarea
                            id="meta_description"
                            rows={3}
                            value={content.text[lang].metaDescription}
                            onChange={(e) =>
                                setContent((c) =>
                                    setAtPath(
                                        c,
                                        `text.${lang}.metaDescription`,
                                        e.target.value,
                                    ),
                                )
                            }
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            onClick={() => setPageInfoOpen(false)}
                        >
                            {t('platform.landing.done')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
