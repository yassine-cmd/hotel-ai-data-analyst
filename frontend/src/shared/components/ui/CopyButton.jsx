import { useState } from 'react';
import { Check, Copy } from 'lucide-react';
import IconButton from './IconButton';

export default function CopyButton({ text, label = 'Copier', className = '' }) {
  const [copied, setCopied] = useState(false);
  const handleCopy = async () => {
    try { await navigator.clipboard.writeText(text); } catch { /* noop */ }
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };
  return (
    <IconButton label={copied ? 'Copié' : label} onClick={handleCopy} className={`h-6 w-6 ${className}`}>
      {copied ? <Check className="h-3.5 w-3.5 text-success" /> : <Copy className="h-3.5 w-3.5" />}
    </IconButton>
  );
}
