import { useEffect } from 'react';

/** Copies each column's header text onto its cells as `data-label`, for one `.table-stack` table. */
function labelTable(table: HTMLTableElement): void {
    const headers = Array.from(table.tHead?.rows[0]?.cells ?? []).map(
        (cell) => cell.textContent?.trim() ?? '',
    );

    if (headers.length === 0) {
        return;
    }

    for (const body of Array.from(table.tBodies)) {
        for (const row of Array.from(body.rows)) {
            let column = 0;

            for (const cell of Array.from(row.cells)) {
                const label = cell.colSpan > 1 ? null : (headers[column] ?? '');

                if (label === null) {
                    cell.removeAttribute('data-label');
                } else if (cell.dataset.label !== label) {
                    cell.dataset.label = label;
                }

                column += cell.colSpan;
            }
        }
    }
}

/**
 * Keeps the labels of every `.table-stack` table on the page in step as pages and rows change,
 * so the phone layout (see app.css) can show "label  value" lines without each table having
 * to repeat its headers on every cell.
 */
export function useStackedTables(): void {
    useEffect(() => {
        let frame = 0;

        const labelAll = () => {
            frame = 0;
            document
                .querySelectorAll<HTMLTableElement>('.table-stack table')
                .forEach(labelTable);
        };

        const schedule = () => {
            if (frame === 0) {
                frame = requestAnimationFrame(labelAll);
            }
        };

        labelAll();

        // Our own data-label writes are attribute changes, which this doesn't watch, so it
        // can't loop on itself.
        const observer = new MutationObserver(schedule);
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
        });

        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
        };
    }, []);
}
