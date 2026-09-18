import { useMemo, useState } from 'react';
import { useStore } from '../store.jsx';
import { Badge, Field, Section } from './ui.jsx';
import { age, fullName } from '../data/mock.js';

/**
 * Select youth, review the list, then confirm.
 * Ticking a checkbox does nothing on its own — notifications, simulated SMS and
 * simulated email are only created by the Confirm step, and only for the youth
 * actually selected.
 */
export default function SelectYouthPanel({ targetType, targetId, label, verb = 'selected', alreadySelected = [] }) {
  const { scoped, confirmSelection } = useStore();
  const [query, setQuery] = useState('');
  const [picked, setPicked] = useState([]);
  const [review, setReview] = useState(false);

  const done = new Set(alreadySelected);

  const candidates = useMemo(() => scoped('youth')
    .filter((y) => !y.archived)
    .filter((y) => fullName(y).toLowerCase().includes(query.toLowerCase())), [scoped, query]);

  const toggle = (id) => setPicked((list) => (list.includes(id) ? list.filter((x) => x !== id) : [id, ...list]));
  const chosen = candidates.filter((y) => picked.includes(y.id));
  const chosenAll = scoped('youth').filter((y) => picked.includes(y.id));

  const confirm = () => {
    const count = confirmSelection(targetType, targetId, picked, { verb, label });
    if (count) { setPicked([]); setReview(false); }
  };

  return (
    <Section title="Select youth"
      action={<span className="text-xs text-[#8391A4]">{picked.length} selected</span>}>
      <p className="mt-1 text-sm text-[#5A6C82]">
        Tick the youth, review the list, then confirm. Only the youth you confirm are notified.
      </p>

      <div className="mt-3">
        <Field label="Search youth">
          <input className="field" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Type a name" />
        </Field>
      </div>

      <div className="mt-3 max-h-72 space-y-1.5 overflow-y-auto rounded-lg border border-line p-2">
        {candidates.length === 0 ? (
          <p className="px-2 py-3 text-sm text-[#7A889B]">No youth match that search.</p>
        ) : candidates.map((youth) => (
          <label key={youth.id}
            className={`flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 ${picked.includes(youth.id) ? 'bg-[#EDF3FA]' : 'hover:bg-shell'}`}>
            <input type="checkbox" className="h-5 w-5 shrink-0 rounded border-[#C7D0DA]"
              checked={picked.includes(youth.id)} onChange={() => toggle(youth.id)} />
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium text-navy-900">{fullName(youth)}</span>
              <span className="block text-xs text-[#8391A4]">
                {age(youth.birthDate)} years old · {youth.interests || 'No interest recorded'} · {youth.contact || 'no mobile'}
              </span>
            </span>
            {done.has(youth.id) && <Badge value="selected" />}
          </label>
        ))}
      </div>

      {picked.length > 0 && !review && (
        <button type="button" className="btn btn-primary mt-4 w-full" onClick={() => setReview(true)}>
          Review {picked.length} selected youth
        </button>
      )}

      {review && (
        <div className="mt-4 rounded-lg border border-navy-900 bg-[#F8FAFD] p-4">
          <p className="text-sm font-semibold text-navy-900">Confirm selection</p>
          <p className="mt-1 text-xs text-[#5A6C82]">
            These {chosenAll.length} youth will be marked {verb} for {label} and will each receive an in-app
            notification, a simulated SMS, and a simulated email. No one else is notified.
          </p>
          <ul className="mt-3 space-y-1 text-sm text-navy-900">
            {chosenAll.map((youth) => (
              <li key={youth.id} className="flex justify-between gap-2">
                <span>{fullName(youth)}</span>
                <span className="text-xs text-[#8391A4]">{youth.contact || 'no mobile'}</span>
              </li>
            ))}
          </ul>
          <div className="mt-4 flex flex-wrap gap-2">
            <button type="button" className="btn btn-accent flex-1" onClick={confirm}>Confirm selection</button>
            <button type="button" className="btn btn-ghost" onClick={() => setReview(false)}>Back</button>
          </div>
        </div>
      )}

      {picked.length === 0 && chosen.length === 0 && (
        <p className="mt-3 text-xs text-[#8391A4]">Nothing is sent until you confirm.</p>
      )}
    </Section>
  );
}
