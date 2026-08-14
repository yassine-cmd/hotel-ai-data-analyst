export function smartTitle(text, maxLen = 60) {
  const cleaned = String(text || '').replace(/\s+/g, ' ').trim();
  if (!cleaned) return '';
  if (cleaned.length <= maxLen) return cleaned;
  const space = cleaned.lastIndexOf(' ', maxLen);
  const cut = space > 0 ? space : maxLen;
  return cleaned.slice(0, cut).trimEnd().replace(/[,;:]+$/, '') + '…';
}

export function sessionLabel(session) {
  if (session?.name) return session.name;
  const date = session?.created_at ? new Date(session.created_at) : null;
  if (date && !Number.isNaN(date.getTime())) {
    return `Conversation · ${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`;
  }
  return 'Conversation';
}
