#!/usr/bin/env node
// End-to-end smoke test (task 8.8): brief -> audience -> content -> approval -> wait for "completed",
// then compare the app's database with the mock provider's own counters. Needs `make up` first.
// The brief matches no fake-LLM keyword, so the generic scenario picks nearly all customers: enough
// load to exercise the rate limit, and the mock provider's fault injection to exercise retries.
import { execFile } from "node:child_process";
import { promisify } from "node:util";

const run = promisify(execFile);

const BASE_URL = process.env.SMOKE_BASE_URL ?? "http://localhost:8080";
const RATE_LIMIT_PER_SECOND = Number(process.env.SEND_RATE_PER_SECOND ?? 100);
const BRIEF = "Tum musterilere yeni yil kampanyasini duyur";
const CHANNELS = ["sms", "email"];

const POLL_INTERVAL_MS = 1000;
const PROGRESS_EVERY_POLLS = 5;
const COMPLETION_TIMEOUT_MS = 10 * 60 * 1000;
const MAX_OUTPUT_BYTES = 10 * 1024 * 1024;

// The stream ends with one of these (ContentEvent::COMPLETED and ::FAILED).
const EVENT_COMPLETED = "final";
const EVENT_FAILED = "error";

async function compose(...args) {
  const { stdout } = await run("docker", ["compose", ...args], {
    maxBuffer: MAX_OUTPUT_BYTES,
  });
  return stdout;
}

async function api(method, path, body) {
  const response = await fetch(`${BASE_URL}/api${path}`, {
    method,
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  if (!response.ok) {
    throw new Error(
      `${method} /api${path} answered ${response.status}: ${text}`,
    );
  }
  return JSON.parse(text).data;
}

// The mock provider is not published to the host, so its counters are read from inside the network.
async function providerRequest(method, path) {
  const code = `echo file_get_contents("http://mock-provider:8080${path}", false, stream_context_create(["http" => ["method" => "${method}"]]));`;
  return JSON.parse(await compose("exec", "-T", "app", "php", "-r", code));
}

// The sender's own peak is what proves the limit (K17): the provider's peak is capped by its own limit, so it
// would look fine even if we sent too fast. It lives in Redis across runs, so it is cleared with the provider stats.
async function resetSenderPeak() {
  await compose(
    "exec",
    "-T",
    "redis",
    "sh",
    "-c",
    "redis-cli --scan --pattern '*send:peak:*' | xargs -r redis-cli del",
  );
}

// NaN when the metric is missing, which fails the check instead of passing silently.
async function senderPeaks() {
  const text = await (await fetch(`${BASE_URL}/api/metrics`)).text();
  return Object.fromEntries(
    CHANNELS.map((channel) => [
      channel,
      Number(
        new RegExp(
          `^send_peak_per_second\\{channel="${channel}"\\} (\\d+)$`,
          "m",
        ).exec(text)?.[1],
      ),
    ]),
  );
}

async function query(sql) {
  const stdout = await compose(
    "exec",
    "-T",
    "postgres",
    "sh",
    "-c",
    'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tA -F "|" -c "$1"',
    "_",
    sql,
  );
  return stdout
    .trim()
    .split("\n")
    .filter(Boolean)
    .map((line) => line.split("|"));
}

// Reads the server-sent events until the content is done, so approval never races the text.
async function generateContent(campaignId) {
  const response = await fetch(
    `${BASE_URL}/api/campaigns/${campaignId}/content/stream`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "text/event-stream",
      },
      body: "{}",
    },
  );
  if (!response.ok) {
    throw new Error(
      `content stream answered ${response.status}: ${await response.text()}`,
    );
  }

  const decoder = new TextDecoder();
  let buffer = "";
  for await (const chunk of response.body) {
    buffer += decoder.decode(chunk, { stream: true });
    let end;
    while ((end = buffer.indexOf("\n\n")) !== -1) {
      const event = buffer.slice(0, end);
      buffer = buffer.slice(end + 2);
      const type = /^event: (.+)$/m.exec(event)?.[1];
      if (type === EVENT_COMPLETED) return;
      if (type === EVENT_FAILED)
        throw new Error(`content generation failed: ${event}`);
    }
  }
  throw new Error("the content stream ended without a final event");
}

async function deliveryCounts(campaignId) {
  const counts = Object.fromEntries(
    CHANNELS.map((channel) => [
      channel,
      { pending: 0, sending: 0, sent: 0, failed: 0, skipped: 0, attempts: 0 },
    ]),
  );
  const rows = await query(
    `SELECT channel, status, count(*), coalesce(sum(attempts), 0) FROM deliveries WHERE campaign_id = ${Number(campaignId)} GROUP BY channel, status`,
  );
  for (const [channel, status, count, attempts] of rows) {
    counts[channel][status] = Number(count);
    counts[channel].attempts += Number(attempts);
  }
  return counts;
}

const total = (counts) =>
  counts.pending +
  counts.sending +
  counts.sent +
  counts.failed +
  counts.skipped;

async function waitForCompletion(campaignId, startedAt) {
  for (let poll = 1; Date.now() - startedAt < COMPLETION_TIMEOUT_MS; poll++) {
    const campaign = await api("GET", `/campaigns/${campaignId}`);
    if (campaign.status === "completed") return;
    if (campaign.status === "failed")
      throw new Error("the campaign ended as failed");

    if (poll % PROGRESS_EVERY_POLLS === 0) {
      const counts = await deliveryCounts(campaignId);
      const line = CHANNELS.map(
        (channel) =>
          `${channel} ${counts[channel].sent + counts[channel].failed + counts[channel].skipped}/${total(counts[channel])}`,
      ).join("  ");
      console.log(
        `  ${Math.round((Date.now() - startedAt) / 1000)}s  ${campaign.status}  ${line}`,
      );
    }
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
  }
  throw new Error(
    `the campaign did not complete within ${COMPLETION_TIMEOUT_MS / 1000}s`,
  );
}

// Every check returns [name, passed, detail]; the report prints them all before the exit code decides.
function check(audience, counts, provider, senderPeak) {
  const checks = [];
  for (const channel of CHANNELS) {
    const c = counts[channel];
    const p = provider[channel];
    checks.push([
      `${channel}: deliveries = audience (${audience[channel]})`,
      total(c) === audience[channel],
      `${total(c)} rows`,
    ]);
    checks.push([
      `${channel}: nothing left pending or sending`,
      c.pending + c.sending === 0,
      `${c.pending} pending, ${c.sending} sending`,
    ]);
    checks.push([
      `${channel}: sent + failed + skipped = deliveries`,
      c.sent + c.failed + c.skipped === total(c),
      `${c.sent + c.failed + c.skipped} of ${total(c)}`,
    ]);
    // A failed row may still have been accepted: the provider can answer 500 after keeping the message, and
    // every retry can fail too. So the provider total lies between sent and sent + failed; above it means a
    // duplicate, below it a lost message.
    checks.push([
      `${channel}: sent <= provider total <= sent + failed (no duplicates, none lost)`,
      c.sent <= p.total && p.total <= c.sent + c.failed,
      `sent ${c.sent}, failed ${c.failed}, provider ${p.total}`,
    ]);
    checks.push([
      `${channel}: sender peak per second <= ${RATE_LIMIT_PER_SECOND}`,
      senderPeak[channel] <= RATE_LIMIT_PER_SECOND,
      `sender peak ${senderPeak[channel]}, provider peak ${p.peak_per_second} (info only)`,
    ]);
  }
  const retries = CHANNELS.reduce(
    (sum, channel) =>
      sum +
      counts[channel].attempts -
      counts[channel].sent -
      counts[channel].failed,
    0,
  );
  checks.push([
    "fault injection was exercised (retries happened)",
    retries > 0,
    `${retries} retried attempts; set MOCK_RATE_* above 0 if this is 0`,
  ]);

  return checks;
}

function printReport(audience, counts, provider, senderPeak, seconds, checks) {
  console.log(`\nCompleted in ${seconds}s\n`);
  console.log(
    "channel  audience  sent  failed  skipped  attempts  provider total  duplicates prevented  sender peak/s  provider peak/s  rate_limited (limit/injected)",
  );
  for (const channel of CHANNELS) {
    const c = counts[channel];
    const p = provider[channel];
    const limited = `${p.rate_limited.limit}/${p.rate_limited.injected}`;
    console.log(
      `${channel.padEnd(7)}  ${String(audience[channel]).padStart(8)}  ${String(c.sent).padStart(4)}  ${String(c.failed).padStart(6)}  ${String(c.skipped).padStart(7)}  ${String(c.attempts).padStart(8)}  ${String(p.total).padStart(14)}  ${String(p.duplicates_prevented).padStart(20)}  ${String(senderPeak[channel]).padStart(13)}  ${String(p.peak_per_second).padStart(15)}  ${limited.padStart(29)}`,
    );
  }
  console.log("");
  for (const [name, passed, detail] of checks) {
    console.log(`${passed ? "PASS" : "FAIL"}  ${name}  (${detail})`);
  }
}

async function main() {
  const health = await fetch(`${BASE_URL}/api/health`).catch(() => null);
  if (!health?.ok) {
    throw new Error(
      `the API at ${BASE_URL} is not healthy; start the stack with "make up" first`,
    );
  }

  // Start from zero so the provider's totals belong to this run only.
  await providerRequest("DELETE", "/stats");
  await resetSenderPeak();

  const startedAt = Date.now();
  const campaign = await api("POST", "/campaigns", { brief: BRIEF });
  console.log(`campaign ${campaign.id}: "${BRIEF}"`);

  const defined = await api("POST", `/campaigns/${campaign.id}/audience`);
  const audience = defined.audience_counts.channel_counts;
  console.log(
    `audience: ${CHANNELS.map((channel) => `${channel} ${audience[channel]}`).join(", ")}`,
  );

  await generateContent(campaign.id);
  console.log("content generated");

  await api("POST", `/campaigns/${campaign.id}/approve`);
  console.log("approved; waiting for completion");
  await waitForCompletion(campaign.id, startedAt);

  const counts = await deliveryCounts(campaign.id);
  const provider = await providerRequest("GET", "/stats");
  const senderPeak = await senderPeaks();
  const checks = check(audience, counts, provider, senderPeak);
  printReport(
    audience,
    counts,
    provider,
    senderPeak,
    Math.round((Date.now() - startedAt) / 1000),
    checks,
  );

  return checks.every(([, passed]) => passed);
}

main()
  .then((passed) => process.exit(passed ? 0 : 1))
  .catch((error) => {
    console.error(`smoke failed: ${error.message}`);
    process.exit(1);
  });
