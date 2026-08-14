const SIZES = {
  xs: 'h-6 w-6 text-[9px]',
  sm: 'h-7 w-7 text-[10px]',
  md: 'h-9 w-9 text-xs',
  lg: 'h-14 w-14 text-lg',
};

function initials(name = '') {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export default function Avatar({ name = '', size = 'md', className = '', ...props }) {
  return (
    <span
      className={`inline-grid shrink-0 select-none place-items-center rounded-full bg-accent font-semibold text-white ${SIZES[size]} ${className}`}
      aria-hidden="true"
      {...props}
    >
      {initials(name)}
    </span>
  );
}
