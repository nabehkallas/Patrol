import type { ReactNode } from 'react';

/**
 * The top of every page: the title and its description on the start side (right in Arabic), and
 * the page's main action buttons, passed as children, on the end side. The actions wrap under
 * the title on narrow screens.
 */
export function PageHeader({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <header className="min-w-0 space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="text-muted-foreground text-sm">
                        {description}
                    </p>
                )}
            </header>
            {children && (
                <div className="flex flex-wrap items-center gap-2">
                    {children}
                </div>
            )}
        </div>
    );
}
