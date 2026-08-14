// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { smartTitle, sessionLabel } from './smartTitle';

describe('smartTitle', () => {
  it('returns short messages unchanged', () => {
    expect(smartTitle('Show reservations')).toBe('Show reservations');
  });

  it('truncates at a word boundary with an ellipsis', () => {
    const long = 'Show reservations by month for the current year and compare against last year';
    const title = smartTitle(long, 40);
    expect(title.length).toBeLessThanOrEqual(41);
    expect(title.endsWith('…')).toBe(true);
    const prefix = title.slice(0, -1);
    expect(long.startsWith(prefix)).toBe(true);
    const lastWord = prefix.split(/\s+/).pop();
    expect(lastWord.length).toBeGreaterThan(1);
  });

  it('hard-cuts a single unbroken word', () => {
    const title = smartTitle('a'.repeat(100), 20);
    expect(title).toBe('a'.repeat(20) + '…');
  });

  it('collapses whitespace and trims', () => {
    expect(smartTitle('  Show   revenue\n  by month ')).toBe('Show revenue by month');
  });

  it('returns exactly the message when at the limit', () => {
    const msg = 'x'.repeat(60);
    expect(smartTitle(msg, 60)).toBe(msg);
  });

  it('strips trailing punctuation before the ellipsis', () => {
    const long = 'Show reservations by month for the current year, and';
    const title = smartTitle(long, 35);
    expect(title.endsWith('…')).toBe(true);
    expect(title).not.toMatch(/[,;:]…$/);
  });

  it('returns empty for blank input', () => {
    expect(smartTitle('   ')).toBe('');
    expect(smartTitle('')).toBe('');
  });
});

describe('sessionLabel', () => {
  it('uses the name when present', () => {
    expect(sessionLabel({ name: 'Q3 report', created_at: '2026-08-03T10:00:00Z' })).toBe('Q3 report');
  });

  it('falls back to a date label for unnamed sessions', () => {
    const label = sessionLabel({ name: '', created_at: '2026-08-03T10:00:00Z' });
    expect(label).toMatch(/^Conversation · /);
  });

  it('falls back to a plain label when there is no date', () => {
    expect(sessionLabel({ name: '' })).toBe('Conversation');
    expect(sessionLabel(null)).toBe('Conversation');
  });
});
