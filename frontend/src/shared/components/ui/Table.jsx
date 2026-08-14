export function Table({ children, className = '', framed = true }) {
  return (
    <div className={`overflow-x-auto ${framed ? 'rounded-[12px] border border-[#e8ecf2] bg-white' : ''} ${className}`}>
      <table className="w-full border-collapse text-left">{children}</table>
    </div>
  );
}

export function Th({ children, className = '' }) {
  return (
    <th className={`whitespace-nowrap border-b border-[#e8ecf2] bg-[#f8fafc] px-4 py-3 text-[10px] font-semibold uppercase tracking-[0.06em] text-[#777b85] ${className}`}>
      {children}
    </th>
  );
}

export function Td({ children, className = '' }) {
  return <td className={`border-b border-[#f0f2f6] px-4 py-3 text-[13px] text-[#4b5563] ${className}`}>{children}</td>;
}

export function Tr({ children, className = '', onClick }) {
  return (
    <tr className={`transition-colors hover:bg-[#f8fafc] ${onClick ? 'cursor-pointer' : ''} ${className}`} onClick={onClick}>
      {children}
    </tr>
  );
}

export function TableFooter({ children }) {
  return <div className="flex items-center justify-between gap-3 border-t border-[#e8ecf2] px-4 py-3 text-[11.5px] text-[#777b85]">{children}</div>;
}
