import { useState } from 'react';
import { useStore, useStoreData } from '../store.jsx';
import { Badge, Confirm, Field, FormErrors, Section } from './ui.jsx';

const ACCEPT_OPTIONS = [
  ['image/*,application/pdf', 'Photo or PDF'],
  ['image/*', 'Photo only'],
  ['application/pdf', 'PDF only'],
];

const EMPTY = { name: '', description: '', required: true, accepts: 'image/*,application/pdf' };

function errorFields(err) {
  if (err?.fields && typeof err.fields === 'object') return err.fields;
  if (err?.message) return { form: err.message };
  return { form: 'The requirement could not be saved.' };
}

/**
 * Requirements the SK sets for one assistance program.
 * Definitions are stored by the API for the signed-in organization.
 */
export default function RequirementsEditor({ targetType, targetId }) {
  const { requirementsFor, db, addRequirement, updateRequirement, removeRequirement, mutating } = useStore();
  // Requirements live on the program / assistance copies; file counts on the applications.
  useStoreData(targetType === 'assistance' ? 'assistance' : 'programs', 'applications');
  const [values, setValues] = useState(EMPTY);
  const [editingId, setEditingId] = useState(null);
  const [deletingId, setDeletingId] = useState(null);
  const [errors, setErrors] = useState({});
  const rows = requirementsFor(targetType, targetId);

  const submissionCount = (requirementId) => db.submissions.filter((s) => s.requirementId === requirementId).length;

  const save = async (event) => {
    event.preventDefault();
    if (mutating) return;
    setErrors({});
    try {
      if (editingId) {
        await updateRequirement(editingId, values);
      } else {
        await addRequirement(targetType, targetId, values);
      }
      setValues(EMPTY);
      setEditingId(null);
    } catch (err) {
      setErrors(errorFields(err));
    }
  };

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
                <button type="button" className="btn btn-ghost btn-sm" disabled={mutating}
                  onClick={() => {
                    setErrors({});
                    setEditingId(requirement.id);
                    setValues({
                      name: requirement.name || '',
                      description: requirement.description || '',
                      required: Boolean(requirement.required),
                      accepts: requirement.accepts || EMPTY.accepts,
                    });
                  }}>
                  Edit
                </button>
                <button type="button" className="btn btn-ghost btn-sm" disabled={mutating}
                  onClick={async () => {
                    setErrors({});
                    try {
                      await updateRequirement(requirement.id, { ...requirement, required: !requirement.required });
                    } catch (err) {
                      setErrors(errorFields(err));
                    }
                  }}>
                  {mutating ? 'Saving...' : `Make ${requirement.required ? 'optional' : 'required'}`}
                </button>
                <Confirm className="btn btn-danger btn-sm" disabled={mutating}
                  message="Remove this requirement? Files already submitted for it stay on the application."
                  onConfirm={async () => {
                    setDeletingId(requirement.id);
                    setErrors({});
                    try {
                      await removeRequirement(requirement.id);
                    } catch (err) {
                      setErrors(errorFields(err));
                    } finally {
                      setDeletingId(null);
                    }
                  }}>{deletingId === requirement.id ? 'Deleting...' : 'Remove'}</Confirm>
              </div>
            </div>
          ))}
        </div>
      )}

      <form className="mt-4 space-y-3 border-t border-line pt-4" onSubmit={save}>
        <FormErrors errors={errors} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Requirement name" error={errors.name}>
            <input className="field" value={values.name} disabled={mutating}
              onChange={(e) => setValues({ ...values, name: e.target.value })} placeholder="e.g. Barangay Certificate" />
          </Field>
          <Field label="Accepted file type" error={errors.accepts}>
            <select className="field" value={values.accepts} disabled={mutating}
              onChange={(e) => setValues({ ...values, accepts: e.target.value })}>
              {ACCEPT_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </Field>
        </div>
        <Field label="Description shown to the youth" error={errors.description}>
          <input className="field" value={values.description} disabled={mutating}
            onChange={(e) => setValues({ ...values, description: e.target.value })}
            placeholder="e.g. Certificate of residency issued by the barangay." />
        </Field>
        <label className="flex items-center gap-2 text-sm text-[#4A5B70]">
          <input type="checkbox" className="h-4 w-4 rounded border-[#C7D0DA]" checked={values.required} disabled={mutating}
            onChange={(e) => setValues({ ...values, required: e.target.checked })} /> Required
        </label>
        <div className="flex flex-wrap gap-2">
          <button type="submit" className="btn btn-ghost" disabled={mutating}>
            {mutating ? 'Saving...' : editingId ? 'Save changes' : 'Add requirement'}
          </button>
          {editingId && (
            <button type="button" className="btn btn-ghost" disabled={mutating}
              onClick={() => { setEditingId(null); setValues(EMPTY); setErrors({}); }}>
              Cancel
            </button>
          )}
        </div>
      </form>
    </Section>
  );
}
