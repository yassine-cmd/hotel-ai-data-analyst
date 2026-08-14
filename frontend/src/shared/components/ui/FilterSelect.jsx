export default function FilterSelect({ value, onChange, options, label, allLabel = 'Tous', className = '', id }) {
  return (
    <label className={`flex items-center gap-2 ${className}`}>
      {label && <span className="whitespace-nowrap text-[12px] font-semibold text-fg-3">{label}</span>}
      <select
        id={id}
        value={value}
        onChange={(e) => onChange?.(e.target.value)}
        aria-label={label || allLabel}
        className="cursor-pointer rounded-[10px] border border-line bg-white px-3 py-2 text-[13px] text-fg outline-none transition-all focus:border-[#43A7BA] focus:ring-2 focus:ring-[#43A7BA]/10"
      >
        <option value="">{allLabel}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
    </label>
  );
}