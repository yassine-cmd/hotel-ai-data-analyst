export default function StatusBadge({ status, className = '' }) {
  const tone = status === 'active' || status === 'success' || status === 'complete'
    ? 'bg-[#ecf7eb] text-[#49ae43]'
    : status === 'inactive' || status === 'error'
      ? 'bg-[#fef2f2] text-[#f04444]'
      : status === 'running' || status === 'warning' || status === 'pending'
        ? 'bg-[#fff6e8] text-[#f5a142]'
        : 'bg-[#f3f5f7] text-[#777b85]';
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10.5px] font-semibold capitalize ${tone} ${className}`}>
      <span className="h-1.5 w-1.5 rounded-full bg-current" />
      {status}
    </span>
  );
}
