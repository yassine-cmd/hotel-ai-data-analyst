import { useState } from 'react';

const toAnswer = (a) => (Array.isArray(a) ? a.join(', ') : a);

export default function QuestionCard({ questions, onSend }) {
  const [answers, setAnswers] = useState({});
  const [submitted, setSubmitted] = useState(false);
  const complete = questions.every((_, i) => (Array.isArray(answers[i]) ? answers[i].length > 0 : (answers[i] || '').trim()));

  const toggleOption = (index, option) => {
    if (submitted) return;
    setAnswers((old) => {
      const current = old[index];
      if (questions[index].multi) {
        const list = Array.isArray(current) ? current : [];
        return {
          ...old,
          [index]: list.includes(option)
            ? list.filter((v) => v !== option)
            : [...list, option],
        };
      }
      return { ...old, [index]: option };
    });
  };

  const submit = () => {
    if (submitted || !complete) return;
    setSubmitted(true);
    const text = questions
      .map((question, i) => `Q: ${question.question}\nA: ${toAnswer(answers[i])}`)
      .join('\n\n');
    onSend(text);
  };

  return (
    <div className="question-block rounded-xl border border-accent/30 bg-accent/5 p-4">
      <p className="mb-3 text-[13px] font-semibold text-accent-fg">
        {questions.length > 1 ? 'Veuillez répondre à ces questions pour continuer :' : 'Un peu plus de précision, s\'il vous plaît :'}
      </p>
      {questions.map((question, index) => (
        <div className="mb-3 last:mb-0" key={index}>
          <p className="mb-1.5 text-[12.5px] text-fg-2">{question.question}</p>
          {question.options?.length ? (
            <div className="flex flex-wrap gap-1.5">
              {question.options.map((option) => {
                const selected = answers[index] === option || answers[index]?.includes?.(option);
                return (
                  <button
                    key={option}
                    type="button"
                    className={`rounded-full border px-3 py-1 text-xs transition-colors ${
                      selected
                        ? 'border-accent bg-accent text-white'
                        : 'border-line bg-transparent text-fg-2 hover:border-accent/50 hover:bg-accent/5'
                    }`}
                    aria-pressed={selected}
                    disabled={submitted}
                    onClick={() => toggleOption(index, option)}
                  >
                    {option}
                  </button>
                );
              })}
            </div>
          ) : (
            <input
              className="w-full rounded-lg border border-line bg-input px-3 py-2 text-xs text-fg outline-none transition-colors placeholder:text-fg-3 focus:border-accent disabled:opacity-50"
              placeholder="Saisissez votre réponse…"
              disabled={submitted}
              value={answers[index] || ''}
              onChange={(event) => { if (!submitted) setAnswers((old) => ({ ...old, [index]: event.target.value })); }}
            />
          )}
        </div>
      ))}
      <div className="mt-3 flex items-center gap-3">
        <button
          type="button"
          className="h-8 rounded-lg bg-accent px-4 text-xs font-semibold text-white transition-colors hover:bg-accent-hover disabled:pointer-events-none disabled:opacity-40"
          disabled={!complete || submitted}
          onClick={submit}
        >
          Envoyer les réponses
        </button>
          {submitted && <span className="text-xs font-medium text-success">Réponses envoyées</span>}
      </div>
    </div>
  );
}
