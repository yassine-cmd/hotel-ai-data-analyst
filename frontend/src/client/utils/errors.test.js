import { describe, expect, it } from 'vitest';
import { toUserError } from './errors';

describe('toUserError', () => {
  it('maps AUTH_EXPIRED to a friendly message and flags the sign-in redirect', () => {
    const e = toUserError({ code: 'AUTH_EXPIRED' });
    expect(e.message).toContain('session a expiré');
    expect(e.retryable).toBe(false);
    expect(e.redirectToSignin).toBe(true);
  });

  it('maps 429 status to quota copy without leaking the raw Laravel string', () => {
    const e = toUserError({ status: 429, message: 'Quota exceeded for policy...' });
    expect(e.message).toContain('budget mensuel');
    expect(e.retryable).toBe(false);
  });

  it('keeps the (already safe) backend message for an unknown code', () => {
    const e = toUserError({ code: 'STREAM_ERROR', message: 'Something went wrong while processing your question. Please try again.' });
    expect(e.message).toBe('Something went wrong while processing your question. Please try again.');
    expect(e.retryable).toBe(true);
  });

  it('falls back to a neutral message when nothing usable is provided', () => {
    const e = toUserError({});
    expect(e.message).toBe('Une erreur est survenue. Veuillez réessayer.');
    expect(e.retryable).toBe(true);
    expect(e.code).toBe('UNKNOWN');
  });
});
