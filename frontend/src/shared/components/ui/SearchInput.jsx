import { useEffect, useState } from 'react';
import { Search, X } from 'lucide-react';

export default function SearchInput({ value, onChange, placeholder = 'Rechercher…', delay = 250, className = '', id }) {
  const [text, setText] = useState(value ?? '');

  useEffect(() => { setText(value ?? ''); }, [value]);

  useEffect(() => {
    if (value === text) return;
    const t = setTimeout(() => onChange?.(text), delay);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [text, delay]);

  return (
    <div className={`relative ${className}`}>
      <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-fg-3" />
      <input
        id={id}
        type="search"
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder={placeholder}
        className="w-full rounded-[10px] border border-line bg-white py-2 pl-9 pr-8 text-[13px] text-fg placeholder:text-fg-3 outline-none transition-all focus:border-[#43A7BA] focus:ring-2 focus:ring-[#43A7BA]/10"
      />
      {text && (
        <button
          type="button"
          aria-label="Effacer la recherche"
          onClick={() => { setText(''); onChange?.(''); }}
          className="absolute right-2.5 top-1/2 -translate-y-1/2 text-fg-3 transition-colors hover:text-fg"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  );
}