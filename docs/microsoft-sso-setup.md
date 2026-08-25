# Microsoft Entra SSO setup

This guide walks an organization administrator through connecting their own Microsoft Entra ID (Azure AD) tenant to Rudaca Voice, so members can sign in with their Microsoft 365 account.

**Every organization — and every Rudaca installation — configures its own Microsoft app registration.** There is no shared or default app registration. If you are self-hosting Rudaca Voice, this also means each environment needs its own app registration, because the redirect URI (see step 4) is different for each one.

## Before you start

You'll need:

- **Global Administrator** or **Application Administrator** access to your organization's Microsoft Entra tenant.
- **Owner** or an authentication-management permission on the Rudaca Voice organization you're configuring.
- Rudaca Voice reachable over **HTTPS** in production. Microsoft Entra will accept an `http://localhost` redirect URI for local development, but will reject a non-HTTPS redirect URI for any other host.

## Setup steps

1. **Open the Microsoft Entra admin center** at [entra.microsoft.com](https://entra.microsoft.com), signed in as an admin for the tenant you want members to sign in with.

2. **Create a new app registration** — go to *Identity → Applications → App registrations → New registration*. Give it a recognizable name, e.g. "Rudaca Voice — Production".

3. **Configure supported account types as single-tenant** — select **"Accounts in this organizational directory only"**. Rudaca Voice pins each organization's sign-in to one tenant; a multi-tenant or personal-account app registration will not match what Rudaca Voice validates against, and the connection test will fail with a tenant mismatch.

4. **Add the Rudaca Voice redirect URI** — in Rudaca Voice, go to **Organization Settings → Authentication**, and copy the value shown under **Redirect URL** (there's a copy button next to it). In the app registration, go to *Authentication → Add a platform → Web*, and paste that exact URL into **Redirect URIs**. This URL is generated from your Rudaca Voice environment's URL and is not something you type in by hand — if you ever move to a different domain, the redirect URI shown in settings will change, and the app registration must be updated to match.

5. **Create a client secret, not a certificate** — go to *Certificates & secrets → Client secrets → New client secret*. Give it a description and an expiry. Certificates are not supported; only client secrets are.

6. **Copy the tenant ID (Directory/Tenant ID)** — found on the app registration's *Overview* page. This is a GUID that identifies your Microsoft Entra directory, not the application itself.

7. **Copy the application/client ID** — also on the *Overview* page, listed as **Application (client) ID**. This identifies the app registration, not the directory.

8. **Copy the client secret's *value* before leaving the secrets screen** — this is the single most common mistake. After creating a client secret, Microsoft Entra shows two columns: **Secret ID** and **Value**. You need the **Value**. It is only ever displayed once, immediately after creation — if you navigate away without copying it, you cannot retrieve it again and must create a new secret.

9. **Enter these values in Rudaca Voice** under **Organization Settings → Authentication**: tenant ID, client ID, and the client secret value from steps 6–8.

10. **Save configuration.**

11. **Run "Test Connection."** This starts a real Microsoft sign-in using the values you just saved, and confirms the tenant Microsoft returns actually matches the tenant ID you configured. A successful test records the date, time, and the administrator who ran it.

12. **Enable auto-provisioning only if desired** — this lets Microsoft accounts that don't already have a Rudaca Voice account get one created automatically on first sign-in, with a default role you choose. Leave it off if you'd rather add members manually first.

13. **Enable Microsoft-only enforcement only after a successful owner test** — Rudaca Voice will not let you turn on "Require Microsoft sign-in" until a connection test has succeeded for the currently saved configuration. This exists specifically to prevent an organization from locking itself out with bad credentials: if you change the tenant ID, client ID, or secret after testing, you'll need to re-run the test before enforcement can be (re-)enabled.

## Key terms, explained

- **Tenant ID vs. Application (client) ID** — the tenant ID identifies *your organization's Microsoft Entra directory*; the application ID identifies *this specific app registration* within that directory. Both are GUIDs and easy to mix up — they're both on the app registration's Overview page, right next to each other.
- **Client secret value vs. client secret ID** — the secret **ID** is just a label Microsoft Entra uses to identify which secret is which (useful for rotation); it is not a credential. The secret **value** is the actual credential Rudaca Voice needs. Only the value is ever entered into Rudaca Voice, and Microsoft Entra only shows it once.
- **Redirect URI** — the URL Microsoft redirects back to after a user signs in. It must match *exactly* what's registered in the app registration, and it is specific to the environment Rudaca Voice is running in (a different URL for `localhost`, staging, and production each need their own app registration or an additional redirect URI added to the same one).

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Redirect URI mismatch" or Microsoft shows `AADSTS50011` | The redirect URI in the app registration doesn't exactly match the one shown in Rudaca Voice's settings — check for a trailing slash or `http` vs `https` mismatch. |
| Connection test fails with a tenant mismatch | The app registration is multi-tenant, or the tenant ID entered in Rudaca Voice doesn't match the directory the app registration lives in. Re-check step 3 and step 6. |
| Microsoft shows a consent/permissions prompt or `AADSTS65001` | An admin hasn't granted consent for the app registration yet. Sign in once as a Global Administrator to grant tenant-wide consent, or ask your admin to do so from *Enterprise applications*. |
| Connection test fails with an authentication/credential error | The client secret has expired or was rotated in Microsoft Entra without updating Rudaca Voice. Create a new secret (step 5) and re-save the configuration in Rudaca Voice — this also requires re-running the connection test before enforcement can stay enabled. |
| "Configuration incomplete" status never clears | One of tenant ID, client ID, or client secret is still missing or blank. |

## Hosted vs. self-hosted deployments

- **Hosted (managed by Rudaca):** your organization still creates and owns its own app registration — Rudaca does not have a shared one on your behalf. The redirect URI shown in your settings will point at your hosted instance's domain.
- **Self-hosted:** the redirect URI is derived from whatever `APP_URL` your instance is configured with. If you run multiple environments (e.g. staging and production, or several customer instances), each one needs its own app registration, because each has its own redirect URI.
- **Production HTTPS is required.** Microsoft Entra will reject a non-`localhost` redirect URI that isn't HTTPS. Make sure `APP_URL` (and the certificate serving it) reflects your real production domain before registering the redirect URI.
