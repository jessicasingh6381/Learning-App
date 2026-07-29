import { describe, expect, it, vi } from 'vitest';
import { formatDateOnly } from './dateOnly';

describe('formatDateOnly', () => {
    it('formats an ISO calendar date as a readable date', () => {
        expect(formatDateOnly('2026-08-12')).toBe('Aug 12, 2026');
        expect(formatDateOnly('2027-05-27')).toBe('May 27, 2027');
    });

    it('does not use JavaScript Date or apply a timezone conversion', () => {
        const dateConstructor = vi
            .spyOn(globalThis, 'Date')
            .mockImplementation(() => {
                throw new Error('Date construction would risk timezone shifting');
            });

        expect(formatDateOnly('2026-01-01')).toBe('Jan 1, 2026');
        expect(formatDateOnly('2026-12-31')).toBe('Dec 31, 2026');

        dateConstructor.mockRestore();
    });

    it('leaves unexpected values unchanged', () => {
        expect(formatDateOnly('2026-08-12T00:00:00.000000Z')).toBe(
            '2026-08-12T00:00:00.000000Z',
        );
    });
});
