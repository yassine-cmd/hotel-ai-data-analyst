import { TrendingDown, TrendingUp } from 'lucide-react';

const palette = [
  { bg: 'from-[#4680FF] to-[#2F63D6]', ring: 'bg-[#2A55B8]' },
  { bg: 'from-[#6673FC] to-[#4B54D6]', ring: 'bg-[#4149B8]' },
  { bg: 'from-[#4ABAD2] to-[#2E94A6]', ring: 'bg-[#2A7C8A]' },
  { bg: 'from-[#3FCC7E] to-[#2FA85F]', ring: 'bg-[#2A8C52]' },
];

export default function StatCard({ label, value, delta, icon }) {
  const positive = typeof delta === 'string' && (delta.startsWith('+') || !delta.startsWith('-'));
  const tone = palette[Math.min(palette.length - 1, Math.max(0, (label?.length ?? 0) % palette.length))];

  return (
    <div className={`relative overflow-hidden rounded-[22px] bg-gradient-to-br ${tone.bg} p-5 text-white shadow-[0_12px_30px_rgba(48,54,77,0.16)]`}>
      <div className="absolute right-4 top-4 flex h-12 w-12 items-center justify-center rounded-full bg-black/15 text-white">
        {icon ? <span className="text-white">{icon}</span> : <div className="h-5 w-5 rounded-full bg-white/70" />}
      </div>
      <p className="text-[11px] font-semibold uppercase tracking-[0.24em] text-white/80">{label}</p>
      <p className="mt-3 text-2xl font-semibold tracking-tight text-white tabular-nums">{value}</p>
      <div className="mt-4 h-px w-full bg-white/30" />
      {delta != null && (
        <p className={`mt-3 inline-flex items-center gap-1 text-[11px] font-semibold ${positive ? 'text-white' : 'text-white/90'}`}>
          {positive ? <TrendingUp className="h-3 w-3" /> : <TrendingDown className="h-3 w-3" />}
          {delta}
        </p>
      )}
    </div>
  );
}
