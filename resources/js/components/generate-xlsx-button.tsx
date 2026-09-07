import { FileSpreadsheet } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

export function GenerateXlsxButton({
    href,
    label,
}: {
    href: string;
    /** Overrides the default "Download Excel" text — e.g. to name which fuel type/report a
     * button downloads, when several sit side by side. */
    label?: string;
}) {
    const { t } = useTranslation();

    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted"
        >
            <FileSpreadsheet className="size-4" />
            {label ?? t('common.generate_xlsx')}
        </a>
    );
}
