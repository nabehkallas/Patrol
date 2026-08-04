import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { PaginationLink } from '@/types';

export default function PaginationLinks({ links }: { links: PaginationLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center gap-1">
            {links.map((link, index) => (
                <Link
                    key={index}
                    href={link.url ?? '#'}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                    preserveScroll
                    className={cn(
                        'min-w-9 rounded-md px-3 py-1.5 text-center text-sm',
                        link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent',
                        !link.url && 'pointer-events-none opacity-40',
                    )}
                />
            ))}
        </nav>
    );
}
