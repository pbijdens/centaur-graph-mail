# Centaur Graph Mail

Sends every `wp_mail()` message through Microsoft Graph (`POST /users/{sender}/sendMail`) as one configured mailbox.

## How it works

1. **App credentials.** You enter the Tenant ID, Application (client) ID and secret of an Entra ID app registration. The secret is stored encrypted with the site's `wp-config.php` salts.
2. **Delegated consent.** An administrator clicks **Sign in with Microsoft** and consents to `Mail.Send`, `Mail.Send.Shared`, `User.Read` and `offline_access`. The Microsoft account they sign in with must be the sender mailbox, or have *Send As* / *Full Access* rights on it in Exchange Online.
3. **Sending.** The refresh token is stored encrypted and used to get access tokens, so mail also goes out from cron jobs and anonymous visitors. A daily cron job refreshes the token so it doesn't expire after 90 days of inactivity.

The plugin hooks `pre_wp_mail`, so it runs after the `wp_mail` filter, and it still fires `wp_mail_succeeded` and `wp_mail_failed`.

| wp_mail feature | Graph mapping |
|---|---|
| `to`, `Cc:`, `Bcc:` (with display names, quoted commas) | `toRecipients`, `ccRecipients`, `bccRecipients` |
| `Reply-To:` | `replyTo` |
| `From:` with a different address (e.g. a contact form visitor) | becomes `replyTo` when no Reply-To is set; the message is always sent from the configured mailbox |
| `Content-Type: text/html`, `wp_mail_content_type` filter | `body.contentType` HTML / Text |
| `charset`, `wp_mail_charset` filter | body and subject are converted to UTF-8 |
| `$attachments` (named keys supported) | `fileAttachment` |
| `$embeds` / `wp_mail_embed_args` | inline `fileAttachment` with `contentId` (`cid:`) |
| `X-Priority:`, `Importance:`, `Priority:` | `importance` |
| other `X-*` headers | `internetMessageHeaders` (Graph only accepts `X-` headers) |

**Limit:** Graph `sendMail` accepts requests up to 4 MB, which is about 3 MB of attachments. Larger messages fail with a clear error. Supporting them would need upload sessions and the `Mail.ReadWrite` permission.

## Entra ID app registration

1. Under **Authentication → Add a platform → Web**, add this redirect URI (it is also shown on the settings page):
   `https://<your-site>/wp-admin/admin-post.php`
   (`http://localhost:8080/wp-admin/admin-post.php` for local development)
2. Under **Certificates & secrets**, create a client secret and copy its *value*.
3. Under **API permissions**, add the *delegated* Microsoft Graph permissions `Mail.Send` and `Mail.Send.Shared`. If users can't consent themselves in your tenant, click *Grant admin consent*. The other permissions on the app (`Application.Read.All`, `Sites.*`, …) are not used by this plugin.
4. In Exchange Online, give the signing-in account rights on the sender mailbox, unless it is that mailbox:
   `Add-RecipientPermission -Identity sender@contoso.com -Trustee admin@contoso.com -AccessRights SendAs`

## Configuration in wp-config.php (optional)

Constants override the settings screen and lock the matching fields:

```php
define( 'CENTAUR_GRAPH_MAIL_TENANT_ID', '…' );
define( 'CENTAUR_GRAPH_MAIL_CLIENT_ID', '…' );
define( 'CENTAUR_GRAPH_MAIL_CLIENT_SECRET', '…' );
define( 'CENTAUR_GRAPH_MAIL_SENDER', 'noreply@contoso.com' );
```

Developers can change the request body with the `centaur_graph_mail_payload` filter.
