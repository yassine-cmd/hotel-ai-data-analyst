import { useEffect, useRef, useState } from 'react';

export default function Dropdown({ trigger, items = [], align = 'right', direction = 'down', className = '', open: openProp, onToggle }) {
  const [internalOpen, setInternalOpen] = useState(false);
  const ref = useRef(null);
  const open = openProp != null ? openProp : internalOpen;

  const setOpen = (next) => {
    if (openProp != null) onToggle?.(next);
    else setInternalOpen(next);
  };

  useEffect(() => {
    if (!open) return;
    const onDown = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
  }, [open, openProp, onToggle]);

  return (
    <div ref={ref} className={`relative ${className}`}>
      <div onClick={() => setOpen(!open)}>{trigger}</div>
      {open && (
        <div
          role="menu"
          className={`absolute z-50 flex min-w-[150px] animate-fadeIn flex-col gap-0.5 rounded-lg border border-line bg-raised p-1.5 shadow-lg ${
            direction === 'up' ? 'bottom-[calc(100%+6px)]' : 'top-[calc(100%+6px)]'
          } ${align === 'right' ? 'right-0' : 'left-0'}`}
        >
          {items.map((item, i) => (
            <button
              key={i}
              role="menuitem"
              className={`flex items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-xs font-medium transition-colors ${
                item.danger ? 'text-danger hover:bg-danger/10' : 'text-fg-2 hover:bg-white/5 hover:text-fg'
              }`}
              onClick={() => { setOpen(false); item.onClick?.(); }}
            >
              {item.icon}
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
