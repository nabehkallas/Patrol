import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The header bar above every table and log. First row: the section title with its date range
 * beside it at the start (the right in Arabic), export buttons pushed to the opposite end.
 * Optional second row: the table's filters (search, status, …). The order follows the page
 * direction, so nothing here is direction-specific.
 */
export function SectionToolbar({
    title,
    description,
    children,
    actions,
    filters,
    className,
}: {
    title?: ReactNode;
    description?: ReactNode;
    /** Shown right beside the title, typically the date range picker. */
    children?: ReactNode;
    /** Export (PDF / Excel) buttons. */
    actions?: ReactNode;
    /** Filters for the table, on their own row below. */
    filters?: ReactNode;
    className?: string;
}) {
    return (
        <div
            data-slot="section-toolbar"
            className={cn(
                'bg-card shadow-xs space-y-3 rounded-xl border px-4 py-3',
                className,
            )}
        >
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex min-w-0 flex-wrap items-center gap-3">
                    {(title || description) && (
                        <div className="min-w-0">
                            {title && (
                                <h3 className="font-semibold leading-tight">
                                    {title}
                                </h3>
                            )}
                            {description && (
                                <p className="text-muted-foreground text-sm">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {children}
                </div>

                {actions && (
                    <div
                        data-slot="section-toolbar-actions"
                        // ms-auto keeps the exports at the far end even after the row wraps.
                        className="ms-auto flex flex-wrap items-center gap-2"
                    >
                        {actions}
                    </div>
                )}
            </div>

            {filters && (
                <div
                    data-slot="section-toolbar-filters"
                    className="flex flex-wrap items-center gap-3 border-t pt-3"
                >
                    {filters}
                </div>
            )}
        </div>
    );
}
