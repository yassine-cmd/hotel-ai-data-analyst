import { describe, expect, it } from 'vitest';
import { formatMoney } from './money';

describe('formatMoney', () => {
  it('keeps the backend decimal digits instead of forcing cents', () => {
    expect(formatMoney(0.007714)).toBe('$0.007714');
    expect(formatMoney(0.0077)).toBe('$0.0077');
    expect(formatMoney(0.00012)).toBe('$0.00012');
  });

  it('formats whole and half amounts without trailing zeros', () => {
    expect(formatMoney(100)).toBe('$100');
    expect(formatMoney(100.5)).toBe('$100.5');
    expect(formatMoney(1234567.89)).toBe('$1,234,567.89');
  });

  it('coerces non-finite or missing values to zero', () => {
    expect(formatMoney(null)).toBe('$0');
    expect(formatMoney(undefined)).toBe('$0');
    expect(formatMoney('nope')).toBe('$0');
    expect(formatMoney(NaN)).toBe('$0');
  });
});