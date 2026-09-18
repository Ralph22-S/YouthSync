import { Field } from './ui.jsx';
import { EDUCATION_FIELDS, EDUCATION_LEVELS, EDUCATION_STATUSES, STRANDS, YEAR_LEVELS } from '../data/mock.js';

/**
 * Education section. Asks for the status first and only then shows the fields
 * that the chosen level actually needs — a youth who is not studying sees none.
 */
export default function EducationFields({ values, onChange, errors = {} }) {
  const studying = values.educationStatus === 'Currently Studying';
  const shown = studying ? (EDUCATION_FIELDS[values.education] || []) : [];

  const set = (key) => (e) => onChange({ ...values, [key]: e.target.value });

  const setStatus = (e) => {
    const educationStatus = e.target.value;
    onChange(educationStatus === 'Currently Studying'
      ? { ...values, educationStatus, studying: true }
      : { ...values, educationStatus, studying: false, school: '', course: '', yearLevel: '', strand: '' });
  };

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Field label="Education status" error={errors.educationStatus}>
        <select className="field" value={values.educationStatus || ''} onChange={setStatus}>
          <option value="">Select status</option>
          {EDUCATION_STATUSES.map((s) => <option key={s}>{s}</option>)}
        </select>
      </Field>

      <Field label={studying ? 'Educational level' : 'Highest level reached'} error={errors.education}>
        <select className="field" value={values.education || ''} onChange={set('education')}>
          <option value="">Select level</option>
          {EDUCATION_LEVELS.map((l) => <option key={l}>{l}</option>)}
        </select>
      </Field>

      {shown.includes('school') && (
        <Field label="School"><input className="field" value={values.school || ''} onChange={set('school')} /></Field>
      )}

      {shown.includes('course') && (
        <Field label="Course / Program"><input className="field" value={values.course || ''} onChange={set('course')} /></Field>
      )}

      {shown.includes('yearLevel') && (
        <Field label="Year / Grade level">
          <select className="field" value={values.yearLevel || ''} onChange={set('yearLevel')}>
            <option value="">Select</option>
            {YEAR_LEVELS.map((y) => <option key={y}>{y}</option>)}
          </select>
        </Field>
      )}

      {shown.includes('strand') && (
        <Field label="Strand">
          <select className="field" value={values.strand || ''} onChange={set('strand')}>
            <option value="">Select strand</option>
            {STRANDS.map((s) => <option key={s}>{s}</option>)}
          </select>
        </Field>
      )}

      {!studying && values.educationStatus && (
        <p className="text-xs text-[#8391A4] sm:col-span-2">
          School fields are hidden because this youth is not currently studying.
        </p>
      )}
    </div>
  );
}
