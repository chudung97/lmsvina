const path = require('node:path');
const dotenv = require('dotenv');
const mysql = require('mysql2/promise');
const ZKLib = require('zklib-js');
const { MAX_CHUNK, COMMANDS, REQUEST_DATA } = require('zklib-js/constants');
const {
  checkNotEventTCP,
  createTCPHeader,
  decodeRecordData40,
  decodeTCPHeader,
  exportErrorMessage,
} = require('zklib-js/utils');

const projectRoot = path.resolve(__dirname, '..');
const collectorLockName = 'workdesk_zkteco_attendance_collector';
const historyPollIntervalMs = 5 * 60 * 1000;
dotenv.config({ path: path.join(projectRoot, 'backend', '.env') });
const deviceIPs = (process.env.ZK_ATTENDANCE_DEVICE_IPS ||
  '192.168.110.235,192.168.110.236,192.168.110.237,192.168.110.238,192.168.1.240,192.168.1.239,192.168.1.238')
  .split(',')
  .map((ip) => ip.trim())
  .filter(Boolean);

function toMysqlTimestamp(value) {
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return null;

  const pad = (part) => String(part).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function normalizeAttendance(record) {
  const userCode = String(record?.userId ?? record?.deviceUserId ?? '').trim();
  const checkTime = toMysqlTimestamp(record?.attTime ?? record?.recordTime);
  if (!checkTime) return null;
  return {
    user_code: userCode,
    check_time: checkTime,
  };
}

function decodeHistoryRecord(packet, ip) {
  if (!Buffer.isBuffer(packet) || packet.length !== 40) {
    throw new Error('ZKTeco history record must contain exactly 40 bytes.');
  }
  const decoded = decodeRecordData40(packet);
  const row = normalizeAttendance(decoded);
  if (!row) throw new Error('ZKTeco history record has an invalid timestamp.');

  return {
    ...row,
    verify_mode: packet[26],
    in_out_mode: packet[31],
    work_code: null,
    device_ip: ip,
    device_record_sn: decoded.userSn,
    source_type: 'history',
    raw_record: Buffer.from(packet),
  };
}

function createPool() {
  const required = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'];
  const missing = required.filter((key) => !process.env[key]);
  if (missing.length) {
    throw new Error(`Missing database configuration: ${missing.join(', ')}`);
  }

  return mysql.createPool({
    host: process.env.DB_HOST,
    port: Number(process.env.DB_PORT || 3306),
    database: process.env.DB_DATABASE,
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD || '',
    connectionLimit: 5,
    waitForConnections: true,
    queueLimit: 0,
  });
}

async function acquireCollectorLock(connection) {
  const [rows] = await connection.query('SELECT GET_LOCK(?, 0) AS acquired', [collectorLockName]);
  return Number(rows[0]?.acquired) === 1;
}

async function connectTcp(client, ip, onClose = () => {}) {
  const socketPromise = client.zklibTcp.createSocket(
    (error) => console.warn(`[attendance ${ip}] Socket error: ${error.message}`),
    onClose,
  );
  const socket = client.zklibTcp.socket;
  const onTimeout = () => {
    socket.destroy(new Error('Device connection timed out'));
  };
  socket.once('timeout', onTimeout);
  try {
    await socketPromise;
    await client.zklibTcp.connect();
    socket.removeListener('timeout', onTimeout);
    socket.setTimeout(0);
    client.connectionType = 'tcp';
  } catch (error) {
    socket.removeListener('timeout', onTimeout);
    throw error;
  }
}

async function disconnectClient(client) {
  if (client.connectionType === 'tcp') {
    if (!client.zklibTcp.socket) {
      client.connectionType = null;
      return;
    }
    await client.disconnect();
    return;
  }
  client.zklibTcp.socket?.destroy();
}

async function readAttendanceBuffer(client, idleTimeout = 60000) {
  const tcp = client.zklibTcp;
  tcp.replyId++;
  const request = createTCPHeader(
    COMMANDS.CMD_DATA_WRRQ,
    tcp.sessionId,
    tcp.replyId,
    REQUEST_DATA.GET_ATTENDANCE_LOGS,
  );
  const reply = await tcp.requestData(request);
  if (!Buffer.isBuffer(reply) || reply.length < 16) {
    throw new Error('Terminal did not return an attendance history header.');
  }

  const header = decodeTCPHeader(reply.subarray(0, 16));
  if (header.commandId === COMMANDS.CMD_DATA) {
    return reply.subarray(16);
  }
  if (![COMMANDS.CMD_ACK_OK, COMMANDS.CMD_PREPARE_DATA].includes(header.commandId)) {
    throw new Error(`Unexpected attendance response: ${exportErrorMessage(header.commandId)}.`);
  }

  const payloadSize = reply.subarray(16).readUIntLE(1, 4);
  const remaining = payloadSize % MAX_CHUNK;
  let chunksRemaining = Math.floor(payloadSize / MAX_CHUNK) + (remaining > 0 ? 1 : 0);
  const result = [];
  let frameBuffer = Buffer.alloc(0);
  let packetBuffer = Buffer.alloc(0);
  let timer;

  return new Promise((resolve, reject) => {
    const cleanup = () => {
      clearTimeout(timer);
      tcp.socket.removeListener('data', onData);
      tcp.socket.removeListener('close', onClose);
    };
    const fail = (error) => {
      cleanup();
      reject(error);
    };
    const refreshTimeout = () => {
      clearTimeout(timer);
      timer = setTimeout(
        () => fail(new Error(`Timed out while receiving attendance history (${chunksRemaining} packets remain).`)),
        idleTimeout,
      );
    };
    const onClose = () => fail(new Error('Terminal disconnected while sending attendance history.'));
    const onData = (data) => {
      frameBuffer = Buffer.concat([frameBuffer, data]);
      while (frameBuffer.length >= 8) {
        const packetLength = frameBuffer.readUIntLE(4, 2);
        const frameLength = 8 + packetLength;
        if (packetLength < 8) {
          fail(new Error('Terminal returned a malformed attendance packet.'));
          return;
        }
        if (frameBuffer.length < frameLength) return;

        const frame = frameBuffer.subarray(0, frameLength);
        frameBuffer = frameBuffer.subarray(frameLength);
        if (checkNotEventTCP(frame)) continue;

        refreshTimeout();
        packetBuffer = Buffer.concat([packetBuffer, frame.subarray(16, frameLength)]);
        const expectedLength = (chunksRemaining > 1 ? MAX_CHUNK : remaining) + 8;
        if (packetBuffer.length >= expectedLength) {
          if (packetBuffer.length !== expectedLength) {
            fail(new Error('Terminal returned an unexpected attendance packet size.'));
            return;
          }
          result.push(packetBuffer.subarray(8));
          packetBuffer = Buffer.alloc(0);
          chunksRemaining -= 1;
          if (chunksRemaining === 0) {
            cleanup();
            const data = Buffer.concat(result);
            if (data.length !== payloadSize) {
              reject(new Error(`Terminal returned ${data.length} of ${payloadSize} attendance bytes.`));
              return;
            }
            resolve(data);
            return;
          }
        }
      }
    };

    if (chunksRemaining === 0) {
      resolve(Buffer.alloc(0));
      return;
    }

    refreshTimeout();
    tcp.socket.on('data', onData);
    tcp.socket.once('close', onClose);
    const fullChunks = Math.floor(payloadSize / MAX_CHUNK);
    for (let index = 0; index <= fullChunks; index += 1) {
      if (index === fullChunks) {
        if (remaining > 0) tcp.sendChunkRequest(fullChunks * MAX_CHUNK, remaining);
      } else {
        tcp.sendChunkRequest(index * MAX_CHUNK, MAX_CHUNK);
      }
    }
  });
}

async function fetchDeviceHistory(ip) {
  const client = new ZKLib(ip, 4370, 10000);
  try {
    await connectTcp(client, ip);
    await client.zklibTcp.freeData();
    const payload = await readAttendanceBuffer(client);
    if (!Buffer.isBuffer(payload) || payload.length < 4) {
      throw new Error('Terminal returned an invalid attendance history payload.');
    }

    const recordData = payload.subarray(4);
    if (recordData.length % 40 !== 0) {
      throw new Error(`Terminal returned a truncated attendance payload (${recordData.length} record bytes).`);
    }

    const rows = [];
    for (let offset = 0; offset < recordData.length; offset += 40) {
      rows.push(decodeHistoryRecord(recordData.subarray(offset, offset + 40), ip));
    }
    await client.zklibTcp.freeData();
    return rows;
  } finally {
    await disconnectClient(client);
  }
}

async function insertRows(connection, rows) {
  let inserted = 0;
  const columns = [
    'user_code',
    'check_time',
    'verify_mode',
    'in_out_mode',
    'work_code',
    'device_ip',
    'device_record_sn',
    'source_type',
    'raw_record',
  ];
  for (let offset = 0; offset < rows.length; offset += 500) {
    const batch = rows.slice(offset, offset + 500);
    const [result] = await connection.query(
      `INSERT INTO cham_cong (${columns.join(', ')}) VALUES ?`,
      [batch.map((row) => columns.map((column) => row[column]))],
    );
    inserted += result.affectedRows;
  }
  return inserted;
}

async function readHistoryFromDevices(fetchHistory = fetchDeviceHistory, ips = deviceIPs) {
  const results = [];
  const failures = [];
  for (const ip of ips) {
    try {
      results.push({ ip, records: await fetchHistory(ip) });
    } catch (error) {
      failures.push(`${ip}: ${error.message}`);
    }
  }
  if (failures.length) {
    throw new Error(`History not replaced; could not read every terminal:\n${failures.join('\n')}`);
  }

  const invalidRecords = results.flatMap(({ ip, records }) => {
    const invalidCount = records.filter((record) =>
      !record || !record.check_time || !Buffer.isBuffer(record.raw_record),
    ).length;
    return invalidCount ? [`${ip}: ${invalidCount} invalid record(s)`] : [];
  });
  if (invalidRecords.length) {
    throw new Error(`History not replaced; records without a valid user code or timestamp:\n${invalidRecords.join('\n')}`);
  }
  const history = results.flatMap((result) => result.records);
  if (history.length === 0) {
    throw new Error('History not replaced; no attendance records were returned.');
  }
  return { devices: results, records: history };
}

async function replaceHistory(pool, fetchHistory = fetchDeviceHistory, ips = deviceIPs) {
  const { records: history } = await readHistoryFromDevices(fetchHistory, ips);
  const connection = await pool.getConnection();
  try {
    const [tables] = await connection.query(
      "SELECT ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cham_cong'",
    );
    const [columns] = await connection.query(
      "SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cham_cong'",
    );
    const columnNames = new Set(columns.map((column) => column.COLUMN_NAME));
    if (tables[0]?.ENGINE !== 'InnoDB' || !columnNames.has('user_code') || !columnNames.has('check_time')) {
      throw new Error('History not replaced; cham_cong must be an InnoDB table with user_code and check_time columns.');
    }

    await connection.beginTransaction();
    try {
      await connection.query('DELETE FROM cham_cong');
      const inserted = await insertRows(connection, history);
      const [counts] = await connection.query('SELECT COUNT(*) AS row_count FROM cham_cong');
      if (Number(counts[0].row_count) !== inserted) {
        throw new Error('History not replaced; the stored row count did not match the inserted record count.');
      }
      await connection.commit();
      console.info(`[attendance] Replaced cham_cong with ${inserted} raw records from ${ips.length} terminals.`);
    } catch (error) {
      await connection.rollback();
      throw error;
    }
  } finally {
    connection.release();
  }
}

async function syncDeviceHistory(pool, ip, fetchHistory = fetchDeviceHistory) {
  const records = await fetchHistory(ip);
  if (records.length === 0) {
    throw new Error('Terminal returned no attendance history records.');
  }

  const serials = new Set();
  for (const record of records) {
    if (!record || !record.check_time || !Buffer.isBuffer(record.raw_record)
      || !Number.isInteger(record.device_record_sn)) {
      throw new Error('Terminal history contains a record without valid fields.');
    }
    if (serials.has(record.device_record_sn)) {
      throw new Error(`Terminal history contains duplicate record serial ${record.device_record_sn}.`);
    }
    serials.add(record.device_record_sn);
  }

  const [rows] = await pool.query(
    'SELECT MAX(device_record_sn) AS last_serial FROM cham_cong WHERE device_ip = ?',
    [ip],
  );
  const lastSerial = Number(rows[0]?.last_serial ?? 0);
  const currentMaxSerial = Math.max(...serials);
  if (currentMaxSerial < lastSerial) {
    throw new Error(
      `Terminal record serial moved backwards from ${lastSerial} to ${currentMaxSerial}; refusing to skip or duplicate records.`,
    );
  }

  const newRecords = records.filter((record) => record.device_record_sn > lastSerial);
  if (newRecords.length === 0) {
    return { fetched: records.length, inserted: 0 };
  }

  const firstNewSerial = Math.min(...newRecords.map((record) => record.device_record_sn));
  if (lastSerial > 0 && firstNewSerial > lastSerial + 1) {
    console.warn(
      `[attendance ${ip}] History serial gap: expected ${lastSerial + 1}, first new record is ${firstNewSerial}.`,
    );
  }

  const inserted = await insertRows(pool, newRecords);
  if (inserted !== newRecords.length) {
    throw new Error(`Database stored ${inserted} of ${newRecords.length} new attendance records.`);
  }
  return { fetched: records.length, inserted };
}

function delay(milliseconds) {
  return new Promise((resolve) => {
    const timer = setTimeout(finish, milliseconds);
    function finish() {
      clearTimeout(timer);
      pendingPollDelays.delete(finish);
      resolve();
    }
    pendingPollDelays.add(finish);
  });
}

let stopping = false;
const pendingPollDelays = new Set();
async function runDevicePoller(pool, ip, fetchHistory = fetchDeviceHistory) {
  while (!stopping) {
    try {
      const { fetched, inserted } = await syncDeviceHistory(pool, ip, fetchHistory);
      console.info(`[attendance ${ip}] History check complete: ${inserted} new, ${fetched} scanned.`);
    } catch (error) {
      if (!stopping) console.error(`[attendance ${ip}] History sync failed: ${error.message}`);
    }

    if (!stopping) await delay(historyPollIntervalMs);
  }
}

async function runCollector() {
  if (deviceIPs.length === 0) throw new Error('No attendance terminal IPs are configured.');
  const pool = createPool();
  let lockConnection;
  let lockAcquired = false;
  try {
    lockConnection = await pool.getConnection();
    lockAcquired = await acquireCollectorLock(lockConnection);
    if (!lockAcquired) {
      console.info('[attendance] Another collector is already active; skipping this process.');
      return;
    }

    await pool.query('SELECT 1');
    const stop = () => {
      stopping = true;
      for (const wake of pendingPollDelays) wake();
    };
    process.once('SIGINT', stop);
    process.once('SIGTERM', stop);

    await Promise.all(deviceIPs.map((ip) => runDevicePoller(pool, ip)));
  } finally {
    try {
      if (lockAcquired) {
        await lockConnection.query('SELECT RELEASE_LOCK(?)', [collectorLockName]);
      }
    } finally {
      lockConnection?.release();
      await pool.end();
    }
  }
}

async function main() {
  if (process.argv.includes('--check-history')) {
    const { devices, records } = await readHistoryFromDevices();
    for (const device of devices) {
      console.info(`[attendance ${device.ip}] Read ${device.records.length} history records.`);
    }
    console.info(`[attendance] Read ${records.length} raw records from ${deviceIPs.length} terminals; database unchanged.`);
    return;
  }
  if (process.argv.includes('--replace-history')) {
    const pool = createPool();
    try {
      await replaceHistory(pool);
    } finally {
      await pool.end();
    }
    return;
  }
  await runCollector();
}

if (require.main === module) {
  main().catch((error) => {
    console.error(`[attendance] ${error.message}`);
    process.exitCode = 1;
  });
}

module.exports = {
  decodeHistoryRecord,
  acquireCollectorLock,
  normalizeAttendance,
  readAttendanceBuffer,
  readHistoryFromDevices,
  replaceHistory,
  syncDeviceHistory,
  toMysqlTimestamp,
};
