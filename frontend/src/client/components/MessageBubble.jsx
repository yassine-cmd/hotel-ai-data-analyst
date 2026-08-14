import Logo from '../../shared/components/ui/Logo';
import CopyButton from '../../shared/components/ui/CopyButton';

export default function MessageBubble({ message, children, copyText }) {
  if (message.role === 'user') {
    return (
      <div className="message-bubble user flex justify-end">
        <div className="bubble-content max-w-[85%] rounded-[18px] rounded-tr-xs bg-[#3498E9] px-4 py-2.5 text-sm leading-relaxed text-white shadow-[0_8px_18px_rgba(52,152,233,0.16)] md:max-w-[75%]">
          {children}
        </div>
      </div>
    );
  }

  return (
    <div className="message-bubble assistant group flex gap-3 md:gap-3.5">
      <div className="mt-0.5 shrink-0 select-none">
        <Logo size="sm" />
      </div>
      <div className="min-w-0 flex-1">
        {/* Assistant Header Bar */}
        <div className="mb-1 flex h-6 items-center justify-between gap-2">
          <span className="text-[11px] font-medium tracking-wide text-[#777B85] uppercase select-none">
            Assistant
          </span>
          {copyText != null && (
            <div className="opacity-100 transition-opacity duration-150 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100">
              <CopyButton text={copyText} />
            </div>
          )}
        </div>

        {/* Bubble Message Content */}
        <div className="bubble-content min-w-0 text-sm leading-relaxed text-[#30364D]">
          {children}
        </div>
      </div>
    </div>
  );
}