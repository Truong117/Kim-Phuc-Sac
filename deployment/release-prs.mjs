// API-only: never merge, cherry-pick, rebase or resolve conflicts.
export async function reconcile(github, repo, core) {
  const compare = async (base, head) => (await github.rest.repos.compareCommitsWithBasehead({ ...repo, basehead: `${base}...${head}` })).data;
  const pulls = (await github.rest.pulls.list({ ...repo, state: "open", per_page: 100 })).data;
  const release = await compare("main", "staging");
  if (release.ahead_by > 0 && release.files?.length > 0) {
    const existing = pulls.find((pr) => pr.base.ref === "main" && pr.head.ref === "staging" && pr.head.repo?.full_name === `${repo.owner}/${repo.repo}`);
    const block = `<!-- kps-cicd-status:start -->\nStaging candidate: \`${release.commits.at(-1)?.sha ?? "see staging branch"}\`. Production automatic deployment is disabled until the baseline, DB/files recovery and business smoke are verified. Release order: checks → DB/schema → reviewed migration gate → backend → frontend → smoke. Do not merge until the cutover gates pass.\n<!-- kps-cicd-status:end -->`;
    if (existing) {
      const previous = existing.body ?? "";
      const body = /<!-- kps-cicd-status:start -->[\s\S]*?<!-- kps-cicd-status:end -->/.test(previous)
        ? previous.replace(/<!-- kps-cicd-status:start -->[\s\S]*?<!-- kps-cicd-status:end -->/, block) : `${previous}\n\n${block}`;
      if (body !== previous) await github.rest.pulls.update({ ...repo, pull_number: existing.number, body });
    } else {
      await github.rest.pulls.create({ ...repo, base: "main", head: "staging", draft: true,
        title: "release: staging → main", body: block });
    }
  }
  const backport = await compare("staging", "main");
  if (backport.ahead_by > 0 && backport.files?.length > 0) {
    const main = (await github.rest.repos.getBranch({ ...repo, branch: "main" })).data.commit.sha;
    const branch = `hotfix/backport-main-${main.slice(0, 12)}`;
    if (!pulls.some((pr) => pr.head.ref === branch && pr.base.ref === "staging")) {
      try {
        await github.rest.git.getRef({ ...repo, ref: `heads/${branch}` });
      } catch (error) {
        if (error.status !== 404) throw error;
        await github.rest.git.createRef({ ...repo, ref: `refs/heads/${branch}`, sha: main });
      }
      await github.rest.pulls.create({ ...repo, base: "staging", head: branch, draft: true,
        title: `hotfix: backport main ${main.slice(0, 12)} to staging`,
        body: `Main contains changes absent from staging. Review this PR and any conflicts manually. No automatic conflict resolution or merge. Main SHA: \`${main}\`.` });
      core.info(`Backport PR prepared for ${main}.`);
    }
  }
}
