import { BRAND } from '../../brand';

const SIZES = { sm: 'h-7 w-7 rounded-lg text-[12px]', md: 'h-8 w-8 rounded-lg text-sm', lg: 'h-10 w-10 rounded-xl text-base' };

export default function Logo({ size = 'md', className = '' }) {
  return (
    <span
      className={`inline-grid shrink-0 select-none place-items-center rounded-lg bg-accent font-bold text-white shadow-sm ${SIZES[size]} ${className}`}
      aria-hidden="true"
    >
      {BRAND.monogram}
    </span>
  );
}
