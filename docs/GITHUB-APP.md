# Connecting GitHub

Jogger talks to GitHub through a **GitHub App** that you own. The app lets Jogger register runners and tells it when a job is queued. One Jogger instance uses one app; the app can be installed on several organizations or accounts.

You need to be a Jogger administrator, and an owner of the GitHub organization (or the account) the app will belong to.

## Before you start

`APP_URL` must be the public HTTPS address of your instance. GitHub sends job events to `APP_URL/webhooks/github` and only delivers to HTTPS addresses it can reach. The GitHub page in Jogger warns you when `APP_URL` is not HTTPS or looks private.

## Option 1: create the app from Jogger (recommended)

1. In Jogger, open **GitHub** in the sidebar.
2. Choose whether the app belongs to your personal account or to an organization (enter its login), and optionally a name.
3. Select **Create GitHub App**. GitHub shows a confirmation page with everything prefilled; confirm it.
4. GitHub sends you back to Jogger, which stores the app's credentials (encrypted).
5. Select **Install** and pick the repositories, or all of them, that should be able to use your runners.

The installation then appears under **Installations**, with its repositories.

## Option 2: connect an app you created yourself

Use this when your organization restricts app creation, or on GitHub Enterprise Server where the automatic flow is blocked.

Create a GitHub App in GitHub (**Settings → Developer settings → GitHub Apps → New GitHub App**) with:

| Setting | Value |
|---|---|
| Webhook URL | `APP_URL/webhooks/github` |
| Webhook secret | a long random string (you enter the same one in Jogger) |
| Callback URL | `APP_URL/auth/github/callback` (only needed for "Sign in with GitHub") |
| Repository permission: Actions | Read-only |
| Repository permission: Administration | Read and write |
| Repository permission: Metadata | Read-only |
| Organization permission: Self-hosted runners | Read and write |
| Subscribe to events | Workflow job, Repository |

Generate a private key for the app and download the `.pem` file. Then in Jogger, on the **GitHub** page, choose to connect an existing app and enter the App ID, Client ID, Client secret, Webhook secret and the contents of the private key. Jogger checks the credentials with GitHub before saving them. Finally install the app on your account or organization.

### Why these permissions

- **Administration (write)** on repositories and **Self-hosted runners (write)** on organizations are what GitHub requires to register and remove runners.
- **Actions (read)** lets Jogger read job state and receive the Workflow job event.
- Jogger never reads your code, and never receives your workflow secrets: those stay in GitHub and are sent by GitHub straight to the runner.

## Checking that it works

- **GitHub → Deliveries** lists every webhook received, with its outcome. A `ping` shortly after creating the app means GitHub can reach you. A failed delivery can be replayed from there.
- If nothing arrives, look at the app's **Advanced → Recent Deliveries** page on GitHub for the error GitHub got.

## Using the runners in a workflow

Once a host is online and a pool exists (see [POOLS.md](POOLS.md)), target the pool by its labels:

```yaml
jobs:
  build:
    runs-on: [self-hosted, linux, x64]
    steps:
      - uses: actions/checkout@v4
      - run: echo "Running on Jogger"
```

A job runs on a pool when **every** label in `runs-on` is in the pool's label list.

## GitHub Enterprise Server

Set `JOGGER_GITHUB_WEB_URL` (for example `https://github.example.com`) and `JOGGER_GITHUB_API_URL` (`https://github.example.com/api/v3`). This configuration has not been tested against a real Enterprise Server.
