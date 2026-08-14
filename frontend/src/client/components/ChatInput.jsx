import { useEffect, useRef, useState } from 'react';

export default function ChatInput({ onSend, isStreaming, onStop }) {
  const [value, setValue] = useState('');
  const ref = useRef(null);
  const heightRef = useRef(null);
  useEffect(() => {
    const node = ref.current;
    if (!node) return;
    const next = Math.min(node.scrollHeight, 200);
    if (heightRef.current == null) heightRef.current = next;
    if (next <= heightRef.current) {
      node.style.height = 'auto';
      heightRef.current = Math.min(node.scrollHeight, 200);
    } else {
      node.style.height = `${next}px`;
      heightRef.current = next;
    }
  }, [value]);
  const submit = () => { if (!value.trim()) return; onSend(value); setValue(''); };

  const idle = !isStreaming;
  const hasText = idle && value.trim().length > 0;

  return <div className="mx-auto w-full max-w-[820px] px-4 pb-8 pt-2">
    <div className="input-wrapper flex items-end gap-2 rounded-[18px] border border-[#E0E3E8] bg-white px-3 py-2 shadow-[0_2px_10px_rgba(48,54,77,0.04)] transition-colors focus-within:border-[#43A7BA] focus-within:ring-2 focus-within:ring-[#43A7BA]/10">
      <textarea
        ref={ref}
        className="chat-input max-h-[200px] min-h-[24px] flex-1 resize-none bg-transparent px-1 py-1.5 text-sm leading-relaxed text-[#30364D] outline-none placeholder:text-[#777B85] focus:shadow-none focus-visible:shadow-none"
        value={value}
        placeholder="Posez une question sur vos données..."
        aria-label="Message"
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey && !isStreaming) { e.preventDefault(); submit(); } }}
      />
      <button
        className={`btn-send ${isStreaming ? 'btn-stop' : ''} grid h-9 w-9 shrink-0 place-items-center rounded-xl text-white transition-colors ${
          isStreaming ? 'bg-[#F04444] hover:bg-[#d93636]' : 'bg-[#43A7BA] hover:bg-[#2f8a99]'
        } disabled:pointer-events-none disabled:opacity-40`}
        onClick={isStreaming ? onStop : submit}
        disabled={!isStreaming && !hasText}
        aria-label={isStreaming ? 'Arrêter la génération' : 'Envoyer le message'}
      >
        {isStreaming
          ? <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><rect x="1" y="1" width="10" height="10" rx="2"/></svg>
          : <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>}
      </button>
    </div>
    <p className="mt-2.5 text-center text-[11px] text-[#777B85]">Cet assistant peut faire des erreurs. Vérifiez les informations importantes.</p>
  </div>;
}
