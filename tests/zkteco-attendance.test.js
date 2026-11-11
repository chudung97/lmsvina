import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import test from 'node:test';
import collector from '../scripts/zkteco-attendance-collector.cjs';
import { COMMANDS } from 'zklib-js/constants.js';
import { createTCPHeader } from 'zklib-js/utils.js';

const {
  acquireCollectorLock,
  normalizeAttendance,
  readAttendanceBuffer,
  replaceHistory,
  syncDeviceHistory,
  toMysqlTimestamp,
  decodeHistoryRecord,
} = collector;

test('only one collector process acquires the MySQL advisory lock', async () => {
  let lockName;
  const connection = {
    async query(sql, values) {
      assert.match(sql, /GET_LOCK/);
      lockName = values[0];
      return [[{ acquired: 1 }]];
    },
  };

  assert.equal(await acquireCollectorLock(connection), true);
  assert.equal(lockName, 'workdesk_zkteco_attendance_collector');
});

test('polling inserts only attendance record serials not stored for that terminal', async () => {
  let maxSerial = 42;
  const inserted = [];
  const pool = {
    async query(sql, values) {
      if (sql.startsWith('SELECT MAX')) return [[{ last_serial: maxSerial }]];
      const newRows = values[0];
      inserted.push(...newRows);
      maxSerial = Math.max(...newRows.map((row) => row[6]));
      return [{ affectedRows: newRows.length }];
    },
  };
  const records = [42, 43, 44].map((serial) => ({
    user_code: `user-${serial}`,
    check_time: '2026-05-25 08:00:00',
    verify_mode: 1,
    in_out_mode: 255,
    work_code: null,
    device_ip: '192.0.2.1',
    device_record_sn: serial,
    source_type: 'history',
    raw_record: Buffer.alloc(40, serial),
  }));
  const fetchHistory = async () => records;

  assert.deepEqual(await syncDeviceHistory(pool, '192.0.2.1', fetchHistory), {
    fetched: 3,
    inserted: 2,
  });
  assert.deepEqual(await syncDeviceHistory(pool, '192.0.2.1', fetchHistory), {
    fetched: 3,
    inserted: 0,
  });
  assert.equal(inserted.length, 2);
});

test('polling stops when a terminal serial resets instead of silently skipping entries', async () => {
  let inserted = false;
  const pool = {
    async query(sql) {
      if (sql.startsWith('SELECT MAX')) return [[{ last_serial: 42 }]];
      inserted = true;
      return [{ affectedRows: 1 }];
    },
  };

  await assert.rejects(
    syncDeviceHistory(pool, '192.0.2.1', async () => [{
      user_code: 'user-1',
      check_time: '2026-05-25 08:00:00',
      device_record_sn: 1,
      raw_record: Buffer.alloc(40),
    }]),
    /serial moved backwards/,
  );
  assert.equal(inserted, false);
});

test('reads complete attendance frames even when TCP splits a packet', async () => {
  const payload = Buffer.alloc(44);
  payload.writeUInt32LE(1, 0);
  payload.fill(0x5a, 4);
  const headerPayload = Buffer.alloc(5);
  headerPayload.writeUInt32LE(payload.length, 1);
  const response = createTCPHeader(COMMANDS.CMD_PREPARE_DATA, 17, 1, headerPayload);
  const chunkFrame = createTCPHeader(
    COMMANDS.CMD_DATA,
    17,
    2,
    Buffer.concat([Buffer.alloc(8), payload]),
  );
  const socket = new EventEmitter();
  const tcp = {
    replyId: 0,
    sessionId: 17,
    socket,
    requestData: async () => response,
    sendChunkRequest: () => {
      setImmediate(() => {
        socket.emit('data', chunkFrame.subarray(0, 13));
        socket.emit('data', chunkFrame.subarray(13));
      });
    },
  };

  assert.deepEqual(await readAttendanceBuffer({ zklibTcp: tcp }, 100), payload);
  assert.equal(socket.listenerCount('data'), 0);
});

test('normalizes realtime ZKTeco records using the device user ID', () => {
  assert.deepEqual(normalizeAttendance({
    userId: '000123',
    attTime: new Date(2026, 4, 25, 8, 9, 10),
  }), { user_code: '000123', check_time: '2026-05-25 08:09:10' });
});

test('normalizes historical ZKTeco records using the device user code', () => {
  assert.deepEqual(normalizeAttendance({
    deviceUserId: 'A-17',
    recordTime: '2026-05-25T01:09:10.000Z',
  }), {
    user_code: 'A-17',
    check_time: toMysqlTimestamp('2026-05-25T01:09:10.000Z'),
  });
});

test('preserves records without decoded user codes and rejects invalid timestamps', () => {
  assert.deepEqual(normalizeAttendance({ userId: '', attTime: new Date(2026, 4, 25) }), {
    user_code: '',
    check_time: '2026-05-25 00:00:00',
  });
  assert.equal(normalizeAttendance({ userId: '17', attTime: 'not-a-date' }), null);
});

test('decodes known fields and preserves the complete raw historical record', () => {
  const packet = Buffer.alloc(40);
  packet.writeUInt16LE(42, 0);
  packet.write('A17', 2, 'ascii');
  packet[26] = 2;
  const packedTime = ((((((2026 - 2000) * 12 + 9) * 31 + 20) * 24 + 6) * 60 + 15) * 60) + 30;
  packet.writeUInt32LE(packedTime, 27);
  packet[31] = 0xff;
  const row = decodeHistoryRecord(packet, '192.0.2.1');

  assert.equal(row.user_code, 'A17');
  assert.equal(row.device_record_sn, 42);
  assert.equal(row.device_ip, '192.0.2.1');
  assert.equal(row.source_type, 'history');
  assert.equal(row.raw_record.toString('hex'), packet.toString('hex'));
  assert.equal(row.verify_mode, 2);
  assert.equal(row.in_out_mode, 0xff);
});

test('does not access the database for replacement if any terminal is unreachable', async () => {
  let databaseTouched = false;
  const pool = {
    getConnection() {
      databaseTouched = true;
      throw new Error('Database should not be touched');
    },
  };

  await assert.rejects(
    replaceHistory(
      pool,
      async (ip) => {
        if (ip === 'offline') throw new Error('unreachable');
        return [{
          user_code: 'user-1',
          check_time: '2026-05-25 08:00:00',
          raw_record: Buffer.alloc(40),
        }];
      },
      ['online', 'offline'],
    ),
    /could not read every terminal/,
  );
  assert.equal(databaseTouched, false);
});

test('does not access the database for replacement if terminal history contains invalid records', async () => {
  let databaseTouched = false;
  const pool = {
    getConnection() {
      databaseTouched = true;
      throw new Error('Database should not be touched');
    },
  };

  await assert.rejects(
    replaceHistory(pool, async () => [{
      user_code: 'user-1',
      check_time: '2026-05-25 08:00:00',
      raw_record: Buffer.alloc(40),
    }, null], ['online']),
    /invalid record/,
  );
  assert.equal(databaseTouched, false);
});
