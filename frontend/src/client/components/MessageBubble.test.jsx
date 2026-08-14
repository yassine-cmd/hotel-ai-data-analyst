// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import MessageBubble from './MessageBubble';

afterEach(cleanup);

describe('MessageBubble', () => {
  it('shows the copy button always on touch screens (md:hidden by default)', () => {
    render(<MessageBubble message={{ role: 'assistant' }} copyText="copy me">hi</MessageBubble>);
    const btn = screen.getByLabelText('Copier');
    const wrapper = btn.closest('div');
    expect(wrapper).toHaveClass('opacity-100');
    expect(wrapper).toHaveClass('md:opacity-0');
    expect(wrapper).toHaveClass('md:group-hover:opacity-100');
  });

  it('does not render a copy button without copyText', () => {
    render(<MessageBubble message={{ role: 'assistant' }}>hi</MessageBubble>);
    expect(screen.queryByLabelText('Copier')).not.toBeInTheDocument();
  });

  it('user messages render on the right without a copy button', () => {
    render(<MessageBubble message={{ role: 'user' }} copyText="nope">hi</MessageBubble>);
    expect(screen.getByText('hi')).toBeInTheDocument();
    expect(screen.queryByLabelText('Copier')).not.toBeInTheDocument();
  });
});
