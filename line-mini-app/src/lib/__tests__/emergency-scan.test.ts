/**
 * Smoke tests for the client-side emergency keyword scanner.
 *
 * Uses Node's built-in test runner (`node:test`) — no extra dev deps required.
 * Run with: `node --import tsx --test src/lib/__tests__/emergency-scan.test.ts`
 * (tsx is not currently installed; this file is also a guard against future
 *  regressions if a test runner is added later — e.g. Vitest.)
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
// Note: `.ts` extension required for `node --test` ESM resolution; Next.js +
// TypeScript ignore the extension in the compiled bundle.
import { scanEmergency } from '../emergency-scan.ts'

test('returns null for empty string', () => {
  assert.equal(scanEmergency(''), null)
})

test('returns null for non-matching message', () => {
  assert.equal(scanEmergency('สวัสดีครับ มีคำถามเรื่องวิตามิน'), null)
})

test('critical: หายใจไม่ออก (breathing)', () => {
  const r = scanEmergency('ตอนนี้หายใจไม่ออกครับ')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('หายใจลำบาก/แน่นหน้าอก'))
})

test('critical: เจ็บหน้าอก (chest pain)', () => {
  const r = scanEmergency('เจ็บหน้าอกมากเลย')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('เจ็บหน้าอก'))
})

test('critical: ชัก (seizure)', () => {
  const r = scanEmergency('ลูกชักครับ')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('หมดสติ/ชัก'))
})

test('critical: เลือดไหลไม่หยุด (bleeding)', () => {
  const r = scanEmergency('เลือดไหลไม่หยุด')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('เลือดออกมาก'))
})

test('critical: อัมพาต (stroke-like)', () => {
  const r = scanEmergency('แม่มีอาการอัมพาต')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('อาการคล้ายโรคหลอดเลือดสมอง'))
})

test('critical: ลิ้นบวม (anaphylaxis)', () => {
  const r = scanEmergency('กินยาแล้วลิ้นบวม')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('แพ้ยารุนแรง (Anaphylaxis)'))
})

test('critical: ฆ่าตัวตาย (self-harm)', () => {
  const r = scanEmergency('อยากฆ่าตัวตาย')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('ความคิดทำร้ายตัวเอง'))
})

test('critical: overdose token matches case-insensitively', () => {
  const r = scanEmergency('I think this is an OVERDOSE')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.symptoms.includes('กินยาเกินขนาด'))
})

test('warning-only: ไข้สูง 3 วัน', () => {
  const r = scanEmergency('ไข้สูง 3 วันแล้ว')
  assert.equal(r?.severity, 'warning')
  assert.ok(r?.symptoms.includes('ไข้สูง'))
})

test('warning: ปวดหัวรุนแรง', () => {
  const r = scanEmergency('ปวดหัวรุนแรงมาก')
  assert.equal(r?.severity, 'warning')
  assert.ok(r?.symptoms.includes('ปวดหัวรุนแรง'))
})

test('critical takes precedence when both keywords present', () => {
  // contains warning trigger (ไข้สูงมาก) AND critical trigger (หายใจไม่ออก)
  const r = scanEmergency('ไข้สูงมากและหายใจไม่ออก')
  assert.equal(r?.severity, 'critical')
})

test('symptoms are deduplicated when multiple keywords match same rule', () => {
  // both "หายใจไม่ออก" and "แน่นหน้าอกมาก" map to the same symptom
  const r = scanEmergency('หายใจไม่ออกและแน่นหน้าอกมาก')
  assert.equal(r?.severity, 'critical')
  const occurrences = r?.symptoms.filter((s) => s === 'หายใจลำบาก/แน่นหน้าอก').length
  assert.equal(occurrences, 1)
})

test('returns recommendation text for critical', () => {
  const r = scanEmergency('หมดสติ')
  assert.equal(r?.severity, 'critical')
  assert.ok(r?.recommendation.length ?? 0 > 0)
})
