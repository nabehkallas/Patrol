// Run with `npm run test:js` (Node's own test runner; Node runs the TypeScript directly).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    priceAmount,
    refillAmount,
} from '../../resources/js/lib/price-amount.ts';

// A store item bought at 750 SYP.
const price = '750.00';
const form = (patch: Partial<Parameters<typeof refillAmount>[0]> = {}) => ({
    quantity: '2',
    currency: 'SYP',
    amount: '',
    amountTyped: false,
    ...patch,
});

test('the amount is filled from the price in the price currency', () => {
    assert.equal(priceAmount('2', price, 'SYP', 'SYP'), '1500.00');
    assert.equal(refillAmount(form(), price, 'SYP'), '1500.00');
});

test('another currency is never filled or converted', () => {
    assert.equal(priceAmount('2', price, 'SYP', 'USD'), null);
    // The 1,500 filled in SYP is cleared, not kept as 1,500 USD.
    assert.equal(
        refillAmount(
            form({ currency: 'USD', amount: '1500.00' }),
            price,
            'SYP',
        ),
        '',
    );
});

test('switching back to the price currency fills it again', () => {
    assert.equal(
        refillAmount(form({ currency: 'SYP', amount: '' }), price, 'SYP'),
        '1500.00',
    );
});

test('a typed amount is never changed', () => {
    const typed = { amount: '45', amountTyped: true };
    assert.equal(
        refillAmount(form({ ...typed, currency: 'USD' }), price, 'SYP'),
        '45',
    );
    assert.equal(
        refillAmount(form({ ...typed, currency: 'SYP' }), price, 'SYP'),
        '45',
    );
    assert.equal(
        refillAmount(form({ ...typed, quantity: '5' }), price, 'SYP'),
        '45',
    );
});

test('no price, no quantity or no price currency fills nothing', () => {
    assert.equal(priceAmount('2', null, 'SYP', 'SYP'), null);
    assert.equal(priceAmount('2', '', 'SYP', 'SYP'), null);
    assert.equal(priceAmount('', price, 'SYP', 'SYP'), null);
    assert.equal(priceAmount('0', price, 'SYP', 'SYP'), null);
    assert.equal(priceAmount('2', price, null, 'SYP'), null);
});
