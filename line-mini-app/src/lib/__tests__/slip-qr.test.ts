/**
 * Unit tests for the pure QR-decode seam used by slip upload.
 *
 * Run with: node --experimental-strip-types --test src/lib/__tests__/slip-qr.test.ts
 * The scanner (jsQR) is injected so these tests need no DOM and no jsQR install.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { decodeQrFromImageData } from '../slip-qr.ts'

const img = { data: new Uint8ClampedArray(4), width: 1, height: 1 }

test('returns the decoded payload when the scanner finds a QR code', () => {
  const scan = () => ({ data: '0046000600000101030060225N0067683862100760851590365102TH9104476C' })
  assert.equal(
    decodeQrFromImageData(img, scan),
    '0046000600000101030060225N0067683862100760851590365102TH9104476C'
  )
})

test('returns null when the scanner finds no code', () => {
  assert.equal(decodeQrFromImageData(img, () => null), null)
})

test('returns null for an empty/whitespace payload', () => {
  assert.equal(decodeQrFromImageData(img, () => ({ data: '   ' })), null)
})

test('trims surrounding whitespace from the payload', () => {
  assert.equal(decodeQrFromImageData(img, () => ({ data: '  ABC123  ' })), 'ABC123')
})

test('swallows scanner errors and returns null', () => {
  const scan = () => {
    throw new Error('decode boom')
  }
  assert.equal(decodeQrFromImageData(img, scan), null)
})
