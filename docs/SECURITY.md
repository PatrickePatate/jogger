# Security notes

How Jogger is meant to be run safely, and what it does not protect against. Report vulnerabilities privately to the maintainers (GitHub security advisory on the repository), not in a public issue.

## Trust boundaries

| Actor | Authenticates with | Can do |
|-------|--------------------|--------|
| Browser user | Session (password + optional 2FA, or GitHub login) | What their teams' roles allow; administrators everything |
| API client | Personal access token (hashed at rest, role-capped) | Same as its owner, capped by the token's role |
| GitHub | HMAC signature on each webhook (`X-Hub-Signature-256`) | Report job events |
| Host agent | Per-host credential (hashed at rest), revocable | Fetch its own commands, report its own runners and logs |
| Prometheus | `JOGGER_METRICS_TOKEN` | Read aggregate metrics |

Webhook and agent endpoints carry no session and refuse requests a browser sends from another origin. All browser pages are served with a nonce-based Content-Security-Policy, `X-Frame-Options: DENY`, `nosniff`, and HSTS over HTTPS. Alpine.js needs `'unsafe-eval'` in `script-src`; inline scripts still need the nonce. Set `JOGGER_CSP_REPORT_ONLY=true` temporarily if a page breaks and report it.

## Jobs run untrusted code

A workflow job is arbitrary code chosen by whoever can push to (or open a pull request against) the repository. Jogger's isolation is a container per job: non-root user, all capabilities dropped, `no-new-privileges`, a private network per runner, CPU / memory / PID limits, and the host's Docker socket is never mounted. **A container is not a security boundary against a determined attacker** (kernel exploits escape it).

- **Public repositories**: anyone can make your hosts run code through a pull request. Jogger asks for explicit confirmation when a pool serves a public repository. Prefer GitHub-hosted runners for them, or dedicate a host with nothing else on it and require approval for outside contributors' workflows in GitHub.
- **Docker inside jobs (`dind`)**: the rootless Docker-in-Docker sidecar must run privileged. It holds no secrets and dies with the runner, but a job using it is closer to the host kernel. Enable it only for pools whose repositories you trust.
- **Tool cache volumes** are shared by all jobs of a pool on a host: one job can poison a toolchain a later job uses. Do not enable them on pools that mix trusted and untrusted repositories.
- Put hosts that run jobs on a network segment that cannot reach your internal services.

## Secrets

- The GitHub App key, secrets, registry credentials, webhook signing secrets and 2FA seeds are encrypted with `APP_KEY`. **Back up `APP_KEY`**: without it they are unrecoverable.
- Secrets are injected as environment variables when a runner starts and are masked in logs by exact value (and base64 form). A job can still print a secret transformed (reversed, split) and defeat masking, as on GitHub.
- While a `runner.start` command waits for its host, its secrets sit in the `host_commands` table unencrypted; they are removed once the host acknowledges. Protect database access and backups accordingly.
- Rotate the key with `php artisan jogger:rotate-key` (prints the new key; `--write` updates `.env`). It re-encrypts every encrypted column in one transaction, clears the cache and signs everyone out. Restart all containers with the new key immediately afterwards.

## Operations

- Serve Jogger over HTTPS only; agents send credentials and receive secrets over that connection.
- Revoke a host as soon as it is decommissioned or suspected compromised: its credential stops working at once.
- Keep `JOGGER_METRICS_TOKEN` set or unset: unset disables `/metrics` entirely.
