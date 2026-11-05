#!/usr/bin/env node
// Tests for .claude/hooks/format.mjs. Prettier is replaced by a stub in a temp project
// so the test needs no network and no installed formatter.
import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { formatFile } from '../.claude/hooks/format.mjs';

const HOOK_PATH = resolve(dirname(fileURLToPath(import.meta.url)), '../.claude/hooks/format.mjs');

// Stand-in for `prettier --write <file>`: strips indentation, fails on a marker string.
const PRETTIER_STUB = `
const fs = require('node:fs');
const file = process.argv[process.argv.length - 1];
const text = fs.readFileSync(file, 'utf8');
if (text.includes('SYNTAX_ERROR')) {
  console.error('[error] ' + file + ': SyntaxError: unexpected token');
  process.exit(2);
}
fs.writeFileSync(file, text.replace(/^[ \\t]+/gm, ''));
`;
const MESSY = '# Title\n\n    - indented\n      - more\n';
const CLEAN = '# Title\n\n- indented\n- more\n';

function makeProject({ withPrettier }) {
  const dir = mkdtempSync(join(tmpdir(), 'format-test-'));
  mkdirSync(join(dir, 'docs'), { recursive: true });
  mkdirSync(join(dir, 'frontend', 'src'), { recursive: true });
  if (withPrettier) {
    const bin = join(dir, 'frontend', 'node_modules', 'prettier', 'bin');
    mkdirSync(bin, { recursive: true });
    writeFileSync(join(bin, 'prettier.cjs'), PRETTIER_STUB);
  }
  return dir;
}

function put(dir, relativePath, content) {
  const path = join(dir, relativePath);
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, content);
  return path;
}

let failures = 0;
let total = 0;
function check(label, ok, detail = '') {
  total++;
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? `  (${detail})` : ''}`);
}

const withTool = makeProject({ withPrettier: true });
const withoutTool = makeProject({ withPrettier: false });
try {
  // Direct calls to formatFile
  const rootMarkdown = put(withTool, 'docs/note.md', MESSY);
  let result = formatFile(rootMarkdown, withTool);
  check('.md outside frontend uses the frontend Prettier', result?.status === 'FORMATTED', result?.status);
  check('.md content is fixed', readFileSync(rootMarkdown, 'utf8') === CLEAN);

  const script = put(withTool, 'frontend/src/app.ts', '    const a = 1;\n');
  result = formatFile(script, withTool);
  check('.ts inside frontend is formatted', result?.status === 'FORMATTED', result?.status);
  check('.ts content is fixed', readFileSync(script, 'utf8') === 'const a = 1;\n');

  const messyJson = put(withTool, 'package.json', '   {"a": 1}\n');
  result = formatFile(messyJson, withTool);
  check('.json is formatted', result?.status === 'FORMATTED', result?.status);

  const noTool = put(withoutTool, 'docs/note.md', MESSY);
  result = formatFile(noTool, withoutTool);
  check('Prettier missing: SKIP, no error', result?.status === 'SKIP' && /not installed/.test(result.detail));
  check('Prettier missing: file untouched', readFileSync(noTool, 'utf8') === MESSY);

  const php = put(withoutTool, 'backend/app/Foo.php', '<?php  class Foo {}\n');
  result = formatFile(php, withoutTool);
  check('Pint missing: SKIP', result?.status === 'SKIP' && /Pint/.test(result.detail), result?.detail);

  const broken = put(withTool, 'docs/broken.md', 'SYNTAX_ERROR\n    x\n');
  result = formatFile(broken, withTool);
  check('formatter error: FAILED with message', result?.status === 'FAILED' && /SyntaxError/.test(result.detail), result?.detail);
  check('formatter error: file untouched', readFileSync(broken, 'utf8') === 'SYNTAX_ERROR\n    x\n');

  check('unsupported extension is ignored', formatFile(put(withTool, 'docs/a.txt', '  x\n'), withTool) === null);
  check(
    'node_modules is ignored',
    formatFile(put(withTool, 'frontend/node_modules/pkg/index.js', '  x\n'), withTool) === null,
  );
  check('missing file: SKIP', formatFile(join(withTool, 'docs/ghost.md'), withTool)?.status === 'SKIP');

  // End to end: the hook process as Claude Code runs it
  const logPath = join(withTool, '.claude', 'logs', 'hooks.log');
  const run = (payload, raw) =>
    spawnSync(process.execPath, [HOOK_PATH], {
      input: raw ?? JSON.stringify(payload),
      encoding: 'utf8',
      env: { ...process.env, CLAUDE_PROJECT_DIR: withTool },
    });

  const e2eFile = put(withTool, 'docs/e2e.md', MESSY);
  let run1 = run({ tool_name: 'Write', cwd: withTool, tool_input: { file_path: e2eFile } });
  check('e2e: exits 0 and fixes the file', run1.status === 0 && readFileSync(e2eFile, 'utf8') === CLEAN);
  const log = readFileSync(logPath, 'utf8');
  check('e2e: hooks.log has a FORMATTED line', /\tformat\tFORMATTED\tdocs[\\/]e2e\.md\tprettier/.test(log));

  run1 = run({ tool_name: 'Edit', cwd: withTool, tool_input: { file_path: broken } });
  check('e2e: formatter error still exits 0', run1.status === 0, `status=${run1.status}`);
  check('e2e: error is logged as FAILED', /\tformat\tFAILED\tdocs[\\/]broken\.md/.test(readFileSync(logPath, 'utf8')));

  run1 = run(null, 'not json');
  check('e2e: invalid input exits 0', run1.status === 0, `status=${run1.status}`);
  run1 = run({ tool_name: 'Write', cwd: withTool, tool_input: {} });
  check('e2e: input without file_path exits 0', run1.status === 0);
} finally {
  rmSync(withTool, { recursive: true, force: true });
  rmSync(withoutTool, { recursive: true, force: true });
}

console.log(`\n${total - failures}/${total} passed`);
process.exit(failures === 0 ? 0 : 1);
