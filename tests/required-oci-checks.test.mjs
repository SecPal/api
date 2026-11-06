// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: CC0-1.0
import assert from "node:assert/strict";
import { test } from "node:test";
import { readFileSync } from "node:fs";
import { applicable, admit, validateFiles } from "../scripts/required-oci-checks.mjs";

const pr = { changed_files: 1 };
for (const path of [
  "app/Models/User.php",
  "Containerfile.php-fpm",
  "docker/nginx.conf",
  "composer.lock",
  ".github/workflows/container-image.yml",
  "scripts/container-publisher.py",
  "unknown-input",
]) {
  test(`${path} requires OCI qualification`, () =>
    assert.equal(applicable([{ filename: path }], pr), true));
}
test("trusted complete docs-only diff is not applicable", () =>
  assert.equal(applicable([{ filename: "README.md" }], pr), false));
test("renaming runtime input to documentation remains applicable", () =>
  assert.equal(
    applicable([{ filename: "README.md", previous_filename: "Containerfile" }], pr),
    true
  ));
for (const files of [
  [],
  [{ filename: "../README.md" }],
  [{ filename: "README.md" }, { filename: "CHANGELOG.md" }],
  [{}],
]) {
  test(`invalid or incomplete diff fails closed: ${JSON.stringify(files)}`, () =>
    assert.throws(() => validateFiles(files, pr)));
}
const names = [
  "Validate API build contract",
  "Native API (amd64)",
  "Native API (arm64)",
  "Qualify unpublished PHP-FPM and Nginx OCI artifacts",
];
const jobs = names.map((name) => ({ name, status: "completed", conclusion: "success" }));
test("complete green native runtime and pair qualification passes", () =>
  assert.equal(admit(jobs), true));
for (const conclusion of ["failure", "cancelled", "skipped", "neutral", "timed_out", null]) {
  test(`${conclusion} child is blocking`, () =>
    assert.throws(() => admit(jobs.map((job, i) => (i === 3 ? { ...job, conclusion } : job)))));
}
test("missing pair qualification is blocking", () => assert.throws(() => admit(jobs.slice(0, 3))));
test("legacy native builds alone do not qualify a pair", () =>
  assert.throws(() => admit(jobs.slice(0, 3))));
test("duplicate check identities are ambiguous and blocking", () =>
  assert.throws(() => admit([...jobs, jobs[0]])));

const { qualify } = await import("../scripts/required-oci-checks.mjs");
const head = "a".repeat(40);
const base = "b".repeat(40);
function fixture(change = {}) {
  const current = {
    state: "open",
    head: { sha: head },
    base: { sha: base, ref: "main" },
    changed_files: 1,
  };
  const run = {
    id: 5,
    run_attempt: 2,
    status: "completed",
    conclusion: "success",
    head_sha: head,
    event: "pull_request",
    path: ".github/workflows/oci-pr-gate.yml",
    repository: { full_name: "SecPal/api" },
    pull_requests: [{ number: 1, head: { sha: head }, base: { sha: base } }],
    ...change.run,
  };
  const seen = [];
  return {
    repository: "SecPal/api",
    number: 1,
    head,
    base,
    runId: 5,
    attempt: 2,
    request: async (path) => {
      seen.push(path);
      if (path.endsWith("/pulls/1")) return change.pr || current;
      if (path.includes("/files?")) return change.files || [{ filename: "Containerfile" }];
      if (path.includes("/runs?")) return { workflow_runs: change.runs || [run] };
      if (path.includes("/jobs?"))
        return {
          jobs: (change.jobs || jobs).map((job) => ({
            ...job,
            name: "Native OCI qualification / " + job.name,
          })),
          total_count: jobs.length,
        };
      if (path.endsWith("/runs/5")) return run;
      throw new Error(`Unexpected API call ${path}`);
    },
    seen,
  };
}
test("exact current head, base and latest attempt are required", async () => {
  const input = fixture();
  assert.match(await qualify(input), /PASS/);
  assert.ok(input.seen.some((path) => path.includes("/attempts/2/jobs")));
});
test("not applicable requires a complete native PR read-back", async () => {
  const input = fixture({ files: [{ filename: "README.md" }] });
  assert.match(await qualify(input), /NOT_APPLICABLE/);
  assert.equal(input.seen.filter((path) => path.endsWith("/pulls/1")).length, 2);
});
for (const [name, change] of [
  ["red pair", { jobs: jobs.map((job, i) => (i === 3 ? { ...job, conclusion: "failure" } : job)) }],
  ["wrong workflow", { run: { path: ".github/workflows/other.yml" } }],
  ["wrong attempt", { run: { run_attempt: 1 } }],
  ["foreign event", { run: { event: "workflow_dispatch" } }],
  [
    "stale base",
    { run: { pull_requests: [{ number: 1, head: { sha: head }, base: { sha: "c".repeat(40) } }] } },
  ],
  ["old source", { run: { head_sha: "c".repeat(40) } }],
  ["foreign repository", { run: { repository: { full_name: "attacker/api" } } }],
]) {
  test(`${name} cannot admit OCI artifacts`, async () => assert.rejects(qualify(fixture(change))));
}
test("scanner evidence failures propagated by pair qualification remain blocking", async () => {
  await assert.rejects(
    qualify(
      fixture({ jobs: jobs.map((job, i) => (i === 3 ? { ...job, conclusion: "failure" } : job)) })
    )
  );
});

test("application Markdown and image notices are runtime inputs", () => {
  for (const filename of ["resources/views/notice.md", "THIRD-PARTY-NOTICES.md", "unknown.md"]) {
    assert.equal(applicable([{ filename }], pr), true);
  }
});
test("required Pest tests cannot be skipped using a PR branch name", () => {
  assert.doesNotMatch(
    readFileSync(new URL("../.github/workflows/php-ci.yml", import.meta.url), "utf8"),
    /^\s*if:.*startsWith.*spike\//m
  );
});
