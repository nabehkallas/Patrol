import { useState } from 'react';
import { formatNonZeroBreakdown } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { CurrencyBreakdown } from '@/types';

export type DebtorCard = {
    id: number;
    name: string;
    outstanding: CurrencyBreakdown;
    count: number;
};

const INITIALLY_SHOWN = 8;

/**
 * "Who owes us": one card per debtor with an unpaid balance. Clicking a card filters the debts
 * table to that debtor; clicking the selected card again clears the filter.
 */
export function DebtorOverview({
    cards,
    selectedId,
    onSelect,
}: {
    cards: DebtorCard[];
    selectedId?: string;
    onSelect: (debtorId: string | undefined) => void;
}) {
    const { t } = useTranslation();
    const [showAll, setShowAll] = useState(false);

    if (cards.length === 0) {
        return null;
    }

    // Keep the selected debtor visible even when the list is collapsed.
    const visible = showAll
        ? cards
        : cards.filter(
              (card, index) =>
                  index < INITIALLY_SHOWN || String(card.id) === selectedId,
          );

    return (
        <section className="space-y-3" data-test="debtor-overview">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h3 className="text-base font-semibold">
                        {t('debts.overview_title')}
                    </h3>
                    <p className="text-muted-foreground text-sm">
                        {t('debts.overview_hint')}
                    </p>
                </div>
                {selectedId && (
                    <button
                        type="button"
                        onClick={() => onSelect(undefined)}
                        className="text-sm underline"
                    >
                        {t('debts.overview_clear')}
                    </button>
                )}
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {visible.map((card) => {
                    const selected = String(card.id) === selectedId;

                    return (
                        <button
                            key={card.id}
                            type="button"
                            aria-pressed={selected}
                            onClick={() =>
                                onSelect(selected ? undefined : String(card.id))
                            }
                            data-test={`debtor-card-${card.id}`}
                            className={cn(
                                'bg-card hover:border-primary/60 shadow-xs rounded-xl border border-s-4 border-s-emerald-500 p-4 text-start transition-colors',
                                selected &&
                                    'border-primary ring-primary/40 border-s-primary ring-2',
                            )}
                        >
                            <div className="truncate font-medium">
                                {card.name}
                            </div>
                            <div className="mt-1 text-lg font-bold tabular-nums">
                                {formatNonZeroBreakdown(card.outstanding)}
                            </div>
                            <div className="text-muted-foreground mt-1 text-xs">
                                {t('debts.overview_count').replace(
                                    ':count',
                                    String(card.count),
                                )}
                            </div>
                        </button>
                    );
                })}
            </div>

            {cards.length > INITIALLY_SHOWN && (
                <button
                    type="button"
                    onClick={() => setShowAll((value) => !value)}
                    className="text-sm underline"
                >
                    {showAll
                        ? t('debts.overview_show_less')
                        : t('debts.overview_show_all').replace(
                              ':count',
                              String(cards.length),
                          )}
                </button>
            )}
        </section>
    );
}
