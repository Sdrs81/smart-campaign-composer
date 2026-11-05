#!/usr/bin/env node
// Tests for scripts/scrub-sessions.mjs: unit cases on scrub(), then the CLI on a sample file.
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { scrub } from './scrub-sessions.mjs';

const SCRIPT = resolve(dirname(fileURLToPath(import.meta.url)), 'scrub-sessions.mjs');

// Secret-shaped values are assembled at runtime so this file never contains one.
const anthropicKey = `sk-${'ant'}-${'a1B2c3D4'.repeat(4)}`;
const githubToken = `gh${'p'}_${'A1b2C3d4E5'.repeat(4)}`;
const bearerToken = `${'x9Y8z7W6'.repeat(4)}`;

let failures = 0;
let total = 0;
function check(label, ok, detail = '') {
  total++;
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? `  (${detail})` : ''}`);
}

// [label, input, expected output]
const masks = [
  ['Anthropic key', `key=${anthropicKey}`, 'key=[API_KEY]'],
  ['GitHub token', `token ${githubToken}`, 'token [API_KEY]'],
  ['bearer token', `Authorization: Bearer ${bearerToken}`, 'Authorization: Bearer [TOKEN]'],
  ['email', 'mail jane.doe+x@gmail.com now', 'mail [EMAIL] now'],
  ['phone, international', 'call +90 532 123 45 67 today', 'call [PHONE] today'],
  ['phone, Turkish national', 'call 0532 123 45 67 today', 'call [PHONE] today'],
  ['phone, US', 'call (555) 123-4567 or 555-123-4567', 'call [PHONE] or [PHONE]'],
  ['path, backslash', String.raw`C:\Users\jdoe\Desktop\app`, String.raw`C:\Users\[USER]\Desktop\app`],
  ['path, JSON-escaped', String.raw`C:\\Users\\jdoe\\Desktop`, String.raw`C:\\Users\\[USER]\\Desktop`],
  ['path, forward slash', 'C:/Users/jdoe/Desktop', 'C:/Users/[USER]/Desktop'],
  ['path, Git Bash', '/c/Users/jdoe/Desktop', '/c/Users/[USER]/Desktop'],
];
for (const [label, input, expected] of masks) {
  const { text } = scrub(input);
  check(`masks ${label}`, text === expected, text === expected ? '' : `got: ${text}`);
}

// Values that must stay as they are
const untouched = [
  ['noreply address', 'Co-Authored-By: Claude <noreply@anthropic.com>'],
  ['example.com fixture', 'user@example.com'],
  ['shared Windows folder', String.raw`C:\Users\Public\Documents`],
  ['diff statistics', '14 files changed, 1295 insertions(+), 1 deletion(-)'],
  ['added diff line with a date', ' 630 +2026-11-04 answered'],
  ['path placeholder', 'mask C:\\Users\\\u2026 paths'],
  ['timestamp and version', '2026-11-05T12:31:55.863Z react@18.3.1'],
  ['placeholder text', '[API_KEY] [EMAIL] [PHONE] [USER] Bearer [TOKEN]'],
  ['plain prose', 'Nothing sensitive here.'],
];
for (const [label, input] of untouched) {
  const { text, findings } = scrub(input);
  check(`keeps ${label}`, text === input && findings.length === 0, text === input ? '' : `got: ${text}`);
}

const mixed = `first line\nmail a@b.org\n${anthropicKey}\n`;
const once = scrub(mixed);
check('reports rule and line', once.findings.map((f) => `${f.rule}:${f.line}`).join() === 'api-key:3,email:2');
check('scrubbing twice changes nothing', scrub(once.text).text === once.text);
check('findings never contain the value', !JSON.stringify(once.findings).includes(anthropicKey));

// CLI on a sample file
const dir = mkdtempSync(join(tmpdir(), 'scrub-test-'));
try {
  const sample = join(dir, 'sample.md');
  writeFileSync(sample, `# Session\n\nUser jane@gmail.com used ${anthropicKey}\nin C:\\Users\\jdoe\\work\n`);
  const run = (...args) => spawnSync(process.execPath, [SCRIPT, ...args], { encoding: 'utf8' });

  const dirty = run('--check', sample);
  check('--check exits 1 on a dirty file', dirty.status === 1, `status=${dirty.status}`);
  check('--check does not echo the secret', !dirty.stdout.includes(anthropicKey) && !dirty.stdout.includes('jane@'));
  check('--check does not modify the file', readFileSync(sample, 'utf8').includes(anthropicKey));

  const fixed = run(sample);
  check('scrub exits 0', fixed.status === 0, `status=${fixed.status}`);
  const result = readFileSync(sample, 'utf8');
  check('scrub removed every value', !result.includes(anthropicKey) && !result.includes('jane@') && !result.includes('jdoe'));

  const clean = run('--check', sample);
  check('--check exits 0 afterwards', clean.status === 0, `status=${clean.status}`);

  const missing = run('--check', join(dir, 'missing.md'));
  check('missing file exits 2', missing.status === 2, `status=${missing.status}`);
} finally {
  rmSync(dir, { recursive: true, force: true });
}

console.log(`\n${total - failures}/${total} passed`);
process.exit(failures === 0 ? 0 : 1);
