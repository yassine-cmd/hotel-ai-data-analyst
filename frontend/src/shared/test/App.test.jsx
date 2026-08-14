// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import App from '../../App';

afterEach(cleanup);

describe('guest routing', () => {
  it('redirects the root route to sign in', async () => {
    render(<MemoryRouter initialEntries={['/']}><App /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.getByPlaceholderText('votre-identifiant')).toBeTruthy();
    });
  });

  it('shows sign in page', async () => {
    render(<MemoryRouter initialEntries={['/signin']}><App /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.getByPlaceholderText('votre-identifiant')).toBeTruthy();
    });
  });
});
