import { Field, Section } from './ui.jsx';
import { CheckboxGroup } from './tags.jsx';
import { ACTIVITY_OPTIONS, INTEREST_OPTIONS, SKILL_OPTIONS } from '../data/mock.js';

/**
 * Eligibility settings for one opportunity.
 * These drive the "matches your profile" hint the youth sees — they never
 * approve anybody. The SK still makes the decision on every application.
 */
export default function EligibilityFields({ values, onChange }) {
  const set = (key, value) => onChange({ ...values, [key]: value });

  return (
    <Section title="Eligibility & profile matching">
      <p className="mt-1 text-xs text-[#8391A4]">
        Optional. Anything you tick here is compared against the youth profile to show a
        recommendation. It is a hint only — nothing is auto-approved.
      </p>

      <div className="mt-4 space-y-5">
        <CheckboxGroup label="Matches these interests" options={INTEREST_OPTIONS} columns={3}
          values={values.tagInterests || []} onChange={(v) => set('tagInterests', v)} />
        <CheckboxGroup label="Matches these skills" options={SKILL_OPTIONS} columns={3}
          values={values.tagSkills || []} onChange={(v) => set('tagSkills', v)} />
        <CheckboxGroup label="Matches these preferred activities" options={ACTIVITY_OPTIONS} columns={3}
          values={values.tagActivities || []} onChange={(v) => set('tagActivities', v)} />

        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Minimum age">
            <input className="field" type="number" min="10" max="35" value={values.minAge || ''}
              onChange={(e) => set('minAge', e.target.value ? Number(e.target.value) : '')} />
          </Field>
          <Field label="Maximum age">
            <input className="field" type="number" min="10" max="35" value={values.maxAge || ''}
              onChange={(e) => set('maxAge', e.target.value ? Number(e.target.value) : '')} />
          </Field>
          <label className="flex items-center gap-2 pt-6 text-sm text-[#4A5B70]">
            <input type="checkbox" className="h-4 w-4 rounded border-[#C7D0DA]"
              checked={Boolean(values.requiresStudying)}
              onChange={(e) => set('requiresStudying', e.target.checked)} />
            Must be currently studying
          </label>
        </div>
      </div>
    </Section>
  );
}
