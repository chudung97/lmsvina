import { scryptSync, timingSafeEqual } from 'node:crypto'

let input = ''
for await (const chunk of process.stdin) input += chunk

const { password, salt, hash } = JSON.parse(input)
if (typeof password !== 'string'
  || typeof salt !== 'string'
  || typeof hash !== 'string'
  || !/^[\da-f]{32}$/i.test(salt)
  || !/^[\da-f]{128}$/i.test(hash)) {
  process.stdout.write('false')
  process.exit(0)
}

const expected = Buffer.from(hash, 'hex')
const actual = scryptSync(password, Buffer.from(salt, 'hex'), expected.length, {
  N: 16384,
  r: 8,
  p: 1,
})
process.stdout.write(String(timingSafeEqual(actual, expected)))
