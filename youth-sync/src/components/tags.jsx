import { useState } from 'react';

/**
 * Chip / tag input. One entry per value — never a single free-text box.
 * Used for Skills and Interests.
 */
export function TagInput({ label, values = [], onChange, options = [], placeholder, required, error, hint, addLabel = 'Add' }) {
  const [draft, setDraft] = useState('');

  const add = (raw) => {
    const value = String(raw || '').trim();
    if (!value) return;
    if (values.some((v) => v.toLowerCase() === value.toLowerCase())) { setDraft(''); return; }
    onChange([...values, value]);
    setDraft('');
  };

  const remove = (value) => onChange(values.filter((v) => v !== value));
  const suggestions = options.filter((o) => !values.includes(o));

  return (
    <div>
      <label className="label">
        {label} {required && <span className="text-[#B3261E]">*</span>}
      </label>

      <div className={`min-h-[44px] rounded-lg border bg-white p-2 ${error ? 'border-[#B3261E]' : 'border-[#D8DEE6]'}`}>
        {values.length === 0 ? (
          <p className="px-1 py-1 text-sm text-[#9AA7B6]">{placeholder || 'Nothing added yet.'}</p>
        ) : (
          <ul className="flex flex-wrap gap-2">
            {values.map((value) => (
              <li key={value} className="inline-flex items-center gap-1.5 rounded-full bg-shell px-3 py-1.5 text-sm text-navy-900">
                {value}
                <button type="button" aria-label={`Remove ${value}`} onClick={() => remove(value)}
                  className="grid h-5 w-5 place-items-center rounded-full text-[#5A6C82] hover:bg-white hover:text-[#B3261E]">×</button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="mt-2 flex gap-2">
        <input className="field" value={draft} list={`${label}-options`}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(draft); } }}
          placeholder={`Type and press Enter`} />
        <datalist id={`${label}-options`}>{suggestions.map((o) => <option key={o} value={o} />)}</datalist>
        <button type="button" className="btn btn-ghost whitespace-nowrap" onClick={() => add(draft)}>+ {addLabel}</button>
      </div>

      {suggestions.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {suggestions.slice(0, 8).map((option) => (
            <button key={option} type="button" onClick={() => add(option)}
              className="rounded-full border border-line px-2.5 py-1 text-xs text-[#5A6C82] hover:border-navy-600 hover:text-navy-900">
              + {option}
            </button>
          ))}
        </div>
      )}

      {error ? <p className="mt-1 text-xs font-medium text-[#96201A]">{error}</p>
        : hint && <p className="mt-1 text-xs text-[#8391A4]">{hint}</p>}
    </div>
  );
}

/** Multi-select checkbox group, for Preferred Activities. */
export function CheckboxGroup({ label, options, values = [], onChange, hint, error, columns = 2 }) {
  const toggle = (option) => onChange(values.includes(option)
    ? values.filter((v) => v !== option)
    : [...values, option]);

  return (
    <div>
      <label className="label">{label}</label>
      <div className={`grid gap-2 ${columns === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
        {options.map((option) => (
          <label key={option}
            className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm ${
              values.includes(option) ? 'border-navy-600 bg-[#F4F8FD] text-navy-900' : 'border-line bg-white text-[#4A5B70]'}`}>
            <input type="checkbox" className="h-4 w-4 rounded border-[#C7D0DA]"
              checked={values.includes(option)} onChange={() => toggle(option)} />
            {option}
          </label>
        ))}
      </div>
      {error ? <p className="mt-1 text-xs font-medium text-[#96201A]">{error}</p>
        : hint && <p className="mt-1 text-xs text-[#8391A4]">{hint}</p>}
    </div>
  );
}
