export function Spinner({ className = 'h-4 w-4' }) {
  return (
    <svg className={`animate-spin ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}

const LOADING_SIZES = { sm: 'h-4 w-4', md: 'h-6 w-6', lg: 'h-7 w-7' };

export function LoadingState({ label = 'Chargement…', size = 'sm', center = false, className = '' }) {
  if (center) {
    return (
      <div role="status" className={`grid min-h-screen place-items-center bg-base ${className}`}>
        <div className="flex items-center gap-2">
          <Spinner className={`${LOADING_SIZES[size]} text-accent`} />
          {label && <span className="text-sm text-fg-3">{label}</span>}
        </div>
      </div>
    );
  }
  return (
    <div role="status" className={`flex items-center gap-2 ${className}`}>
      <Spinner className={`${LOADING_SIZES[size]} text-fg-3`} />
      {label && <span className="text-sm text-fg-3">{label}</span>}
    </div>
  );
}

export function Skeleton({ className = 'h-4 w-full' }) {
  return <div className={`animate-pulse rounded-md bg-white/5 ${className}`} />;
}
