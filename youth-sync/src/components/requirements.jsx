import { useState } from 'react';
import { useStore } from '../store.jsx';
import { Badge, Confirm, Field, Section } from './ui.jsx';

const ACCEPT_OPTIONS = [
  ['image/*,application/pdf', 'Photo or PDF'],
  ['image/*', 'Photo only'],
  ['application/pdf', 'PDF only'],
];

const EMPTY = { name: '', description: '', required: true, accepts: 'image/*,application/pdf' };

/**
 * Requirements the SK sets for one event, program, or assistance program.
 * Youth see exactly this list when they apply.
 */
export default function RequirementsEditor({ targetType, targetId }) {
  const { requirementsFor, db, addRequirement, updateRequirement, removeRequirement } = useStore();
  const [values, setValues] = useState(EMPTY);
  const rows = requirementsFor(targetType, targetId);

  const submissionCount = (requirementId) => db.submissions.filter((s) => s.requirementId === requirementId).length;

  return (
    <Section title="Requirements" action={<span className="text-xs text-[#8391A4]">{rows.length} configured</span>}>
      {rows.length === 0 ? (
        <p className="mt-3 text-sm text-[#7A889B]">
          No requirements yet. Youth can apply without uploading anything until you add some.
        </p>
      ) : (
        <div className="mt-3 space-y-2">
          {rows.map((requirement) => (
            <div key={requirement.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-line px-3 py-2">
              <div className="min-w-0">
                <p className="text-sm font-medium text-navy-900">{requirement.name}</p>
                <p className="text-xs text-[#5A6C82]">{requirement.description}</p>
                <p className="mt-1 text-xs text-[#8391A4]">
                  {ACCEPT_OPTIONS.find(([v]) => v === requirement.accepts)?.[1] || requirement.accepts}
                  {' · '}{submissionCount(requirement.id)} submitted
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge value={requirement.required ? 'high' : 'draft'} />
                <button type="button" className="btn btn-ghost btn-sm"
                  onClick={() => updateRequirement(requirement.id, { required: !requirement.required })}>
                  Make {requirement.required ? 'optional' : 'required'}
                </button>
                <Confirm className="btn btn-danger btn-sm"
                  message="Remove this requirement? Files already submitted for it stay on the application."
                  onConfirm={() => removeRequirement(requirement.id)}>Remove</Confirm>
              </div>
            </div>
          ))}
        </div>
      )}

      <form className="mt-4 space-y-3 border-t border-line pt-4" onSubmit={(e) => {
        e.preventDefault();
        if (!values.name.trim()) return;
        addRequirement(targetType, targetId, values);
        setValues(EMPTY);
      }}>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Requirement name">
            <input className="field" value={values.name} onChange={(e) => setValues({ ...values, name: e.target.value })} placeholder="e.g. Barangay Certificate" />
          </Field>
          <Field label="Accepted file type">
            <select className="field" value={values.accepts} onChange={(e) => setValues({ ...values, accepts: e.target.value })}>
              {ACCEPT_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </Field>
        </div>
        <Field label="Description shown to the youth">
          <input className="field" value={values.description} onChange={(e) => setValues({ ...values, description: e.target.value })}
            placeholder="e.g. Certificate of residency issued by the barangay." />
        </Field>
        <label className="flex items-center gap-2 text-sm text-[#4A5B70]">
          <input type="checkbox" className="h-4 w-4 rounded border-[#C7D0DA]" checked={values.required}
            onChange={(e) => setValues({ ...values, required: e.target.checked })} /> Required
        </label>
        <button type="submit" className="btn btn-ghost">Add requirement</button>
      </form>
    </Section>
  );
}
