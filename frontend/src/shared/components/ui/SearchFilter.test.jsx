// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, fireEvent, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SearchInput from './SearchInput';
import FilterSelect from './FilterSelect';

afterEach(() => { cleanup(); vi.clearAllMocks(); });

describe('SearchInput', () => {
  it('debounces onChange until typing pauses', async () => {
    const onChange = vi.fn();
    render(<SearchInput onChange={onChange} />);

    await fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'a' } });
    await fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'al' } });
    expect(onChange).not.toHaveBeenCalled();

    await waitFor(() => expect(onChange).toHaveBeenCalledTimes(1), { timeout: 500 });
    expect(onChange).toHaveBeenCalledWith('al');
  });

  it('renders a clear button once there is text and re-emits empty', async () => {
    const onChange = vi.fn();
    render(<SearchInput onChange={onChange} delay={0} />);
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'x' } });

    const clear = await screen.findByRole('button', { name: 'Effacer la recherche' });
    fireEvent.click(clear);

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(''));
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });
});

describe('FilterSelect', () => {
  const options = [
    { value: '1', label: 'Administrateur' },
    { value: '0', label: 'Gestionnaire' },
  ];

  it('renders an All option plus provided options', () => {
    render(<FilterSelect value="" onChange={vi.fn()} options={options} />);
    expect(screen.getByRole('combobox').options).toHaveLength(3);
    expect(screen.getByRole('combobox').options[0]).toHaveTextContent('Tous');
  });

  it('calls onChange with the selected value', () => {
    const onChange = vi.fn();
    render(<FilterSelect value="" onChange={onChange} options={options} />);
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '1' } });
    expect(onChange).toHaveBeenCalledWith('1');
  });

  it('uses provided labels for individual options', () => {
    render(<FilterSelect value="1" onChange={vi.fn()} options={options} label="Role" />);
    expect(screen.getByRole('combobox')).toHaveValue('1');
    expect(screen.getByText('Administrateur')).toBeInTheDocument();
  });
});