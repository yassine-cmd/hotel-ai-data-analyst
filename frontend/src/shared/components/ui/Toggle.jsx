export default function Toggle({ checked, onChange, label, description, disabled, id }) {
  const inputId = id || `toggle-${label?.replace(/\s+/g, '-').toLowerCase()}`;
  return (
    <label htmlFor={inputId} className={`flex cursor-pointer items-center gap-3 ${disabled ? 'opacity-50' : ''}`}>
      <span className="relative inline-block h-5 w-9 shrink-0">
        <input
          id={inputId}
          type="checkbox"
          className="peer sr-only"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onChange?.(e.target.checked)}
        />
        <span className="absolute inset-0 rounded-full bg-line transition-colors peer-checked:bg-accent" />
        <span className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-fg-3 transition-transform peer-checked:translate-x-4 peer-checked:bg-white" />
      </span>
      {(label || description) && (
        <span className="flex flex-col gap-0.5">
          {label && <span className="text-xs font-semibold text-fg">{label}</span>}
          {description && <span className="text-[11px] text-fg-3">{description}</span>}
        </span>
      )}
    </label>
  );
}
