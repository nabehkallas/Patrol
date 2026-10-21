// Run with `npm run test:js` (Node's own test runner; Node runs the TypeScript directly).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readingShownTime } from '../../resources/js/lib/reading-time.ts';

test('a reading saved on its own date shows the time it was saved', () => {
    assert.deepEqual(
        readingShownTime(
            '2026-10-20T00:00:00.000000+03:00',
            '2026-10-20T22:15:00.000000+03:00',
            '2026-10-20',
        ),
        { at: '2026-10-20T22:15:00.000000+03:00', entered: null },
    );
});

test('a reading saved on a later day shows its date and when it was entered', () => {
    assert.deepEqual(
        readingShownTime(
            '2026-10-21T00:00:00.000000+03:00',
            '2026-10-22T02:30:00.000000+03:00',
            '2026-10-22',
            'مسائية',
        ),
        {
            at: '2026-10-21',
            entered: {
                at: '2026-10-22T02:30:00.000000+03:00',
                shift: 'مسائية',
            },
        },
    );
});

test('a bare date works too, and no shift is named unless given', () => {
    assert.deepEqual(
        readingShownTime(
            '2026-10-21',
            '2026-10-23T09:00:00+03:00',
            '2026-10-23',
        ),
        {
            at: '2026-10-21',
            entered: { at: '2026-10-23T09:00:00+03:00', shift: null },
        },
    );
});
