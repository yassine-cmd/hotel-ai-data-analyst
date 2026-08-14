// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChatPage from './ChatPage';

const mockState = vi.hoisted(() => ({ messages: [] }));

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  useOutletContext: () => ({
    session: { load: () => Promise.resolve(), sessionId: 's1', loadingHistory: false },
  }),
}));

vi.mock('../hooks/useChat', () => ({
  useChat: () => ({ messages: mockState.messages, isStreaming: false, send: vi.fn(), stop: vi.fn(), maxSteps: null }),
}));

afterEach(cleanup);

const pane = () => document.querySelector('[class*="overflow-hidden"]');
const panel = () => document.querySelector('#agent-loop-panel');

const conversation = () => [
  { id: 'm1', role: 'user', content: 'first query' },
  { id: 'm2', role: 'assistant', steps: 2, blocks: [{ id: 't1', type: 'thinking', content: 'OLD THOUGHT', n: 1 }] },
  { id: 'm3', role: 'user', content: 'second query' },
  { id: 'm4', role: 'assistant', steps: 3, blocks: [{ id: 't2', type: 'thinking', content: 'NEW THOUGHT', n: 1 }] },
];

describe('ChatPage drawer resize', () => {
  it('follows the pointer imperatively during a drag', () => {
    render(<ChatPage />);
    const handle = screen.getByRole('separator', { name: 'Redimensionner le panneau' });
    expect(handle).toHaveAttribute('aria-valuenow', '480');
    fireEvent(handle, new MouseEvent('pointerdown', { bubbles: true, cancelable: true, button: 0, clientX: 600 }));
    fireEvent(handle, new MouseEvent('pointermove', { bubbles: true, cancelable: true, clientX: 500 }));
    expect(pane().style.getPropertyValue('--drawer-width')).toBe('580px');
  });

  it('commits the last dragged width on release instead of an undefined value', () => {
    render(<ChatPage />);
    const handle = screen.getByRole('separator', { name: 'Redimensionner le panneau' });
    fireEvent(handle, new MouseEvent('pointerdown', { bubbles: true, cancelable: true, button: 0, clientX: 600 }));
    fireEvent(handle, new MouseEvent('pointermove', { bubbles: true, cancelable: true, clientX: 500 }));
    fireEvent(handle, new MouseEvent('pointerup', { bubbles: true, cancelable: true }));
    expect(handle).toHaveAttribute('aria-valuenow', '580');
    expect(pane().style.getPropertyValue('--drawer-width')).toBe('580px');
  });
});

describe('ChatPage drawer per-message thinking', () => {
  it('shows the latest message thinking by default', () => {
    mockState.messages = conversation();
    render(<ChatPage />);
    fireEvent.click(screen.getByText('A réfléchi pendant 3 étapes'));
    expect(screen.getByText('NEW THOUGHT')).toBeInTheDocument();
    expect(screen.queryByText('OLD THOUGHT')).not.toBeInTheDocument();
  });

  it('reveals the specific query thinking when its strip is clicked, not the latest', () => {
    mockState.messages = conversation();
    render(<ChatPage />);
    fireEvent.click(screen.getByText('A réfléchi pendant 2 étapes'));
    expect(screen.getByText('OLD THOUGHT')).toBeInTheDocument();
    expect(screen.queryByText('NEW THOUGHT')).not.toBeInTheDocument();
  });

  it('closes the panel when the same strip is clicked again', () => {
    mockState.messages = conversation();
    render(<ChatPage />);
    fireEvent.click(screen.getByText('A réfléchi pendant 2 étapes'));
    expect(panel()).not.toHaveClass('translate-x-full');
    fireEvent.click(screen.getByText('A réfléchi pendant 2 étapes'));
    expect(panel()).toHaveClass('translate-x-full');
  });

  it('switches to another message strip without closing when already open', () => {
    mockState.messages = conversation();
    render(<ChatPage />);
    fireEvent.click(screen.getByText('A réfléchi pendant 2 étapes'));
    expect(screen.getByText('OLD THOUGHT')).toBeInTheDocument();
    fireEvent.click(screen.getByText('A réfléchi pendant 3 étapes'));
    expect(screen.getByText('NEW THOUGHT')).toBeInTheDocument();
    expect(screen.queryByText('OLD THOUGHT')).not.toBeInTheDocument();
    expect(panel()).not.toHaveClass('translate-x-full');
  });
});
