/**
 * Asking before the browser's back/forward buttons leave a page with unsaved work. The router
 * moves pages on `popstate` and that can't be cancelled from a page: listeners on window run
 * in the order they were added, so this one is installed before the app boots (app.tsx) and
 * runs first. A page sets a guard while it has unsaved work; the guard returns whether to
 * leave. Staying steps forward again, and that step is kept from the router too.
 */
let guard: (() => boolean) | null = null;
let returning = false;

export function installLeaveGuard(): void {
    window.addEventListener('popstate', (event) => {
        if (returning) {
            returning = false;
            event.stopImmediatePropagation();

            return;
        }

        if (guard && !guard()) {
            event.stopImmediatePropagation();
            returning = true;
            window.history.go(1);
        }
    });
}

/** Sets the guard; returns the function that removes it. */
export function setLeaveGuard(check: () => boolean): () => void {
    guard = check;

    return () => {
        if (guard === check) {
            guard = null;
        }
    };
}
