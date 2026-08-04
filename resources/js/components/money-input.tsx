import { useRef } from 'react';
import type { ChangeEvent } from 'react';
import { Input } from '@/components/ui/input';
import {
    formatNumberWithCommas,
    stripNumberInputFormatting,
} from '@/lib/format';

type MoneyInputProps = Omit<
    React.ComponentProps<typeof Input>,
    'type' | 'value' | 'onChange'
> & {
    value: string;
    onChange: (value: string) => void;
};

/**
 * Text input for money amounts: displays thousand separators as you type
 * (e.g. 1,250,000) while the value passed to `onChange` stays a plain
 * numeric string, so existing parseFloat/validation logic is unaffected.
 */
export function MoneyInput({ value, onChange, ...props }: MoneyInputProps) {
    const inputRef = useRef<HTMLInputElement>(null);

    function handleChange(event: ChangeEvent<HTMLInputElement>) {
        const input = event.target;
        const caret = input.selectionStart ?? input.value.length;
        const digitsBeforeCaret = input.value
            .slice(0, caret)
            .replace(/[^\d.]/g, '').length;

        const raw = stripNumberInputFormatting(input.value);
        const formatted = formatNumberWithCommas(raw);

        onChange(raw);

        requestAnimationFrame(() => {
            const el = inputRef.current;

            if (!el) {
                return;
            }

            let caretPos = digitsBeforeCaret === 0 ? 0 : formatted.length;
            let seen = 0;

            for (
                let i = 0;
                i < formatted.length && digitsBeforeCaret > 0;
                i++
            ) {
                if (/[\d.]/.test(formatted[i])) {
                    seen++;
                }

                if (seen === digitsBeforeCaret) {
                    caretPos = i + 1;
                    break;
                }
            }

            el.setSelectionRange(caretPos, caretPos);
        });
    }

    return (
        <Input
            {...props}
            ref={inputRef}
            type="text"
            inputMode="decimal"
            value={formatNumberWithCommas(value)}
            onChange={handleChange}
        />
    );
}
