import { Download } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

export function GeneratePdfButton({ href }: { href: string }) {
    const { t } = useTranslation();

    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted"
        >
            <Download className="size-4" />
            {t('common.generate_pdf')}
        </a>
    );
}
