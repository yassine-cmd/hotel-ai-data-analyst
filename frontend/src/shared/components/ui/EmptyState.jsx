export default function EmptyState({ title, description, children, compact = false, className = '' }) {
  return (
    <div className={`flex flex-col items-center justify-center gap-1.5 text-center ${compact ? 'px-4 py-7' : 'px-8 py-12'} ${className}`}>
      {title && <p className={`font-medium leading-snug text-fg-3/80 ${compact ? 'text-[12.5px]' : 'text-[13px]'}`}>{title}</p>}
      {description && <p className={`max-w-sm leading-relaxed text-fg-3/60 ${compact ? 'text-[11.5px]' : 'text-[12.5px]'}`}>{description}</p>}
      {children && <div className="mt-2">{children}</div>}
    </div>
  );
}
