export default function IconButton({ label, className = '', children, ...props }) {
  return (
    <button
      aria-label={label}
      title={label}
      className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg text-fg-3 transition-colors hover:bg-white/5 hover:text-fg ${className}`}
      {...props}
    >
      {children}
    </button>
  );
}
