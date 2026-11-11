import assert from 'node:assert/strict'
import { once } from 'node:events'
import express from 'express'
import test from 'node:test'
import Database from 'better-sqlite3'
import { createPermitsRouter } from '../server/features/permits/routes.js'

test('gate permit requires assigned approval and guard confirmation in order', async (context) => {
  const db = new Database(':memory:')
  db.exec(`
    CREATE TABLE users (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      email TEXT,
      active INTEGER NOT NULL
    );
    INSERT INTO users (id, name, email, active) VALUES
      ('requester', 'Người lập', 'requester@example.test', 1),
      ('approver', 'Người duyệt', 'approver@example.test', 1),
      ('guard', 'Bảo vệ', 'guard@example.test', 1),
      ('other', 'Người khác', 'other@example.test', 1),
      ('inactive', 'Tài khoản khóa', 'inactive@example.test', 0);
    CREATE TABLE gate_permits (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      requester_id TEXT NOT NULL REFERENCES users(id),
      approver_id TEXT NOT NULL REFERENCES users(id),
      guard_id TEXT NOT NULL REFERENCES users(id),
      direction TEXT NOT NULL,
      permit_date TEXT NOT NULL,
      permit_time TEXT NOT NULL,
      return_time TEXT,
      purpose TEXT NOT NULL,
      destination TEXT NOT NULL,
      vehicle_info TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'pending_approval',
      approver_note TEXT NOT NULL DEFAULT '',
      guard_note TEXT NOT NULL DEFAULT '',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      approved_at TEXT,
      guard_confirmed_at TEXT
    );
  `)
  const app = express()
  app.use(express.json())
  app.use((request, _response, next) => {
    request.user = { id: request.get('x-user-id') || 'requester' }
    next()
  })
  app.use('/api/permits', createPermitsRouter(db))
  const server = app.listen(0, '127.0.0.1')
  await once(server, 'listening')
  context.after(() => {
    server.close()
    db.close()
  })
  const base = `http://127.0.0.1:${server.address().port}/api/permits`
  const call = async (path, userId, body) => {
    const response = await fetch(`${base}${path}`, {
      method: body ? 'POST' : 'GET',
      headers: {
        'x-user-id': userId,
        ...(body ? { 'content-type': 'application/json' } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    })
    return { status: response.status, data: await response.json() }
  }

  const users = await call('/users', 'requester')
  assert.equal(users.status, 200)
  assert.equal(users.data.some((user) => user.id === 'requester'), false)
  assert.equal(users.data.some((user) => user.id === 'inactive'), false)

  const payload = {
    direction: 'out',
    date: '2026-10-23',
    time: '09:00',
    returnTime: '12:00',
    purpose: 'Gặp khách hàng',
    destination: 'Quận 1',
    vehicleInfo: 'Xe máy',
    approverId: 'approver',
    guardId: 'guard',
  }
  const created = await call('/', 'requester', payload)
  assert.equal(created.status, 201)
  assert.equal(created.data.status, 'pending_approval')
  assert.equal(created.data.requesterName, 'Người lập')
  assert.equal(created.data.approverName, 'Người duyệt')
  assert.equal(created.data.guardName, 'Bảo vệ')
  const permitPath = `/${created.data.id}`

  assert.equal((await call('/', 'other')).data.length, 0)
  assert.equal((await call(`${permitPath}/decision`, 'other', {
    decision: 'approve',
  })).status, 403)
  assert.equal((await call(`${permitPath}/guard`, 'guard', {
    decision: 'confirm',
  })).status, 409)

  const approved = await call(`${permitPath}/decision`, 'approver', {
    decision: 'approve',
    note: 'Đồng ý',
  })
  assert.equal(approved.status, 200)
  assert.equal(approved.data.status, 'pending_guard')

  const confirmed = await call(`${permitPath}/guard`, 'guard', {
    decision: 'confirm',
    note: 'Đã kiểm tra tại cổng',
  })
  assert.equal(confirmed.status, 200)
  assert.equal(confirmed.data.status, 'approved')
  assert.equal(confirmed.data.approverNote, 'Đồng ý')
  assert.equal(confirmed.data.guardNote, 'Đã kiểm tra tại cổng')

  assert.equal((await call(`${permitPath}/decision`, 'approver', {
    decision: 'reject',
    note: 'Quá muộn',
  })).status, 409)
  assert.equal((await call('/', 'other')).data.length, 0)
  assert.equal((await call('/', 'guard')).data[0].status, 'approved')

  const rejectedRequest = await call('/', 'requester', payload)
  assert.equal(rejectedRequest.status, 201)
  const rejected = await call(`/${rejectedRequest.data.id}/decision`, 'approver', {
    decision: 'reject',
    note: 'Chưa đủ thông tin',
  })
  assert.equal(rejected.status, 200)
  assert.equal(rejected.data.status, 'rejected')
  assert.equal(rejected.data.approverNote, 'Chưa đủ thông tin')
  assert.equal((await call(`/${rejectedRequest.data.id}/guard`, 'guard', {
    decision: 'confirm',
  })).status, 409)

  const guardRejectedRequest = await call('/', 'requester', payload)
  assert.equal(guardRejectedRequest.status, 201)
  assert.equal((await call(`/${guardRejectedRequest.data.id}/decision`, 'approver', {
    decision: 'approve',
  })).data.status, 'pending_guard')
  const guardRejected = await call(`/${guardRejectedRequest.data.id}/guard`, 'guard', {
    decision: 'reject',
    note: 'Thiếu giấy tờ tùy thân',
  })
  assert.equal(guardRejected.status, 200)
  assert.equal(guardRejected.data.status, 'rejected')
  assert.ok(guardRejected.data.approvedAt)
  assert.ok(guardRejected.data.guardConfirmedAt)
})

test('gate permit rejects invalid assignments, dates, times and empty rejection notes', async (context) => {
  const db = new Database(':memory:')
  db.exec(`
    CREATE TABLE users (id TEXT PRIMARY KEY, name TEXT, email TEXT, active INTEGER);
    INSERT INTO users VALUES ('requester', 'Người lập', 'requester@example.test', 1);
    INSERT INTO users VALUES ('approver', 'Người duyệt', 'approver@example.test', 1);
    INSERT INTO users VALUES ('guard', 'Bảo vệ', 'guard@example.test', 1);
    INSERT INTO users VALUES ('inactive', 'Tài khoản khóa', 'inactive@example.test', 0);
    CREATE TABLE gate_permits (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      requester_id TEXT, approver_id TEXT, guard_id TEXT, direction TEXT,
      permit_date TEXT, permit_time TEXT, return_time TEXT, purpose TEXT,
      destination TEXT, vehicle_info TEXT DEFAULT '', status TEXT DEFAULT 'pending_approval',
      approver_note TEXT DEFAULT '', guard_note TEXT DEFAULT '',
      created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
      approved_at TEXT, guard_confirmed_at TEXT
    );
  `)
  const app = express()
  app.use(express.json())
  app.use((request, _response, next) => {
    request.user = { id: request.get('x-user-id') || 'requester' }
    next()
  })
  app.use('/api/permits', createPermitsRouter(db))
  const server = app.listen(0, '127.0.0.1')
  await once(server, 'listening')
  context.after(() => {
    server.close()
    db.close()
  })
  const base = `http://127.0.0.1:${server.address().port}/api/permits`
  const payload = {
    direction: 'out',
    date: '2026-10-23',
    time: '09:00',
    returnTime: '12:00',
    purpose: 'Gặp khách hàng',
    destination: 'Quận 1',
    approverId: 'approver',
    guardId: 'guard',
  }
  const post = async (body) => {
    const response = await fetch(base, {
      method: 'POST',
      headers: { 'x-user-id': 'requester', 'content-type': 'application/json' },
      body: JSON.stringify(body),
    })
    return response.status
  }

  assert.equal(await post({ ...payload, date: '2026-02-30' }), 400)
  assert.equal(await post({ ...payload, returnTime: '08:30' }), 400)
  assert.equal(await post({ ...payload, guardId: 'approver' }), 400)
  assert.equal(await post({ ...payload, approverId: 'inactive' }), 400)

  const createdResponse = await fetch(base, {
    method: 'POST',
    headers: { 'x-user-id': 'requester', 'content-type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const created = await createdResponse.json()
  const rejectResponse = await fetch(`${base}/${created.id}/decision`, {
    method: 'POST',
    headers: { 'x-user-id': 'approver', 'content-type': 'application/json' },
    body: JSON.stringify({ decision: 'reject', note: '' }),
  })
  assert.equal(rejectResponse.status, 400)
})
