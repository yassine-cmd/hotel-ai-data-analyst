// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent, waitFor } from '@testing-library/react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import Markdown from './Markdown';

afterEach(() => { cleanup(); vi.clearAllMocks(); });

beforeAll(() => {
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
    configurable: true,
  });
});

describe('Markdown code block copy button', () => {
  it('renders Copy button with aria-label', () => {
    render(<Markdown>{'```js\nconsole.log("hi")\n```'}</Markdown>);
    const btn = screen.getByRole('button', { name: 'Copier le code' });
    expect(btn).toBeInTheDocument();
  });

  it('shows Copied feedback briefly on click', async () => {
    render(<Markdown>{'```js\nconsole.log("hi")\n```'}</Markdown>);
    const btn = screen.getByRole('button', { name: 'Copier le code' });
    fireEvent.click(btn);
    await waitFor(() => {
      expect(screen.getByText('Copié')).toBeInTheDocument();
    });
  });

  it('calls clipboard.writeText on click', () => {
    render(<Markdown>{'```js\nconsole.log("hi")\n```'}</Markdown>);
    fireEvent.click(screen.getByRole('button', { name: 'Copier le code' }));
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('console.log("hi")\n');
  });

  it('shows language label', () => {
    render(<Markdown>{'```js\nconst x = 1\n```'}</Markdown>);
    expect(screen.getByText('js')).toBeInTheDocument();
  });

  it('renders a language-less fenced block as a code block, not inline', () => {
    render(<Markdown>{'```\ndef a():\n  return 1\n```'}</Markdown>);
    expect(document.querySelector('.md-code-block-wrapper')).toBeInTheDocument();
    expect(document.querySelector('.md-code-lang')).toHaveTextContent('code');
    expect(document.querySelector('.md-inline-code')).not.toBeInTheDocument();
  });

  it('wraps output in markdown-body with optional className', () => {
    const { rerender } = render(<Markdown>{'hello'}</Markdown>);
    expect(document.querySelector('.markdown-body')).toBeInTheDocument();
    rerender(<Markdown className="tight">{'hello'}</Markdown>);
    const wrapper = document.querySelector('.markdown-body');
    expect(wrapper).toHaveClass('tight');
  });

  it('renders external links with a new-tab target', () => {
    render(<Markdown>{'[docs](https://example.com)'}</Markdown>);
    const link = screen.getByRole('link', { name: /docs/ });
    expect(link).toHaveAttribute('target', '_blank');
    expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    expect(link.querySelector('.md-ext-link')).toBeInTheDocument();
  });

  it('renders GFM task list checkboxes', () => {
    render(<Markdown>{'- [x] done\n- [ ] todo'}</Markdown>);
    const boxes = document.querySelectorAll('.markdown-body input[type="checkbox"]');
    expect(boxes.length).toBe(2);
    expect(boxes[0]).toBeChecked();
    expect(boxes[1]).not.toBeChecked();
  });

  it('renders inline math', () => {
    render(<Markdown>{'The value is $x^2$.'}</Markdown>);
    expect(document.querySelector('.katex')).toBeInTheDocument();
  });

  it('renders display math', () => {
    render(<Markdown>{'$$\n\\sum_{k=1}^{n} k = \\frac{n(n+1)}{2}\n$$'}</Markdown>);
    expect(document.querySelector('.katex-display')).toBeInTheDocument();
  });

  it('does not turn ordinary parentheses or links into math', () => {
    render(<Markdown>{'Use [docs](https://example.com) and (just text)'}</Markdown>);
    expect(document.querySelector('.katex')).not.toBeInTheDocument();
    expect(screen.getByText('docs')).toBeInTheDocument();
    expect(document.querySelector('a')).toHaveAttribute('href', 'https://example.com');
  });
});
