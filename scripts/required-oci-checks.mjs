// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: CC0-1.0
import { readFileSync, appendFileSync } from "node:fs";
import { pathToFileURL } from "node:url";

const nonRuntimeAutomation = new Set([
  ".github/workflows/oci-pr-gate.yml",
  ".github/workflows/php-ci.yml",
  ".github/workflows/reusable-repository-security.yml",
  ".github/workflows/dependabot-auto-merge.yml",
  ".github/dependabot.yml",
  "scripts/required-oci-checks.mjs",
  "tests/required-oci-checks.test.mjs",
]);
export function validateFiles(files, pr) {
  const canonical = (path) =>
    typeof path === "string" &&
    path.length > 0 &&
    !path.includes("\\") &&
    !path.split("/").some((part) => ["", ".", ".."].includes(part));
  if (
    !Array.isArray(files) ||
    !files.length ||
    files.length !== pr.changed_files ||
    files.length >= 3000 ||
    !files.every(
      (file) =>
        canonical(file.filename) && (!file.previous_filename || canonical(file.previous_filename))
    )
  ) {
    throw new Error("Incomplete or invalid GitHub PR file inventory");
  }
}
export function applicable(files, pr) {
  validateFiles(files, pr);
  // Everything except documentation and this admission-only implementation is
  // an OCI input. Renames retain both paths. Unknown inputs require qualification.
  const documentation = (path) =>
    [
      "README.md",
      "CHANGELOG.md",
      "AGENTS.md",
      "CONTRIBUTING.md",
      "CODE_OF_CONDUCT.md",
      "SECURITY.md",
    ].includes(path) ||
    (path.startsWith("docs/") && path.endsWith(".md") && path !== "docs/ADDRESS_DATA.md");
  const relevant = (path) => !documentation(path) && !nonRuntimeAutomation.has(path);
  return files.some(
    (file) =>
      relevant(file.filename) || (file.previous_filename && relevant(file.previous_filename))
  );
}
export function admit(jobs) {
  for (const name of [
    "Validate API build contract",
    "Native API (amd64)",
    "Native API (arm64)",
    "Qualify unpublished PHP-FPM and Nginx OCI artifacts",
  ]) {
    const matches = jobs.filter((job) => job.name === name);
    if (
      matches.length !== 1 ||
      matches[0].status !== "completed" ||
      matches[0].conclusion !== "success"
    ) {
      throw new Error(`Required OCI job is missing, ambiguous or unsuccessful: ${name}`);
    }
  }
  return true;
}
export async function qualify({
  repository,
  number,
  head,
  base,
  runId,
  attempt,
  request,
  scopeOnly = false,
}) {
  if (
    !/^[\w.-]+\/[\w.-]+$/.test(repository) ||
    !Number.isSafeInteger(number) ||
    number < 1 ||
    !/^[a-f0-9]{40}$/.test(head) ||
    !/^[a-f0-9]{40}$/.test(base)
  )
    throw new Error("Invalid PR identity");
  const root = `/repos/${repository}`;
  const readPR = () => request(`${root}/pulls/${number}`);
  const unchanged = (pr) => {
    if (
      pr.state !== "open" ||
      pr.head.sha !== head ||
      pr.base.sha !== base ||
      pr.base.ref !== "main"
    ) {
      throw new Error("PR source or base changed; require a fresh gate run");
    }
  };
  const pr = await readPR();
  unchanged(pr);
  const files = [];
  for (let page = 1; page <= 30; page++) {
    const batch = await request(`${root}/pulls/${number}/files?per_page=100&page=${page}`);
    files.push(...batch);
    if (batch.length < 100) break;
  }
  const required = applicable(files, pr);
  unchanged(await readPR());
  if (scopeOnly) return required;
  if (!required)
    return "NOT_APPLICABLE: complete GitHub diff contains no runtime or publisher inputs";
  if (!Number.isSafeInteger(runId) || runId < 1 || !Number.isSafeInteger(attempt) || attempt < 1) {
    throw new Error("Invalid native workflow attempt");
  }
  const run = await request(`${root}/actions/runs/${runId}`);
  if (
    run.head_sha !== head ||
    run.run_attempt !== attempt ||
    run.event !== "pull_request" ||
    run.path !== ".github/workflows/oci-pr-gate.yml" ||
    run.repository.full_name !== repository ||
    !run.pull_requests.some(
      (item) => item.number === number && item.head.sha === head && item.base.sha === base
    )
  ) {
    throw new Error("Native workflow identity does not match the exact PR head/base");
  }
  const jobs = [];
  for (let page = 1; ; page++) {
    const data = await request(
      `${root}/actions/runs/${runId}/attempts/${attempt}/jobs?per_page=100&page=${page}`
    );
    jobs.push(...data.jobs);
    if (jobs.length >= data.total_count) break;
    if (!data.jobs.length || page >= 100) throw new Error("Incomplete OCI job inventory");
  }
  const prefix = "Native OCI qualification / ";
  admit(
    jobs
      .filter((job) => job.name.startsWith(prefix))
      .map((job) => ({ ...job, name: job.name.slice(prefix.length) }))
  );
  unchanged(await readPR());
  return `PASS: OCI workflow ${runId}, attempt ${attempt}, source ${head}`;
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, "utf8"));
  if (process.env.GITHUB_EVENT_NAME !== "pull_request")
    throw new Error("Expected a pull_request event");
  const scopeOnly = process.argv[2] === "scope";
  if (!scopeOnly) {
    if (process.env.SCOPE_RESULT !== "success") throw new Error("Change detection did not succeed");
    const expected =
      process.env.RELEVANT === "true"
        ? "success"
        : process.env.RELEVANT === "false"
          ? "skipped"
          : undefined;
    if (!expected || process.env.NATIVE_RESULT !== expected)
      throw new Error("Native qualification did not succeed");
  }
  const request = async (path) => {
    const response = await fetch(`https://api.github.com${path}`, {
      headers: {
        Authorization: `Bearer ${process.env.GITHUB_TOKEN}`,
        Accept: "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
      },
      signal: AbortSignal.timeout(30000),
    });
    if (!response.ok) throw new Error(`GitHub evidence request failed: ${response.status}`);
    return response.json();
  };
  const result = await qualify({
    repository: process.env.GITHUB_REPOSITORY,
    number: event.number,
    head: event.pull_request.head.sha,
    base: event.pull_request.base.sha,
    request,
    scopeOnly,
    runId: Number(process.env.GITHUB_RUN_ID),
    attempt: Number(process.env.GITHUB_RUN_ATTEMPT),
  });
  if (scopeOnly) appendFileSync(process.env.GITHUB_OUTPUT, `required=${result}\n`);
  console.log(result);
}
