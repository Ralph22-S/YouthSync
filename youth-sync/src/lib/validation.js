/** Shared form rules. Messages are the exact text the UI shows inline. */

export const CONTACT_LENGTH = 11;

/** Strips everything except digits and caps the length, for use in onChange. */
export const digitsOnly = (value) => String(value ?? '').replace(/\D/g, '').slice(0, CONTACT_LENGTH);

export function validateContact(raw) {
  const value = String(raw ?? '').trim();

  if (!value) return 'Contact number is required.';
  if (/[^0-9]/.test(value)) return 'Contact number must contain numbers only.';
  if (value.length !== CONTACT_LENGTH) return `Contact number must be exactly ${CONTACT_LENGTH} digits.`;

  return null;
}

export function validateEmail(raw, { required = false } = {}) {
  const value = String(raw ?? '').trim();

  if (!value) return required ? 'Email address is required.' : null;
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) return 'Enter a valid email address.';

  return null;
}

/** Youth add / edit / self-registration all run through this. */
export function validateYouth(values) {
  const errors = {};

  if (!values.firstName?.trim()) errors.firstName = 'First name is required.';
  if (!values.lastName?.trim()) errors.lastName = 'Last name is required.';
  if (!values.birthDate) errors.birthDate = 'Date of birth is required.';
  else if (new Date(values.birthDate) > new Date()) errors.birthDate = 'Date of birth cannot be in the future.';
  if (!values.address?.trim()) errors.address = 'Address is required.';

  const contact = validateContact(values.contact);
  if (contact) errors.contact = contact;

  const email = validateEmail(values.email);
  if (email) errors.email = email;

  // Interests are chips now, so at least one entry is what counts.
  if (!Array.isArray(values.interests) || values.interests.length === 0) {
    errors.interests = 'At least one interest is required.';
  }

  return errors;
}

export function validateUser(values, { existingEmails = [], requirePassword = true } = {}) {
  const errors = {};

  if (!values.name?.trim()) errors.name = 'Name is required.';

  const email = validateEmail(values.email, { required: true });
  if (email) errors.email = email;
  else if (existingEmails.includes(values.email.trim().toLowerCase())) {
    errors.email = 'That email is already used by another account.';
  }

  if (values.mobile) {
    const contact = validateContact(values.mobile);
    if (contact) errors.mobile = contact;
  }

  if (requirePassword && (values.password ?? '').length < 8) {
    errors.password = 'Password must be at least 8 characters.';
  }

  return errors;
}

export function validateProgram(values) {
  const errors = {};

  if (!values.name?.trim()) errors.name = values.kind === 'event' ? 'Event name is required.' : 'Program name is required.';
  if (!values.scheduledOn) errors.scheduledOn = values.kind === 'event' ? 'Event date is required.' : 'Program date is required.';
  if (values.startsAt && values.endsAt && values.endsAt <= values.startsAt) {
    errors.endsAt = 'End time must be after the start time.';
  }
  if (values.registrationDeadline && values.scheduledOn && values.registrationDeadline > values.scheduledOn) {
    errors.registrationDeadline = 'The deadline must be on or before the activity date.';
  }

  return errors;
}

export function validateAssistance(values) {
  const errors = {};

  if (!values.name?.trim()) errors.name = 'Program name is required.';
  if (!values.category) errors.category = 'Category is required.';

  return errors;
}

export const hasErrors = (errors) => Object.keys(errors).length > 0;
