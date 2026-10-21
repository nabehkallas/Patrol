import { StickyNote } from 'lucide-react';
import { useState } from 'react';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useTranslation } from '@/lib/i18n';

/**
 * A row's note as a small icon in place of a notes column: the note opens on hover, and a
 * click keeps it open (touch screens have no hover) until a click elsewhere or Escape.
 */
export function NoteIcon({ note }: { note: string }) {
    const { t } = useTranslation();
    const [hovered, setHovered] = useState(false);
    const [pinned, setPinned] = useState(false);

    return (
        <Popover
            open={hovered || pinned}
            onOpenChange={(open) => {
                if (!open) {
                    setHovered(false);
                    setPinned(false);
                }
            }}
        >
            <PopoverAnchor asChild>
                <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground flex rounded-sm"
                    aria-label={t('common.notes')}
                    onMouseEnter={() => setHovered(true)}
                    onMouseLeave={() => setHovered(false)}
                    onClick={() => setPinned((value) => !value)}
                >
                    <StickyNote className="size-4" />
                </button>
            </PopoverAnchor>
            <PopoverContent
                className="w-auto max-w-72 whitespace-pre-wrap break-words p-3 text-sm"
                onOpenAutoFocus={(event) => event.preventDefault()}
            >
                {note}
            </PopoverContent>
        </Popover>
    );
}
