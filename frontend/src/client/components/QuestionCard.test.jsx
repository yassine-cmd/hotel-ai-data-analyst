// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import QuestionCard from './QuestionCard';

afterEach(cleanup);

const questions = [
  { question: 'Which region?', multi: false, options: ['North', 'South'] },
  { question: 'Why?', multi: true, options: ['Price', 'Staff'] },
];

describe('QuestionCard', () => {
  it('submits formatted answers and shows confirmation', () => {
    const onSend = vi.fn();
    render(<QuestionCard questions={questions} onSend={onSend} />);
    fireEvent.click(screen.getByText('North'));
    fireEvent.click(screen.getByText('Price'));
    fireEvent.click(screen.getByText('Envoyer les réponses'));
    expect(onSend).toHaveBeenCalledOnce();
    expect(onSend).toHaveBeenCalledWith('Q: Which region?\nA: North\n\nQ: Why?\nA: Price');
    expect(screen.getByText('Réponses envoyées')).toBeInTheDocument();
  });

  it('blocks a second submit and disables controls after sending', () => {
    const onSend = vi.fn();
    render(<QuestionCard questions={questions} onSend={onSend} />);
    fireEvent.click(screen.getByText('North'));
    fireEvent.click(screen.getByText('Price'));
    fireEvent.click(screen.getByText('Envoyer les réponses'));
    fireEvent.click(screen.getByText('Envoyer les réponses'));
    fireEvent.click(screen.getByText('North'));
    expect(onSend).toHaveBeenCalledOnce();
    expect(screen.getByText('Envoyer les réponses')).toBeDisabled();
    expect(screen.getByText('North')).toBeDisabled();
    expect(screen.getByText('Price')).toBeDisabled();
  });
});
