import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const controls = path.dirname(fileURLToPath(import.meta.url));
const expectedFile = path.join(controls, 'expected-source-hashes.json');
const expectedSha = '83031bc6ee6e69dc09128a2c13da74243fe0c255b37aa0f2fc07503476168f3c';
const owned = new Set();
const results = [];
const commands = [];
let aborted = false;
let server;
let privateRoot;
let output;
let totalTimer;
let globalEscalation;
const receipt = {schema: 1, status: 'FAILED', qualification_cases: 0, commands,
  profile: 'Hosted Ubuntu, not Docker ARM64 or an old CLI/runtime hash reuse',
  production_mutations: false, raw_logs_vendor_home_uploaded: false};
function sha(file) {
  const hash = crypto.createHash('sha256');
  const buffer = Buffer.allocUnsafe(65536);
  const fd = fs.openSync(file, 'r');
  try {
    let bytes;
    while ((bytes = fs.readSync(fd, buffer, 0, buffer.length, null)) !== 0) hash.update(buffer.subarray(0, bytes));
    return hash.digest('hex');
  } finally { fs.closeSync(fd); }
}
const save = (name, value) => fs.writeFileSync(path.join(output, name), JSON.stringify(value, null, 2) + '\n', {flag: 'wx', mode: 0o600});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

function groupAbsent(state) {
  if (!state.child.pid) return state.closed;
  try { process.kill(-state.child.pid, 0); return false; }
  catch (error) { if (error.code === 'ESRCH') return true; throw error; }
}

function childLive(state) {
  return !state.retired && Number.isInteger(state.child.pid) && state.child.pid > 0
    && !state.closed && state.child.exitCode === null && state.child.signalCode === null;
}

function signalOwned(state, signal) {
  if (state.retired) return true;
  // This is not a kernel CAS: an exit can still race the syscall after this check.
  if (!childLive(state)) {
    state.record.signal_suppressed_not_live = true;
    return false;
  }
  try { process.kill(-state.child.pid, signal); return true; }
  catch (error) {
    state.record.signal_error = error.code ?? 'UNKNOWN';
    return false;
  }
}

async function settle(state) {
  if (state.record.unresolved_group_after_exit) return false;
  for (const signal of ['SIGTERM', 'SIGKILL']) {
    const absent = groupAbsent(state);
    if (state.closed && absent) {
      state.retired = true;
      owned.delete(state);
      return true;
    }
    if (!childLive(state) && !absent) {
      state.record.unresolved_group_after_exit = true;
      return false;
    }
    if (childLive(state)) signalOwned(state, signal);
    const deadline = Date.now() + 2000;
    while (Date.now() < deadline) {
      const absent = groupAbsent(state);
      if (state.closed && absent) {
        state.retired = true;
        owned.delete(state);
        return true;
      }
      if (!childLive(state) && !absent) {
        state.record.unresolved_group_after_exit = true;
        return false;
      }
      await pause(25);
    }
  }
  return false;
}

async function capture(label, executable, args, cwd, env, timeout, limit = 2 * 1024 * 1024, liveServer) {
  assert.equal(aborted, false, 'Qualification interrupted/deadline');
  const record = {label, timeout_millis: timeout, output_limit_bytes: limit,
    timedOut: false, overflow: false, signal_error: null, signal_suppressed_not_live: false,
    unresolved_group_after_exit: false, reaped_and_group_absent: false};
  commands.push(record);
  const state = {closed: false, retired: false, record};
  state.child = spawn(executable, args, {cwd, env, detached: true, stdio: ['ignore', 'pipe', 'pipe']});
  owned.add(state);
  let bytes = 0;
  let overflow = false;
  let timedOut = false;
  const stdout = [], stderr = [];
  let escalation;
  const terminate = () => {
    signalOwned(state, 'SIGTERM');
    escalation ??= setTimeout(() => signalOwned(state, 'SIGKILL'), 2000);
  };
  const timer = setTimeout(() => { timedOut = record.timedOut = true; terminate(); liveServer?.closeAllConnections(); }, timeout);
  try {
    const code = await new Promise((resolve, reject) => {
      for (const [stream, chunks] of [[state.child.stdout, stdout], [state.child.stderr, stderr]]) {
        stream.on('data', chunk => {
          bytes += chunk.length;
          if (bytes > limit) { overflow = record.overflow = true; terminate(); return; }
          chunks.push(chunk);
        });
      }
      state.child.once('error', reject);
      state.child.once('close', code => { state.closed = true; resolve(code); });
    });
    record.exit_code = code;
    record.output_bytes = Math.min(bytes, limit);
    assert.equal(timedOut, false, 'Owned command deadline');
    assert.equal(overflow, false, 'Owned command output cap');
    assert.equal(aborted, false, 'Qualification interrupted/deadline');
    if (code !== 0) {
      fs.writeFileSync(path.join(privateRoot, `${label}.stderr`), Buffer.concat(stderr), {flag: 'wx', mode: 0o600});
      throw new Error('Owned command failed; no raw diagnostics exported');
    }
    return Buffer.concat(stdout).toString('utf8');
  } finally {
    clearTimeout(timer);
    clearTimeout(escalation);
    record.timedOut = timedOut;
    record.overflow = overflow;
    record.output_bytes = Math.min(bytes, limit);
    record.output_bytes_observed = bytes;
    record.exit_code = state.child.exitCode;
    record.signal_code = state.child.signalCode;
    record.child_close_observed = state.closed;
    record.reaped_and_group_absent = await settle(state);
    if (!record.reaped_and_group_absent) throw new Error('Owned process cleanup unproven');
  }
}

function interrupt() {
  aborted = true;
  for (const state of owned) signalOwned(state, 'SIGTERM');
  globalEscalation ??= setTimeout(() => {
    for (const state of owned) signalOwned(state, 'SIGKILL');
  }, 2000);
  server?.closeAllConnections();
}

async function main() {
  assert.deepEqual(process.argv.slice(2), ['--qualify']);
  assert.equal(process.platform, 'linux');
  assert.equal(process.version, 'v26.13.0');
  assert.equal(sha(expectedFile), expectedSha);
  const expected = JSON.parse(fs.readFileSync(expectedFile, 'utf8'));
  assert.deepEqual(expected.profiles.map(p => [p.kind, p.head, p.checked_files.length]), [
    ['modern', '4b583db8aecc741e2553820c814ac83deeb4690c', 49],
    ['legacy', '8cbdef82827a34bc9e2e059264e9d2ae86dd9b00', 69],
  ]);
  const get = name => process.env[`ELVEN_QUAL_${name}`];
  assert.equal(get('REPOSITORY'), 'elven-observability/opentelemetry-instrumentation-php');
  for (const name of ['CONTROL_SHA', 'WORKFLOW_SHA']) assert.match(get(name), /^[a-f0-9]{40}$/);
  for (const name of ['RUN_ID', 'RUN_ATTEMPT']) assert.match(get(name), /^[0-9]+$/);
  const temporary = get('RUNNER_TEMP');
  assert.ok(path.isAbsolute(temporary));
  const proposedOutput = path.join(temporary, 'elven-php-tag-receipts');
  fs.mkdirSync(proposedOutput, {mode: 0o700});
  output = proposedOutput;
  privateRoot = fs.mkdtempSync(path.join(temporary, 'elven-php-private-'));
  fs.chmodSync(privateRoot, 0o700);
  const home = path.join(privateRoot, 'home');
  fs.mkdirSync(home, {mode: 0o700});
  const php = fs.realpathSync(get('PHP'));
  const composer = fs.realpathSync(get('COMPOSER'));
  const env = {PATH: `${path.dirname(php)}:/usr/bin:/bin`, LANG: 'C', HOME: home,
    COMPOSER_HOME: path.join(home, 'composer'), COMPOSER_CACHE_DIR: path.join(home, 'cache'),
    COMPOSER_AUTH: '{}', GIT_TERMINAL_PROMPT: '0', GIT_CONFIG_GLOBAL: '/dev/null', GIT_CONFIG_SYSTEM: '/dev/null',
    GIT_CONFIG_COUNT: '2', GIT_CONFIG_KEY_0: 'gc.auto', GIT_CONFIG_VALUE_0: '0',
    GIT_CONFIG_KEY_1: 'maintenance.auto', GIT_CONFIG_VALUE_1: 'false'};
  receipt.control = {sha: get('CONTROL_SHA'), workflow_sha: get('WORKFLOW_SHA'), workflow_ref: get('WORKFLOW_REF'),
    run_id: get('RUN_ID'), run_attempt: get('RUN_ATTEMPT'), repository: get('REPOSITORY'),
    harness_sha256: sha(fileURLToPath(import.meta.url)), expected_metadata_sha256: expectedSha};
  process.on('SIGTERM', interrupt);
  process.on('SIGINT', interrupt);
  totalTimer = setTimeout(interrupt, 720000);
  const consumer = path.join(controls, 'consumer.php');
  assert.equal(sha(consumer), expected.consumer_sha256);
  const runtimeCode = `echo json_encode(array('version'=>PHP_VERSION,'version_id'=>PHP_VERSION_ID,
    'sapi'=>PHP_SAPI,'binary'=>PHP_BINARY,'os'=>PHP_OS_FAMILY,'extensions'=>get_loaded_extensions(),
    'loaded_ini'=>php_ini_loaded_file(),'scanned_ini'=>php_ini_scanned_files(),
    'memory_limit'=>ini_get('memory_limit'),'session_upload_progress_enabled'=>ini_get('session.upload_progress.enabled'),
    'session_serialize_handler'=>ini_get('session.serialize_handler')));`;
  const runtime = JSON.parse(await capture('php-runtime', php, ['-r', runtimeCode], privateRoot, env, 10000, 65536));
  assert.equal(runtime.version, '8.5.12');
  assert.equal(runtime.version_id, 80512);
  assert.equal(runtime.sapi, 'cli');
  assert.equal(runtime.os, 'Linux');
  const composerVersion = await capture('composer-version', composer, ['--version', '--no-ansi'], privateRoot, env, 10000, 65536);
  assert.match(composerVersion, /Composer version 2\.11\.0\b/);
  const binaryHashes = {php: sha(php), composer: sha(composer), node: sha(process.execPath)};
  save('runtime.json', {php: runtime, binaries_sha256: binaryHashes, node_version: process.version,
    node_arch: process.arch, kernel: os.release(), logical_cpus_observed: os.availableParallelism(),
    memory_total_bytes_observed: os.totalmem(), composer_version_line: composerVersion.split('\n')[0],
    resource_scope: 'Hosted-runner snapshot; no Docker 512MiB/2CPU equivalence or overhead claim'});

  async function verifyTag(profile, label) {
    const refs = (await capture(label, '/usr/bin/git', ['-c', 'credential.helper=',
      '-c', 'http.extraHeader=', 'ls-remote', '--exit-code', profile.repository,
      `refs/tags/${profile.tag}`, `refs/tags/${profile.tag}^{}`], privateRoot, env, 20000, 65536))
      .trim().split('\n').map(line => line.trim().split(/\s+/));
    assert.ok(refs.some(([sha, ref]) => sha === profile.head && ref === `refs/tags/${profile.tag}^{}`));
    assert.ok(refs.some(([sha, ref]) => sha === profile.tag_object && ref === `refs/tags/${profile.tag}`));
  }
  const bindings = [];
  for (const profile of expected.profiles) {
    const fixtures = path.join(controls, 'fixtures', profile.kind);
    const cwd = path.join(privateRoot, profile.kind);
    fs.mkdirSync(cwd, {mode: 0o700});
    for (const name of ['composer.json', 'composer.lock']) {
      assert.equal(sha(path.join(fixtures, name)), profile.fixtures_sha256[name]);
      fs.copyFileSync(path.join(fixtures, name), path.join(cwd, name), fs.constants.COPYFILE_EXCL);
    }
    await verifyTag(profile, `${profile.kind}-tag-refs-before`);
    await capture(`${profile.kind}-install`, composer, ['install', '--no-dev', '--no-interaction', '--no-plugins',
      '--no-scripts', '--prefer-dist', '--no-progress', '--no-ansi'], cwd, env, 180000);
    for (const name of ['composer.json', 'composer.lock']) assert.equal(sha(path.join(cwd, name)), profile.fixtures_sha256[name]);
    await capture(`${profile.kind}-platform`, composer, ['--no-plugins', '--no-scripts', 'check-platform-reqs', '--no-dev'], cwd, env, 30000);
    const lock = JSON.parse(fs.readFileSync(path.join(cwd, 'composer.lock'), 'utf8'));
    const pkg = lock.packages.find(p => p.name === profile.package);
    assert.equal(pkg?.source?.reference ?? pkg?.dist?.reference, profile.head);
    const graph = packages => packages.map(p => ({name: p.name, version: p.version,
      reference: p.source?.reference ?? p.dist?.reference ?? null})).sort((a, b) => a.name.localeCompare(b.name));
    const installedMetadata = JSON.parse(fs.readFileSync(path.join(cwd, 'vendor/composer/installed.json'), 'utf8'));
    const installed = Array.isArray(installedMetadata) ? installedMetadata : installedMetadata.packages;
    assert.deepEqual(graph(installed), graph(lock.packages), 'Actual installed production graph must match frozen lock');
    for (const item of profile.checked_files) {
      assert.equal(sha(path.join(cwd, 'vendor', profile.package, item.file)), item.sha256);
    }
    bindings.push({kind: profile.kind, tag: profile.tag, head: profile.head, tag_object: profile.tag_object,
      own_source_files_verified: profile.checked_files.length,
      locked_graph: graph(lock.packages), installed_metadata_matches_lock: true,
      fixtures_sha256: profile.fixtures_sha256});
  }
  save('source-bindings.json', bindings);
  const startedCases = Date.now();
  for (const profile of expected.profiles) for (const count of [1, 10]) for (let repeat = 0; repeat < 3; repeat++) {
    assert.ok(Date.now() - startedCases < 300000, 'Normal-case total deadline');
    const name = `${profile.kind}-${count}-${repeat}`;
    const phaseFile = path.join(privateRoot, `${name}.phase`);
    const receipts = [];
    server = http.createServer((req, res) => {
      const chunks = [];
      let bytes = 0;
      req.on('data', chunk => {
        bytes += chunk.length;
        if (bytes > 4 * 1024 * 1024) { req.destroy(); return; }
        chunks.push(chunk);
      });
      req.on('end', () => {
        try {
          const body = Buffer.concat(chunks).toString();
          const json = JSON.parse(body);
          const spans = (json.resourceSpans ?? []).flatMap(r => (r.scopeSpans ?? []).flatMap(s => s.spans ?? []));
          const logs = (json.resourceLogs ?? []).flatMap(r => (r.scopeLogs ?? []).flatMap(s => s.logRecords ?? []));
          const metrics = (json.resourceMetrics ?? []).flatMap(r => (r.scopeMetrics ?? []).flatMap(s => s.metrics ?? []));
          receipts.push({path: req.url, phase: fs.readFileSync(phaseFile, 'utf8'), bytes, sha256: crypto.createHash('sha256').update(body).digest('hex'),
            spans: spans.length, logs: logs.length,
            operations: metrics.filter(m => m.name === 'audit.operations').flatMap(m => m.sum?.dataPoints ?? [])
              .reduce((n, p) => n + Number(p.asInt ?? p.asDouble ?? 0), 0),
            rc_version_present: body.includes(profile.version),
            secret_present: /SYNTHETIC_(TOKEN|PASSWORD)_CANARY|audit-canary@example\.test/.test(body)});
          res.setHeader('Content-Type', 'application/json');
          res.end('{}');
        } catch { res.writeHead(400); res.end(); }
      });
    });
    server.requestTimeout = 5000;
    server.headersTimeout = 5000;
    try {
      await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
      const stdout = await capture(name, php, [consumer, path.join(privateRoot, profile.kind), profile.kind,
        `http://127.0.0.1:${server.address().port}`, String(count), '-1', 'steady', '0', phaseFile],
        privateRoot, env, Math.min(20000, 300000 - (Date.now() - startedCases)), 65536, server);
      assert.ok(receipts.every(r => !r.secret_present));
      for (const [field, expected] of [['spans', count], ['logs', count], ['operations', count]]) {
        assert.equal(receipts.reduce((n, r) => n + r[field], 0), expected, `${name}:${field}`);
      }
      assert.ok(['/v1/traces', '/v1/metrics', '/v1/logs'].every(p => receipts.some(r => r.path === p && r.phase === 'forceFlush')));
      assert.ok(receipts.some(r => r.rc_version_present), `${name}: RC metadata`);
      const decoded = JSON.parse(stdout);
      assert.equal(decoded.runtime, '8.5.12');
      results.push({kind: profile.kind, head: profile.head, version: profile.version, count, repeat,
        consumer: decoded, receipts, pass: true});
      receipt.qualification_cases = results.length;
      console.log(JSON.stringify({case: name, pass: true, signals: receipts.map(r => r.path)}));
    } finally {
      server.closeAllConnections();
      if (server.listening) await new Promise(resolve => server.close(resolve));
      server = undefined;
    }
  }
  assert.equal(results.length, 12);
  for (const profile of expected.profiles) {
    const cwd = path.join(privateRoot, profile.kind);
    for (const name of ['composer.json', 'composer.lock']) assert.equal(sha(path.join(cwd, name)), profile.fixtures_sha256[name]);
    for (const item of profile.checked_files) assert.equal(sha(path.join(cwd, 'vendor', profile.package, item.file)), item.sha256);
    await verifyTag(profile, `${profile.kind}-tag-refs-after`);
  }
  assert.equal(sha(consumer), expected.consumer_sha256);
  assert.equal(sha(expectedFile), expectedSha);
  assert.deepEqual({php: sha(php), composer: sha(composer), node: sha(process.execPath)}, binaryHashes);
  assert.equal(aborted, false, 'Qualification interrupted/deadline');
  save('results.json', results);
  receipt.status = 'PASS_SCOPED_CLI_DELIVERY';
  receipt.unchanged_fixtures_source_and_runtime_binaries = true;
  receipt.tag_refs_rechecked_after = true;
}

try { await main(); }
catch (error) { receipt.error_type = error.name; process.exitCode = 1; console.error('Qualification failed; metadata receipt only, no raw diagnostics'); }
finally {
  clearTimeout(totalTimer);
  clearTimeout(globalEscalation);
  for (const state of [...owned]) {
    try { await settle(state); } catch { /* Retain the handle/private workspace and fail closed. */ }
  }
  server?.closeAllConnections();
  if (server?.listening) await new Promise(resolve => server.close(resolve));
  receipt.owned_groups_absent_and_reaped = owned.size === 0;
  receipt.collector_closed = !server?.listening;
  receipt.private_workspace_removed = false;
  if (owned.size === 0 && privateRoot) {
    try { fs.rmSync(privateRoot, {recursive: true, force: false}); receipt.private_workspace_removed = true; }
    catch { process.exitCode = 1; receipt.private_workspace_cleanup_failed = true; }
  }
  receipt.recovery_required = owned.size !== 0 || !!receipt.private_workspace_cleanup_failed;
  if (receipt.recovery_required) { receipt.status = 'FAILED_CLEANUP'; process.exitCode = 1; }
  process.removeListener('SIGTERM', interrupt);
  process.removeListener('SIGINT', interrupt);
  if (output) save('receipt.json', receipt);
}
