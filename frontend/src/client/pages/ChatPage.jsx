import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useOutletContext } from 'react-router-dom';
import { useChat } from '../hooks/useChat';
import ChatWindow from '../components/ChatWindow';
import ChatInput from '../components/ChatInput';
import AgentLoopPanel from '../components/AgentLoopPanel';

const DEFAULT_DRAWER_WIDTH = 480;

export default function ChatPage() {
  const { session } = useOutletContext();
  const chat = useChat(session);
  const navigate = useNavigate();
  const [panelOpen, setPanelOpen] = useState(false);
  const [drawerWidth, setDrawerWidth] = useState(DEFAULT_DRAWER_WIDTH);
  const [resizing, setResizing] = useState(false);
  const [activeMessageId, setActiveMessageId] = useState(null);
  const paneRef = useRef(null);
  const widthRef = useRef(DEFAULT_DRAWER_WIDTH);

  const onResizeMove = useCallback((w) => {
    widthRef.current = w;
    if (paneRef.current) paneRef.current.style.setProperty('--drawer-width', `${w}px`);
  }, []);

  const onResizeEnd = useCallback(() => {
    setDrawerWidth(widthRef.current);
    setResizing(false);
  }, []);

  useEffect(() => { session.load().catch((err) => console.warn('Session load failed', err)); }, [session.load]);

  useEffect(() => {
    if (paneRef.current) paneRef.current.style.setProperty('--drawer-width', `${widthRef.current}px`);
  });

  const lastAssistant = [...chat.messages].reverse().find((m) => m.role === 'assistant');
  const activeMessage = activeMessageId ? chat.messages.find((m) => m.id === activeMessageId) : null;
  const blocks = activeMessage?.blocks || lastAssistant?.blocks || [];

  useEffect(() => {
    if (lastAssistant?.error?.redirectToSignin) navigate('/signin');
  }, [lastAssistant?.error?.redirectToSignin, navigate]);

  const onTogglePanel = (message) => {
    const targetId = message?.id || null;
    const isSame = activeMessageId != null && activeMessageId === targetId;
    setActiveMessageId(targetId);
    setPanelOpen(isSame ? (o) => !o : true);
  };

  useEffect(() => {
    if (chat.isStreaming && lastAssistant?.id) setActiveMessageId(lastAssistant.id);
  }, [chat.isStreaming, lastAssistant?.id]);

  return (
    <div ref={paneRef} className="relative flex min-w-0 flex-1 overflow-hidden" style={{ '--drawer-width': `${drawerWidth}px` }}>
      <div className={`chat-pane flex min-w-0 flex-1 flex-col overflow-hidden transition-[padding-right] duration-300 ${resizing ? 'transition-none' : ''} ${panelOpen ? 'md:pr-[var(--drawer-width)]' : ''}`}>
        <ChatWindow messages={chat.messages} isStreaming={chat.isStreaming} onSend={chat.send} onTogglePanel={onTogglePanel} activeMessageId={activeMessageId} panelOpen={panelOpen} loadingHistory={session.loadingHistory} sessionError={session.sessionError} onDismissSessionError={() => session.setSessionError(null)} />
        <ChatInput onSend={chat.send} isStreaming={chat.isStreaming} onStop={chat.stop} />
      </div>
      <AgentLoopPanel key={activeMessageId || 'latest'} blocks={blocks} isStreaming={chat.isStreaming} onClose={() => setPanelOpen(false)} panelOpen={panelOpen} maxSteps={chat.maxSteps} width={drawerWidth} onResize={onResizeMove} onResizeStart={() => setResizing(true)} onResizeEnd={onResizeEnd} />
    </div>
  );
}
