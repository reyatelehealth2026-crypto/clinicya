import { buildHealthProfileDisplay } from './health';

const BASE = {
  weight: null,
  height: null,
  bloodType: null,
  medicalConditions: null,
  drugAllergies: null,
  gender: null as 'male' | 'female' | 'other' | null,
};

describe('buildHealthProfileDisplay', () => {
  it('reports hasHealthInfo=false and an empty conditions list when every column is empty', () => {
    const result = buildHealthProfileDisplay(BASE);
    expect(result.hasUserHealth).toBe(false);
    expect(result.hasHealthInfo).toBe(false);
    expect(result.hasLiffHealth).toBe(false); // always false — LIFF merge deferred, see health.ts module doc
    expect(result.conditions).toEqual([]);
    expect(result.bmi).toBeNull();
  });

  it('reports hasUserHealth=true when only weight is present', () => {
    const result = buildHealthProfileDisplay({ ...BASE, weight: '70.5' });
    expect(result.hasUserHealth).toBe(true);
    expect(result.displayWeight).toBe(70.5);
  });

  it('computes BMI from weight (kg) and height (cm) exactly like the PHP calc', () => {
    const result = buildHealthProfileDisplay({ ...BASE, weight: '70', height: '175' });
    // 70 / (1.75^2) = 22.857...
    expect(result.bmi).toBeCloseTo(22.857, 2);
  });

  it('leaves BMI null when height is present but zero', () => {
    const result = buildHealthProfileDisplay({ ...BASE, weight: '70', height: '0' });
    expect(result.bmi).toBeNull();
  });

  it('splits medical_conditions on commas and newlines, trimming and dropping empties', () => {
    const result = buildHealthProfileDisplay({ ...BASE, medicalConditions: 'เบาหวาน, ความดันสูง\n\nโรคหัวใจ' });
    expect(result.conditions).toEqual(['เบาหวาน', 'ความดันสูง', 'โรคหัวใจ']);
  });

  it('passes drug_allergies through as plain text (LIFF structured allergies deferred)', () => {
    const result = buildHealthProfileDisplay({ ...BASE, drugAllergies: 'Penicillin' });
    expect(result.allergiesText).toBe('Penicillin');
  });

  it.each([
    ['male', 'ชาย', '👨'],
    ['female', 'หญิง', '👩'],
    ['other', 'อื่นๆ', '🧑'],
    [null, '-', '👤'],
  ] as const)('gender=%s -> text=%s icon=%s', (gender, text, icon) => {
    const result = buildHealthProfileDisplay({ ...BASE, gender });
    expect(result.genderText).toBe(text);
    expect(result.genderIcon).toBe(icon);
  });
});
