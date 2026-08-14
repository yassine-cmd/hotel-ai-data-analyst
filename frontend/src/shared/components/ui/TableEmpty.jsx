import EmptyState from './EmptyState';

export default function TableEmpty({ title, description, className = '' }) {
  return (
    <div className={`flex h-40 items-center justify-center overflow-hidden rounded-[12px] border border-[#e8ecf2] bg-white ${className}`}>
      <EmptyState compact title={title} description={description} />
    </div>
  );
}
