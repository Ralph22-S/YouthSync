import { Field } from './ui.jsx';

/**
 * One filter bar used by every list, so search, filters, sort and reset sit in
 * the same place on every page. It is a grid, not a wrapping flex row, so the
 * controls line up instead of stair-stepping on a narrow screen.
 */
export function FilterBar({ search, onSearch, placeholder = 'Search', children, onReset, sort }) {
  return (
    <div className="card mb-4 p-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {onSearch && (
          <div className="sm:col-span-2">
            <Field label="Search">
              <input className="field" value={search} onChange={(e) => onSearch(e.target.value)} placeholder={placeholder} />
            </Field>
          </div>
        )}
        {children}
        {sort && (
          <Field label="Sort">
            <select className="field" value={sort.value} onChange={(e) => sort.onChange(e.target.value)}>
              {sort.options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </Field>
        )}
      </div>

      {onReset && (
        <div className="mt-3 flex justify-end border-t border-line pt-3">
          <button type="button" className="btn btn-ghost btn-sm" onClick={onReset}>Reset filters</button>
        </div>
      )}
    </div>
  );
}

/** Sort presets. Newest first is the default everywhere. */
export const SORT_NEWEST = [
  ['newest', 'Newest first'],
  ['oldest', 'Oldest first'],
  ['name', 'Name (A–Z)'],
];

export const byNewest = (a, b) =>
  String(b.createdAt || b.at || b.sentAt || '').localeCompare(String(a.createdAt || a.at || a.sentAt || '')) || b.id - a.id;

export const sortRows = (rows, mode, nameOf = (r) => r.name || '') => {
  const copy = [...rows];
  if (mode === 'oldest') return copy.sort((a, b) => byNewest(b, a));
  if (mode === 'name') return copy.sort((a, b) => String(nameOf(a)).localeCompare(String(nameOf(b))));
  return copy.sort(byNewest);
};
