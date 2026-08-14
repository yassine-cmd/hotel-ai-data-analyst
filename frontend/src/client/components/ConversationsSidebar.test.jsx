// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ConversationsSidebar from './ConversationsSidebar';

afterEach(cleanup);

function setup(overrides = {}) {
  const select = vi.fn();
  const onNew = vi.fn();
  const onClose = vi.fn();
  const rename = vi.fn().mockResolvedValue();
  const remove = vi.fn().mockResolvedValue();
  const session = {
    sessionId: 's2',
    sessions: [
      { session_id: 's1', name: 'Chat one', created_at: new Date(Date.now() - 1000).toISOString() },
      { session_id: 's2', name: 'Chat two', created_at: new Date(Date.now() - 60000).toISOString() },
    ],
    select,
    rename,
    remove,
    ...overrides.session,
  };
  render(<ConversationsSidebar session={session} onNew={onNew} open onClose={onClose} />);
  return { select, onNew, onClose, rename, remove };
}

describe('ConversationsSidebar', () => {
  it('renders sessions and shows kebab buttons', () => {
    setup();
    expect(screen.getByText('Chat one')).toBeInTheDocument();
    expect(screen.getByText('Chat two')).toBeInTheDocument();
    expect(screen.getAllByLabelText('Options de la conversation')).toHaveLength(2);
  });

  it('marks the active session with .active', () => {
    setup();
    const cards = document.querySelectorAll('.session-item');
    expect(cards[1]).toHaveClass('active');
  });

  it('clicking a session selects it and closes', () => {
    const { select, onClose } = setup();
    const cards = document.querySelectorAll('.session-item');
    fireEvent.click(cards[0]);
    expect(select).toHaveBeenCalledWith('s1');
    expect(onClose).toHaveBeenCalled();
  });

  it('kebab click opens the menu with aria-expanded toggle', () => {
    setup();
    const dots = screen.getAllByLabelText('Options de la conversation')[0];
    fireEvent.click(dots);
    expect(screen.getByText('Renommer')).toBeInTheDocument();
    expect(screen.getByText('Supprimer')).toBeInTheDocument();
    expect(dots).toHaveAttribute('aria-expanded', 'true');
    const menu = screen.getByRole('menu');
    expect(menu).toHaveAttribute('id', 'menu-s1');
    expect(dots).toHaveAttribute('aria-controls', 'menu-s1');
  });

  it('Escape closes the menu', () => {
    setup();
    fireEvent.click(screen.getAllByLabelText('Options de la conversation')[0]);
    expect(screen.getByRole('menu')).toBeInTheDocument();
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('clicking outside closes the menu', () => {
    setup();
    fireEvent.click(screen.getAllByLabelText('Options de la conversation')[0]);
    fireEvent.mouseDown(document.body);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('start rename shows input pre-filled and commits on Enter', () => {
    const { rename } = setup();
    fireEvent.click(screen.getAllByLabelText('Options de la conversation')[0]);
    fireEvent.click(screen.getByText('Renommer'));
    const input = screen.getByDisplayValue('Chat one');
    expect(input).toHaveClass('input-sm');
    fireEvent.change(input, { target: { value: 'Renamed chat' } });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(rename).toHaveBeenCalledWith('s1', 'Renamed chat');
  });

  it('Escape cancels rename', () => {
    setup();
    fireEvent.click(screen.getAllByLabelText('Options de la conversation')[0]);
    fireEvent.click(screen.getByText('Renommer'));
    const input = screen.getByDisplayValue('Chat one');
    fireEvent.keyDown(input, { key: 'Escape' });
    expect(screen.queryByDisplayValue('Chat one')).not.toBeInTheDocument();
    expect(screen.getByText('Chat one')).toBeInTheDocument();
  });

  it('blurring an emptied rename input cancels the rename instead of getting stuck', () => {
    const { rename } = setup();
    fireEvent.click(screen.getAllByLabelText('Options de la conversation')[0]);
    fireEvent.click(screen.getByText('Renommer'));
    const input = screen.getByDisplayValue('Chat one');
    fireEvent.change(input, { target: { value: '   ' } });
    fireEvent.blur(input);
    expect(rename).not.toHaveBeenCalled();
    expect(screen.queryByDisplayValue('Chat one')).not.toBeInTheDocument();
    expect(screen.getByText('Chat one')).toBeInTheDocument();
  });

  it('delete flow: confirm then delete removes session', async () => {
    const { remove } = setup();
    fireEvent.click(screen.getAllByLabelText('Options de la conversation')[0]);
    fireEvent.click(screen.getByText('Supprimer'));
    expect(screen.getByText('Supprimer cette session ?')).toBeInTheDocument();
    const menu = screen.getByRole('menu');
    fireEvent.click(within(menu).getByText('Supprimer'));
    await new Promise((r) => setTimeout(r, 220));
    expect(remove).toHaveBeenCalledWith('s1');
    expect(screen.queryByText('Supprimer cette session ?')).not.toBeInTheDocument();
  });

  it('delete flow: cancel restores the menu', () => {
    setup();
    fireEvent.click(screen.getAllByLabelText('Options de la conversation')[0]);
    fireEvent.click(screen.getByText('Supprimer'));
    const menu = screen.getByRole('menu');
    fireEvent.click(within(menu).getByText('Annuler'));
    expect(screen.queryByText('Supprimer cette session ?')).not.toBeInTheDocument();
    expect(screen.getByText('Renommer')).toBeInTheDocument();
    expect(screen.getByText('Supprimer')).toBeInTheDocument();
  });

  it('New Conversation button fires onNew', () => {
    const { onNew } = setup();
    fireEvent.click(screen.getByText('Nouvelle conversation'));
    expect(onNew).toHaveBeenCalled();
  });

  it('search filters sessions and shows No matches', () => {
    setup();
    fireEvent.change(screen.getByPlaceholderText('Rechercher des conversations…'), { target: { value: 'one' } });
    expect(screen.getByText('Chat one')).toBeInTheDocument();
    expect(screen.queryByText('Chat two')).not.toBeInTheDocument();
    fireEvent.change(screen.getByPlaceholderText('Rechercher des conversations…'), { target: { value: 'zzz' } });
    expect(screen.getByText('Aucun résultat')).toBeInTheDocument();
  });

  it('shows No conversations yet when empty', () => {
    setup({ session: { sessions: [], sessionId: null } });
    expect(screen.getByText('Aucune conversation')).toBeInTheDocument();
  });
});
