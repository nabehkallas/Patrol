import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    cashSource,
    cashSourcePayload,
    conversionRate,
} from '../../resources/js/lib/cash-source.ts';

const rates = { SYP: 139, TRY: 0.5 };
const source = (
    amount: string,
    currency: string,
    balances: Record<string, number>,
    input = { pay_from: '' as const, cash_rate: '' },
    giveBack: Record<string, number> = {},
) => cashSource(amount, currency, input, 'SYP', balances, rates, 0, giveBack);

test("today's rate is quoted as 1 currency = X SYP", () => {
    assert.equal(conversionRate('USD', 'SYP', rates), 139);
    assert.equal(conversionRate('TRY', 'SYP', rates), 278);
    assert.equal(conversionRate('EUR', 'SYP', rates), null);
});

test('the primary currency needs no choice', () => {
    const s = source('8000', 'SYP', { SYP: 5000 });
    assert.equal(s.applies, false);
    assert.equal(s.payFrom, 'own');
});

test('with an empty USD box it defaults to the SYP box at a rate', () => {
    const s = source('50', 'USD', { SYP: 100000 });
    assert.equal(s.enough, false);
    assert.equal(s.payFrom, 'primary');
    assert.equal(s.converted, 6950);
    assert.deepEqual(cashSourcePayload(s), {
        pay_from: 'primary',
        cash_rate: 139,
    });
});

test('with enough in the USD box it defaults to that box', () => {
    const s = source('50', 'USD', { USD: 80 });
    assert.equal(s.payFrom, 'own');
    assert.deepEqual(cashSourcePayload(s), {
        pay_from: 'own',
        cash_rate: null,
    });
});

test('a typed rate is used, and the choice stays as chosen', () => {
    const s = source(
        '50',
        'USD',
        { USD: 80 },
        { pay_from: 'primary', cash_rate: '140' },
    );
    assert.equal(s.payFrom, 'primary');
    assert.equal(s.converted, 7000);
});

test("an edited record's own amount counts as available", () => {
    assert.equal(
        source('50', 'USD', { USD: 10 }, undefined, { USD: 45 }).enough,
        true,
    );
});
