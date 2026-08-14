// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChatInput from './ChatInput';

afterEach(cleanup);

describe('ChatInput', () => {
  it('textarea exposes an accessible name', () => {
    render(<ChatInput onSend={() => {}} isStreaming={false} onStop={() => {}} />);
    expect(screen.getByLabelText('Message')).toBeInTheDocument();
  });

  it('idle + empty renders disabled send button', () => {
    const onSend = vi.fn();
    render(<ChatInput onSend={onSend} isStreaming={false} onStop={() => {}} />);
    const btn = screen.getByRole('button');
    expect(btn).toBeDisabled();
    expect(btn).toHaveClass('btn-send');
    expect(btn).toHaveClass('btn-send');
  });

  it('typing text enables button', () => {
    const onSend = vi.fn();
    render(<ChatInput onSend={onSend} isStreaming={false} onStop={() => {}} />);
    const textarea = screen.getByPlaceholderText('Posez une question sur vos données...');
    fireEvent.change(textarea, { target: { value: 'hello' } });
    const btn = screen.getByRole('button');
    expect(btn).toBeEnabled();
  });

  it('streaming applies btn-stop, shows stop rect, no send arrow', () => {
    const onStop = vi.fn();
    render(<ChatInput onSend={() => {}} isStreaming={true} onStop={onStop} />);
    const btn = screen.getByRole('button');
    expect(btn).toHaveClass('btn-send');
    expect(btn).toHaveClass('btn-stop');
    expect(btn.querySelector('rect')).toBeInTheDocument();
    expect(btn.querySelector('polygon')).toBeNull();
  });

  it('Enter during streaming does not call onSend', () => {
    const onSend = vi.fn();
    render(<ChatInput onSend={onSend} isStreaming={true} onStop={() => {}} />);
    const textarea = screen.getByPlaceholderText('Posez une question sur vos données...');
    fireEvent.change(textarea, { target: { value: 'hello' } });
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: false });
    expect(onSend).not.toHaveBeenCalled();
  });

  it('Shift+Enter during streaming does not call onSend', () => {
    const onSend = vi.fn();
    render(<ChatInput onSend={onSend} isStreaming={true} onStop={() => {}} />);
    const textarea = screen.getByPlaceholderText('Posez une question sur vos données...');
    fireEvent.change(textarea, { target: { value: 'hello' } });
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: true });
    expect(onSend).not.toHaveBeenCalled();
  });

  it('click during streaming calls onStop', () => {
    const onStop = vi.fn();
    render(<ChatInput onSend={() => {}} isStreaming={true} onStop={onStop} />);
    fireEvent.click(screen.getByRole('button'));
    expect(onStop).toHaveBeenCalledOnce();
  });

  it('Enter while idle with text calls onSend and clears input', () => {
    const onSend = vi.fn();
    render(<ChatInput onSend={onSend} isStreaming={false} onStop={() => {}} />);
    const textarea = screen.getByPlaceholderText('Posez une question sur vos données...');
    fireEvent.change(textarea, { target: { value: 'hello' } });
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: false });
    expect(onSend).toHaveBeenCalledWith('hello');
  });
});
