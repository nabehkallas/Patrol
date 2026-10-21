// Run with `npm run test:js` (Node's own test runner; Node runs the TypeScript directly).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    formatDate,
    formatDateTime,
    setDisplayLocale,
    setStationTimeZone,
    todayInStation,
} from '../../resources/js/lib/format.ts';

setStationTimeZone('Asia/Damascus');
setDisplayLocale('en-US');
const thisYear = Number(todayInStation().slice(0, 4));

test('the year is left out only when asked and only for the current year', () => {
    assert.equal(formatDate(`${thisYear}-10-21`, true), 'Oct 21');
    assert.equal(
        formatDate(`${thisYear - 1}-10-21`, true),
        `Oct 21, ${thisYear - 1}`,
    );
    assert.equal(formatDate(`${thisYear}-10-21`), `Oct 21, ${thisYear}`);
});

test('a timestamp keeps its year in another year, in station time', () => {
    assert.equal(
        formatDateTime(`${thisYear}-10-20T22:15:00+03:00`, true),
        'Oct 20, 22:15',
    );
    assert.equal(
        formatDateTime(`${thisYear - 1}-10-20T22:15:00+03:00`, true),
        `Oct 20, ${thisYear - 1}, 22:15`,
    );
    // Still 31 Dec in UTC, but already 1 Jan of this year in Damascus: no year.
    assert.equal(
        formatDateTime(`${thisYear - 1}-12-31T22:30:00Z`, true),
        'Jan 1, 01:30',
    );
});
