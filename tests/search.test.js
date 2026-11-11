import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizeSearchText } from '../src/search.js'

test('search normalization matches Vietnamese names without diacritics', () => {
  assert.equal(normalizeSearchText('Nguyễn Thị Đặng'), 'nguyen thi dang')
})

test('search normalization matches employee codes case-insensitively', () => {
  assert.equal(normalizeSearchText('VN8678'), 'vn8678')
})
