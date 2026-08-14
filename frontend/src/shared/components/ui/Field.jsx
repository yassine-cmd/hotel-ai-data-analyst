export default function Field({ label, htmlFor, hint, error, children, className = '' }) {
  return (
    <div className={`flex flex-col gap-1.5 ${className}`}>
      {label && <label htmlFor={htmlFor} className="text-[12px] font-semibold text-[#30364d]">{label}</label>}
      {children}
      {hint && !error && <p className="text-[11px] text-[#777b85]">{hint}</p>}
      {error && <p className="text-[11px] text-[#f04444]">{error}</p>}
    </div>
  );
}

export const inputClass = 'w-full rounded-[10px] border border-[#dfe4eb] bg-white px-3 py-2 text-[13px] text-[#30364d] placeholder:text-[#777b85] outline-none transition-all focus:border-[#43A7BA] focus:ring-2 focus:ring-[#43A7BA]/10';

export function TextInput({ className = '', ...props }) {
  return <input className={`${inputClass} ${className}`} {...props} />;
}

export function TextArea({ className = '', ...props }) {
  return <textarea className={`${inputClass} min-h-[80px] resize-y leading-relaxed ${className}`} {...props} />;
}

export function Select({ className = '', children, ...props }) {
  return (
    <select className={`${inputClass} cursor-pointer ${className}`} {...props}>
      {children}
    </select>
  );
}
