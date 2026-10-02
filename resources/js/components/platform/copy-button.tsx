import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from '@/lib/i18n';

/** Small icon button that copies an email address, with a brief "copied" check. */
export function CopyButton({ value }: { value: string }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={() => {
                void navigator.clipboard?.writeText(value);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }}
            className="text-muted-foreground hover:bg-muted hover:text-foreground inline-flex size-7 shrink-0 items-center justify-center rounded-md transition-colors"
            aria-label={t('platform.copy_email')}
            title={copied ? t('platform.copied') : t('platform.copy_email')}
        >
            {copied ? (
                <Check className="size-3.5 text-emerald-500" />
            ) : (
                <Copy className="size-3.5" />
            )}
        </button>
    );
}
