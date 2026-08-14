export default function PageHeader({ title, description, actions }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4 rounded-[18px] border border-[#e8ecf2] bg-white px-5 py-4 shadow-[0_2px_10px_rgba(48,54,77,0.05)]">
      <div>
        {title && <h1 className="text-[22px] font-semibold tracking-tight text-[#30364d]">{title}</h1>}
        {description && <p className="mt-1 text-[13px] text-[#777b85]">{description}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  );
}
