export default function Card({ className = '', as: Tag = 'div', ...props }) {
  return <Tag className={`rounded-[18px] border border-[#e8ecf2] bg-white shadow-[0_2px_12px_rgba(48,54,77,0.06)] ${className}`} {...props} />;
}
