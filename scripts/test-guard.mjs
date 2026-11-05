#!/usr/bin/env node
// Tests for .claude/hooks/guard.mjs: table-driven cases against evaluate(), then a few
// end-to-end runs of the real hook process (exit code, stderr, log line).
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { evaluate } from '../.claude/hooks/guard.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const GUARD_PATH = join(ROOT, '.claude/hooks/guard.mjs');
const SETTINGS_PATH = join(ROOT, '.claude/settings.json');
// guard.mjs: branch lookup + staged diff + unstaged diff, each capped at 5 s (GIT_TIMEOUT_MS).
const GUARD_WORST_CASE_SECONDS = 15;
// format.mjs: one formatter run capped at 30 s (FORMATTER_TIMEOUT_MS).
const FORMAT_WORST_CASE_SECONDS = 30;
// Built at runtime so this file itself never contains a key-shaped string.
const FAKE_KEY = `sk-${'ant'}-${'a1B2c3D4'.repeat(4)}`;

const BLOCK = 'block';
const ALLOW = 'allow';

/** @type {{name: string, shell: 'bash'|'powershell', command: string, expect: string, rule?: string, branch?: string|null, secret?: boolean|null}[]} */
const cases = [
  // Recursive deletes
  { name: 'rm -rf', shell: 'bash', command: 'rm -rf node_modules', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'rm with separate -r -f', shell: 'bash', command: 'rm -f -r build', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'rm -fR behind sudo', shell: 'bash', command: 'sudo rm -fR /tmp/x', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'rm -rf inside bash -c', shell: 'bash', command: 'bash -c "rm -rf build"', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'rm -rf inside docker exec', shell: 'bash', command: 'docker compose exec app rm -rf /var/www/html', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'find -delete', shell: 'bash', command: 'find . -name "*.log" -delete', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'Remove-Item -Recurse', shell: 'powershell', command: 'Remove-Item -Recurse -Force build', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'Remove-Item -r abbreviation', shell: 'powershell', command: 'Remove-Item -r build', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'rd /s /q', shell: 'powershell', command: 'rd /s /q build', expect: BLOCK, rule: 'recursive-delete' },
  { name: 'rm of a single file', shell: 'bash', command: 'rm notes.txt', expect: ALLOW },
  { name: 'rm -f of a single file', shell: 'bash', command: 'rm -f notes.txt', expect: ALLOW },
  { name: 'Remove-Item -Force of a single file', shell: 'powershell', command: 'Remove-Item -Force notes.txt', expect: ALLOW },

  // Git: pushes and commits
  { name: 'push to main', shell: 'bash', command: 'git push origin main', expect: BLOCK, rule: 'push-target-main' },
  { name: 'push HEAD:main', shell: 'bash', command: 'git push origin HEAD:main', expect: BLOCK, rule: 'push-target-main' },
  { name: 'push main inside a chain', shell: 'bash', command: 'echo done && git push origin main', expect: BLOCK, rule: 'push-target-main' },
  { name: 'push while on main', shell: 'bash', command: 'git push', branch: 'main', expect: BLOCK, rule: 'push-from-main' },
  { name: 'force push', shell: 'bash', command: 'git push --force origin phase-1/setup', expect: BLOCK, rule: 'force-push' },
  { name: 'force push -f', shell: 'powershell', command: 'git push -f origin phase-1/setup', expect: BLOCK, rule: 'force-push' },
  { name: 'force push with +refspec', shell: 'bash', command: 'git push origin +phase-1/setup', expect: BLOCK, rule: 'force-push' },
  { name: 'commit on main', shell: 'bash', command: 'git commit -m "x"', branch: 'main', expect: BLOCK, rule: 'commit-on-main' },
  { name: 'switch to main then commit', shell: 'bash', command: 'git switch main && git commit -m "x"', expect: BLOCK, rule: 'commit-on-main' },
  { name: 'commit with unknown branch', shell: 'bash', command: 'git commit -m "x"', branch: null, expect: BLOCK, rule: 'unknown-branch' },
  { name: 'commit with key in staged diff', shell: 'bash', command: 'git commit -m "x"', secret: true, expect: BLOCK, rule: 'secret-in-commit' },
  { name: 'commit -am with key in unstaged diff', shell: 'bash', command: 'git commit -am "x"', secret: 'unstaged', expect: BLOCK, rule: 'secret-in-commit' },
  { name: 'commit when the diff is unreadable', shell: 'bash', command: 'git commit -m "x"', secret: null, expect: BLOCK, rule: 'unknown-diff' },
  { name: 'push a phase branch', shell: 'bash', command: 'git push -u origin phase-1/setup', expect: ALLOW },
  { name: 'push a branch whose name contains main', shell: 'bash', command: 'git push origin feature/main-fix', expect: ALLOW },
  { name: 'commit on a phase branch', shell: 'bash', command: 'git commit -m "chore: add guard [Phase 1]"', expect: ALLOW },
  { name: 'commit -am without a key', shell: 'powershell', command: 'git commit -am "x"', expect: ALLOW },
  { name: 'diff against main', shell: 'bash', command: 'git diff main...HEAD', expect: ALLOW },
  { name: 'switch to main then only read', shell: 'bash', command: 'git switch main && git log --oneline -3', expect: ALLOW },

  // Real env files
  { name: 'cat .env', shell: 'bash', command: 'cat .env', expect: BLOCK, rule: 'env-file' },
  { name: 'cat backend/.env', shell: 'bash', command: 'cat backend/.env', expect: BLOCK, rule: 'env-file' },
  { name: 'type .env', shell: 'powershell', command: 'type .env', expect: BLOCK, rule: 'env-file' },
  { name: 'Get-Content nested .env', shell: 'powershell', command: 'Get-Content .\\backend\\.env', expect: BLOCK, rule: 'env-file' },
  { name: 'Get-Content .env.local', shell: 'powershell', command: 'Get-Content .env.local', expect: BLOCK, rule: 'env-file' },
  { name: 'cp from .env', shell: 'bash', command: 'cp .env /tmp/leak', expect: BLOCK, rule: 'env-file' },
  { name: 'cp .env.example onto .env', shell: 'bash', command: 'cp .env.example .env', expect: BLOCK, rule: 'env-file' },
  { name: 'grep inside .env', shell: 'bash', command: 'grep KEY .env', expect: BLOCK, rule: 'env-file' },
  { name: 'source .env', shell: 'bash', command: 'source .env', expect: BLOCK, rule: 'env-file' },
  { name: 'redirect from .env', shell: 'bash', command: 'sort <.env', expect: BLOCK, rule: 'env-file' },
  { name: 'cat .env via docker exec', shell: 'bash', command: 'docker compose exec app cat .env', expect: BLOCK, rule: 'env-file' },
  { name: 'cat .env inside sh -c', shell: 'bash', command: 'docker compose exec app sh -c "cat /var/www/html/.env"', expect: BLOCK, rule: 'env-file' },
  { name: 'glob .e*', shell: 'bash', command: 'cat .e*', expect: BLOCK, rule: 'env-file' },
  { name: 'quote-split name', shell: 'bash', command: 'cat .e""nv', expect: BLOCK, rule: 'env-file' },
  { name: 'read via node fs', shell: 'bash', command: 'node -e "console.log(require(\'fs\').readFileSync(\'.env\', \'utf8\'))"', expect: BLOCK, rule: 'env-file' },
  { name: 'command substitution around a reader', shell: 'bash', command: 'cat $(echo .env)', expect: BLOCK, rule: 'unparseable' },
  { name: 'unbalanced quote around a reader', shell: 'bash', command: 'cat "unterminated', expect: BLOCK, rule: 'unparseable' },
  { name: 'recursive grep', shell: 'bash', command: 'grep -rn KEY .', expect: BLOCK, rule: 'recursive-search' },
  { name: 'rg without ignore rules', shell: 'bash', command: 'rg --no-ignore KEY', expect: BLOCK, rule: 'recursive-search' },
  { name: 'PowerShell recursive search', shell: 'powershell', command: 'Get-ChildItem -Recurse -Force | Select-String KEY', expect: BLOCK, rule: 'recursive-search' },
  { name: 'cat .env.example', shell: 'bash', command: 'cat .env.example', expect: ALLOW },
  { name: 'Get-Content .env.example', shell: 'powershell', command: 'Get-Content .env.example', expect: ALLOW },
  { name: 'git add .env.example', shell: 'bash', command: 'git add .env.example', expect: ALLOW },
  { name: 'git check-ignore .env', shell: 'bash', command: 'git check-ignore -v .env', expect: ALLOW },
  { name: 'grep inside .env.example', shell: 'bash', command: 'grep ANTHROPIC .env.example', expect: ALLOW },

  // Environment dumps and keys
  { name: 'printenv', shell: 'bash', command: 'printenv', expect: BLOCK, rule: 'env-dump' },
  { name: 'bare env', shell: 'bash', command: 'env | sort', expect: BLOCK, rule: 'env-dump' },
  { name: 'env in container', shell: 'bash', command: 'docker compose exec app env', expect: BLOCK, rule: 'env-dump' },
  { name: 'printenv in container', shell: 'bash', command: 'docker compose exec app printenv', expect: BLOCK, rule: 'env-dump' },
  { name: 'proc environ', shell: 'bash', command: 'docker compose exec app cat /proc/1/environ', expect: BLOCK, rule: 'env-dump' },
  { name: 'Get-ChildItem Env:', shell: 'powershell', command: 'Get-ChildItem Env:', expect: BLOCK, rule: 'env-dump' },
  { name: '$env: variable', shell: 'powershell', command: 'Write-Output $env:ANTHROPIC_API_KEY', expect: BLOCK, rule: 'env-dump' },
  { name: 'echo the key variable', shell: 'bash', command: 'echo $ANTHROPIC_API_KEY', expect: BLOCK, rule: 'env-dump' },
  { name: 'docker inspect', shell: 'bash', command: 'docker inspect app', expect: BLOCK, rule: 'env-dump' },
  { name: 'docker compose config', shell: 'bash', command: 'docker compose config', expect: BLOCK, rule: 'env-dump' },
  { name: 'key pasted into a command', shell: 'bash', command: `curl -H "x-api-key: ${FAKE_KEY}" http://localhost`, expect: BLOCK, rule: 'secret-in-command' },
  { name: 'env as a wrapper with a command', shell: 'bash', command: 'env FOO=1 node scripts/test-guard.mjs', expect: ALLOW },

  // Destructive docker
  { name: 'compose down -v', shell: 'bash', command: 'docker compose down -v', expect: BLOCK, rule: 'docker-destructive' },
  { name: 'compose down --volumes', shell: 'powershell', command: 'docker compose down --volumes', expect: BLOCK, rule: 'docker-destructive' },
  { name: 'docker volume rm', shell: 'bash', command: 'docker volume rm smart_pgdata', expect: BLOCK, rule: 'docker-destructive' },
  { name: 'docker system prune', shell: 'bash', command: 'docker system prune -af', expect: BLOCK, rule: 'docker-destructive' },
  { name: 'compose down without volumes', shell: 'bash', command: 'docker compose down', expect: ALLOW },
  { name: 'compose up', shell: 'bash', command: 'docker compose up -d', expect: ALLOW },
  { name: 'artisan test in container', shell: 'bash', command: 'docker compose exec app php artisan test', expect: ALLOW },

  // Fail closed
  { name: 'eval', shell: 'bash', command: 'eval "echo hi"', expect: BLOCK, rule: 'unparseable' },
  { name: 'Invoke-Expression', shell: 'powershell', command: 'Invoke-Expression "Get-Date"', expect: BLOCK, rule: 'unparseable' },
  { name: 'encoded PowerShell', shell: 'powershell', command: 'powershell -EncodedCommand ZQBjAGgAbwA=', expect: BLOCK, rule: 'unparseable' },
  { name: 'backtick around git', shell: 'powershell', command: 'git commit -m "`x`"', expect: BLOCK, rule: 'unparseable' },
  { name: 'non-string command', shell: 'bash', command: undefined, expect: BLOCK, rule: 'invalid-input' },

  // Everyday commands stay allowed
  { name: 'git status', shell: 'bash', command: 'git status --short', expect: ALLOW },
  { name: 'make test', shell: 'bash', command: 'make test', expect: ALLOW },
  { name: 'npm run lint', shell: 'powershell', command: 'npm run lint', expect: ALLOW },
  { name: 'php artisan llm:eval', shell: 'bash', command: 'php artisan llm:eval --only=example', expect: ALLOW },
  { name: 'type-check script', shell: 'bash', command: 'npm run type-check', expect: ALLOW },
  { name: 'curl health with query', shell: 'bash', command: 'curl http://localhost:8080/api/health?verbose=1', expect: ALLOW },
  { name: 'run this test script', shell: 'bash', command: 'node scripts/test-guard.mjs', expect: ALLOW },
  { name: 'ls', shell: 'powershell', command: 'ls -la', expect: ALLOW },
];

function contextFor(testCase) {
  const branch = testCase.branch === undefined ? 'phase-1/setup' : testCase.branch;
  const secret = testCase.secret === undefined ? false : testCase.secret;
  return {
    branch: () => branch,
    // `secret: 'unstaged'` models a key that only `git commit -a` would pick up.
    stagedSecret: (includeUnstaged) => (secret === 'unstaged' ? includeUnstaged : secret),
  };
}

let failures = 0;
let total = 0;
const report = (ok, label, detail = '') => {
  total++;
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? `  (${detail})` : ''}`);
};

for (const testCase of cases) {
  const verdict = evaluate(testCase.command, contextFor(testCase));
  const wantBlocked = testCase.expect === BLOCK;
  const ruleOk = !testCase.rule || verdict.rule === testCase.rule;
  const detail = verdict.blocked ? `rule=${verdict.rule}` : 'allowed';
  report(verdict.blocked === wantBlocked && ruleOk, `[${testCase.shell}] ${testCase.name}`, detail);
}

// End to end: run the real hook the way Claude Code does.
function runHook(projectDir, payload, rawInput) {
  return spawnSync(process.execPath, [GUARD_PATH], {
    input: rawInput ?? JSON.stringify(payload),
    encoding: 'utf8',
    env: { ...process.env, CLAUDE_PROJECT_DIR: projectDir },
  });
}

const projectDir = mkdtempSync(join(tmpdir(), 'guard-test-'));
const logPath = join(projectDir, '.claude', 'logs', 'hooks.log');
try {
  const push = runHook(projectDir, {
    tool_name: 'Bash',
    cwd: projectDir,
    tool_input: { command: 'git push origin main' },
  });
  report(push.status === 2, 'e2e: git push origin main exits 2', `status=${push.status}`);
  report(/push-target-main/.test(push.stderr), 'e2e: stderr names the rule');
  const logged = existsSync(logPath) ? readFileSync(logPath, 'utf8') : '';
  report(/\tBLOCK\tpush-target-main\tBash\tgit push origin main/.test(logged), 'e2e: hooks.log has a line');

  const status = runHook(projectDir, {
    tool_name: 'PowerShell',
    cwd: projectDir,
    tool_input: { command: 'git status' },
  });
  report(status.status === 0, 'e2e: git status exits 0', `status=${status.status}`);

  const otherTool = runHook(projectDir, { tool_name: 'Read', tool_input: { file_path: '.env' } });
  report(otherTool.status === 0, 'e2e: non-shell tools are ignored');

  const garbage = runHook(projectDir, null, 'not json');
  report(garbage.status === 2, 'e2e: invalid input fails closed', `status=${garbage.status}`);

  const keyed = runHook(projectDir, {
    tool_name: 'Bash',
    cwd: projectDir,
    tool_input: { command: `echo ${FAKE_KEY}` },
  });
  const loggedAfter = readFileSync(logPath, 'utf8');
  report(keyed.status === 2 && !loggedAfter.includes(FAKE_KEY), 'e2e: keys are masked in hooks.log');

  // An unexpected crash must block (exit 2); Node's default exit 1 would let the command run.
  const preload = join(projectDir, 'explode-preload.mjs');
  writeFileSync(
    preload,
    "JSON.parse = () => new Proxy({}, { get() { throw new Error('boom'); } });\n",
  );
  const crashed = spawnSync(process.execPath, ['--import', pathToFileURL(preload).href, GUARD_PATH], {
    input: JSON.stringify({ tool_name: 'Bash', cwd: projectDir, tool_input: { command: 'git status' } }),
    encoding: 'utf8',
    env: { ...process.env, CLAUDE_PROJECT_DIR: projectDir },
  });
  report(crashed.status === 2, 'e2e: unexpected crash fails closed', `status=${crashed.status}`);
  report(
    /\tBLOCK\tinternal-error\t/.test(readFileSync(logPath, 'utf8')),
    'e2e: unexpected crash is logged as internal-error',
  );
} finally {
  rmSync(projectDir, { recursive: true, force: true });
}

// settings.json: narrow matchers and timeouts above the hooks' own worst case.
const settings = JSON.parse(readFileSync(SETTINGS_PATH, 'utf8'));
const hookEntry = (event, matcher) => settings.hooks?.[event]?.find((entry) => entry.matcher === matcher);
const guardTimeout = hookEntry('PreToolUse', 'Bash|PowerShell')?.hooks?.[0]?.timeout;
report(
  guardTimeout >= GUARD_WORST_CASE_SECONDS,
  'settings: guard hook timeout covers its git calls',
  `timeout=${guardTimeout}s, needs >= ${GUARD_WORST_CASE_SECONDS}s`,
);
const formatTimeout = hookEntry('PostToolUse', 'Write|Edit')?.hooks?.[0]?.timeout;
report(
  formatTimeout >= FORMAT_WORST_CASE_SECONDS,
  'settings: format hook timeout covers the formatter',
  `timeout=${formatTimeout}s, needs >= ${FORMAT_WORST_CASE_SECONDS}s`,
);

console.log(`\n${total - failures}/${total} passed`);
process.exit(failures === 0 ? 0 : 1);
