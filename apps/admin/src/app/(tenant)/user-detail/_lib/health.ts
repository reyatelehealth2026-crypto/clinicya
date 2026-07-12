/**
 * health.ts — the users-table fallback branch of user-detail.php's health
 * profile section (lines 665-930): weight/height/blood_type/
 * medical_conditions/drug_allergies straight off the `users` row, plus the
 * BMI calc (lines 711-727) and the conditions-text-split (lines 757-760).
 *
 * OUT OF SCOPE, flagged (not silently dropped): the "LIFF profile" branch —
 * `CustomerHealthEngineService::getHealthProfile()` — is NOT ported. That
 * service merges structured allergy/medication rows from FOUR additional
 * tables (`user_allergies`, `user_medications`, and the LINE-mini-app-owned
 * `user_drug_allergies`/`user_current_medications`) with fuzzy
 * name-deduplication against the plain-text `users` columns, plus a
 * separate communication-style/tips subsystem this page doesn't even render.
 * Porting it faithfully is a substantial, mostly-independent effort out of
 * proportion to this batch's core deliverable (the customer-360 view's
 * profile/points/orders sections). Practical effect: `hasLiffHealth` is
 * always `false` here, so the "อัพเดทจาก LIFF" badge never shows and
 * allergies/medications only ever come from the plain-text `users` columns
 * (`drugAllergies`/`currentMedications` — via `allergiesText`) rather than
 * the richer structured LIFF-entered list. `hasUserHealth` (the common case
 * — weight/height/blood type/conditions/allergies entered by staff or
 * synced from the users table) works fully. Follow-up ticket, not this
 * batch.
 */

export interface UserHealthColumns {
  weight: number | string | null;
  height: number | string | null;
  bloodType: string | null;
  medicalConditions: string | null;
  drugAllergies: string | null;
  gender: 'male' | 'female' | 'other' | null;
}

export interface HealthProfileDisplay {
  /** Always false — see module doc. */
  hasLiffHealth: boolean;
  hasUserHealth: boolean;
  hasHealthInfo: boolean;
  displayWeight: number | null;
  displayHeight: number | null;
  displayBloodType: string | null;
  /** null when weight/height are missing or height <= 0 — mirrors PHP leaving `$bmi = '-'`. */
  bmi: number | null;
  conditions: string[];
  allergiesText: string | null;
  genderText: string;
  genderIcon: string;
}

function toNumberOrNull(value: number | string | null): number | null {
  if (value === null || value === '') {
    return null;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

/** Ported from user-detail.php lines 665-748. */
export function buildHealthProfileDisplay(user: UserHealthColumns): HealthProfileDisplay {
  const hasUserHealth = !!(user.weight || user.height || user.medicalConditions || user.drugAllergies);
  const hasLiffHealth = false;
  const hasHealthInfo = hasLiffHealth || hasUserHealth;

  const displayWeight = toNumberOrNull(user.weight);
  const displayHeight = toNumberOrNull(user.height);
  const displayBloodType = user.bloodType;

  let bmi: number | null = null;
  if (displayWeight && displayHeight && displayHeight > 0) {
    const heightM = displayHeight / 100;
    bmi = displayWeight / (heightM * heightM);
  }

  const conditions = user.medicalConditions
    ? user.medicalConditions
        .split(/[,\n]+/)
        .map((c) => c.trim())
        .filter((c) => c.length > 0)
    : [];

  let genderText = '-';
  let genderIcon = '👤';
  if (user.gender === 'male') {
    genderText = 'ชาย';
    genderIcon = '👨';
  } else if (user.gender === 'female') {
    genderText = 'หญิง';
    genderIcon = '👩';
  } else if (user.gender === 'other') {
    genderText = 'อื่นๆ';
    genderIcon = '🧑';
  }

  return {
    hasLiffHealth,
    hasUserHealth,
    hasHealthInfo,
    displayWeight,
    displayHeight,
    displayBloodType,
    bmi,
    conditions,
    allergiesText: user.drugAllergies,
    genderText,
    genderIcon,
  };
}
