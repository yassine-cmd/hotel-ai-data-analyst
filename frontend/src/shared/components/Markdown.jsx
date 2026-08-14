import { useEffect, useRef, useState } from 'react';
import { ExternalLink } from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import remarkMath from 'remark-math';
import rehypeKatex from 'rehype-katex';
import rehypeHighlight from 'rehype-highlight';

function CodeBlock({ className, children, lang }) {
  const [copied, setCopied] = useState(false);
  const timer = useRef(null);
  const codeRef = useRef(null);

  useEffect(() => () => clearTimeout(timer.current), []);

  const handleCopy = () => {
    const text = codeRef.current?.textContent ?? '';
    navigator.clipboard.writeText(text).catch(() => {});
    setCopied(true);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setCopied(false), 1500);
  };

  return <div className="md-code-block-wrapper">
    <div className="md-code-header">
      <span className="md-code-lang">{lang}</span>
      <button className="md-copy-btn" onClick={handleCopy} aria-label="Copier le code"><span aria-live="polite" aria-atomic="true">{copied ? 'Copié' : 'Copier'}</span></button>
    </div>
    <pre className="md-code-block"><code ref={codeRef} className={className}>{children}</code></pre>
  </div>;
}

const components = {
  code({ className, children, ...props }) {
    const tokens = String(className || '').split(/\s+/).filter(Boolean);
    const langToken = tokens.find((t) => t.startsWith('language-'));
    const isInline = !langToken && !String(children).includes('\n');
    if (isInline) return <code className="md-inline-code">{children}</code>;
    const lang = langToken ? langToken.slice('language-'.length) : 'code';
    return <CodeBlock className={className} lang={lang}>{children}</CodeBlock>;
  },
  table({ children }) { return <div className="md-table-wrap"><table>{children}</table></div>; },
  a({ href, children }) {
    const isExternal = /^https?:\/\//i.test(href || '');
    return (
      <a href={href} target="_blank" rel="noopener noreferrer">
        {children}
        {isExternal && <ExternalLink className="md-ext-link" aria-hidden="true" />}
      </a>
    );
  },
};

export default function Markdown({ children, className }) {
  if (!children) return null;
  const cls = className ? `markdown-body ${className}` : 'markdown-body';
  return (
    <div className={cls}>
      <ReactMarkdown
        components={components}
        remarkPlugins={[remarkGfm, remarkMath]}
        rehypePlugins={[rehypeKatex, rehypeHighlight]}
      >
        {children}
      </ReactMarkdown>
    </div>
  );
}