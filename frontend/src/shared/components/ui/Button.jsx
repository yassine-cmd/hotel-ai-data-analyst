import { forwardRef } from 'react';

const VARIANTS = {
  primary: 'bg-[#3498E9] text-white hover:bg-[#2d83c3] active:bg-[#2d83c3] shadow-[0_8px_18px_rgba(52,152,233,0.16)]',
  secondary: 'bg-white border border-[#dfe4eb] text-[#4b5563] hover:border-[#43A7BA] hover:text-[#30364d]',
  ghost: 'bg-transparent text-[#4b5563] hover:bg-[#e3e7ee] hover:text-[#30364d]',
  accent: 'bg-[#ecfaff] text-[#2f7d93] hover:bg-[#dff7fd]',
  danger: 'bg-transparent text-[#f04444] hover:bg-[#fef2f2]',
};

const SIZES = {
  sm: 'h-7 px-2.5 text-xs rounded-md',
  md: 'h-9 px-4 text-[13px] rounded-lg',
  lg: 'h-10 px-5 text-sm rounded-lg',
};

const Button = forwardRef(function Button({ variant = 'secondary', size = 'md', className = '', disabled, loading, children, ...props }, ref) {
  return (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={`inline-flex cursor-pointer select-none items-center justify-center gap-1.5 font-medium transition-colors duration-100 disabled:pointer-events-none disabled:opacity-45 ${VARIANTS[variant]} ${SIZES[size]} ${className}`}
      {...props}
    >
      {children}
    </button>
  );
});

export default Button;
