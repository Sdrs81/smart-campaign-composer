#!/usr/bin/env node
// PostToolUse formatter: .php -> Pint, .vue/.ts/.js/.mjs/.css/.json/.md -> Prettier.
// It never blocks: a missing tool or a formatter error is only logged to hooks.log,
// and the hook always exits 0. Before the backend or frontend exists there is nothing
// to run, so early tasks (settings, hooks, docs) pass through untouched.
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, extname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { writeHookLog } from './hook-log.mjs';

const FORMATTER_TIMEOUT_MS = 30_000;
const PRETTIER_EXTENSIONS = new Set(['.vue', '.ts', '.js', '.mjs', '.css', '.json', '.md']);
const PINT_EXTENSIONS = new Set(['.php']);
// Prettier 3 ships bin/prettier.cjs, Prettier 2 bin-prettier.js. Running them through
// node avoids the .cmd shims that need a shell on Windows.
const PRETTIER_BINS = ['node_modules/prettier/bin/prettier.cjs', 'node_modules/prettier/bin-prettier.js'];
const PINT_BIN = 'vendor/bin/pint';
const GENERATED_DIRS = new Set(['node_modules', 'vendor']);

/** True when `path` is `directory` or below it. `relative` ignores drive-letter case on Windows. */
function isInside(path, directory) {
  const fromDirectory = relative(directory, path);
  return !/^\.\.(?:[\\/]|$)/.test(fromDirectory) && !isAbsolute(fromDirectory);
}

/** Looks for `<dir>/<bin>` from `startDir` up to `projectDir`. */
function findTool(startDir, projectDir, bins) {
  let dir = startDir;
  while (isInside(dir, projectDir)) {
    for (const bin of bins) {
      if (existsSync(join(dir, bin))) return { root: dir, bin: join(dir, bin) };
    }
    const parent = dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }
  return null;
}

const firstLine = (text) => String(text ?? '').trim().split('\n')[0];

/** Formats one file and reports what happened; never throws. */
export function formatFile(file, projectDir) {
  const extension = extname(file).toLowerCase();
  const usesPint = PINT_EXTENSIONS.has(extension);
  if (!usesPint && !PRETTIER_EXTENSIONS.has(extension)) return null;
  // Files outside the project (memory notes, other repos) are never ours to rewrite.
  if (!isInside(file, projectDir)) return null;
  if (relative(projectDir, file).split(sep).some((part) => GENERATED_DIRS.has(part))) return null;
  if (!existsSync(file)) return { status: 'SKIP', detail: 'file not found' };

  const bins = usesPint ? [PINT_BIN] : PRETTIER_BINS;
  // The frontend dir is a fallback so root-level .md/.json can use frontend's Prettier.
  const tool =
    findTool(dirname(file), projectDir, bins) ??
    (usesPint ? null : findTool(join(projectDir, 'frontend'), projectDir, bins));
  if (!tool) return { status: 'SKIP', detail: `${usesPint ? 'Pint' : 'Prettier'} is not installed yet` };

  const [command, args] = usesPint
    ? ['php', [tool.bin, file]]
    : [process.execPath, [tool.bin, '--write', file]];
  try {
    execFileSync(command, args, {
      cwd: tool.root,
      timeout: FORMATTER_TIMEOUT_MS,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    return { status: 'FORMATTED', detail: usesPint ? 'pint' : 'prettier' };
  } catch (error) {
    if (error.code === 'ENOENT') return { status: 'SKIP', detail: `${command} not found` };
    return { status: 'FAILED', detail: firstLine(error.stderr) || firstLine(error.stdout) || error.message };
  }
}

const readStdin = () =>
  new Promise((resolveInput, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => (data += chunk));
    process.stdin.on('end', () => resolveInput(data));
    process.stdin.on('error', reject);
  });

async function main() {
  const input = JSON.parse(await readStdin());
  const filePath = input?.tool_input?.file_path;
  if (typeof filePath !== 'string') return;

  const cwd = input.cwd ?? process.cwd();
  const projectDir = resolve(process.env.CLAUDE_PROJECT_DIR ?? cwd);
  const file = isAbsolute(filePath) ? resolve(filePath) : resolve(cwd, filePath);

  const result = formatFile(file, projectDir);
  if (result) {
    writeHookLog(projectDir, 'format', result.status, relative(projectDir, file), result.detail);
  }
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  try {
    await main();
  } catch (error) {
    // A formatting problem must never block the edit that triggered it.
    process.stderr.write(`format: ${error.message}\n`);
  }
}
