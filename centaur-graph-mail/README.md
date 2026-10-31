# Centaur Graph Mail

Sends every `wp_mail()` message through Microsoft Graph (`POST /users/{sender}/sendMail`) as one configured mailbox, and shows Outlook calendars on the site with the **Outlook calendar** block or the `[centaur_calendar]` shortcode.

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
3. Under **API permissions**, add these *delegated* Microsoft Graph permissions (the settings page lists them too, with which ones the current sign-in was granted):

   | Permission | Used for |
   |---|---|
   | `User.Read` | profile of the signed-in account |
   | `Mail.Send` | sending as the signed-in account |
   | `Mail.Send.Shared` | sending as another (shared) mailbox |
   | `Calendars.Read` | reading the account's own calendars |
   | `Calendars.Read.Shared` | reading calendars of other users / shared mailboxes the account has access to |
   | `offline_access` | refresh token, so it works without anyone signed in |

   If users can't consent themselves in your tenant, click *Grant admin consent*. The other permissions on the app (`Application.Read.All`, `Sites.*`, …) are not used by this plugin. After adding a permission, click **Reconnect with Microsoft** on the settings page. Until you do, mail keeps working: token refreshes only ask for the scopes the existing sign-in was granted.
4. In Exchange Online, give the signing-in account rights on the sender mailbox, unless it is that mailbox:
   `Add-RecipientPermission -Identity sender@contoso.com -Trustee admin@contoso.com -AccessRights SendAs`

## Outlook calendar

Under **4. Outlook calendar** on the settings page:

- **Calendars**: tick one or more calendars of the signed-in account. Calendars of other users or shared mailboxes that don't show up can be added through **Other mailboxes** (one address per line).
- **Ignore appointments matching**: one regular expression per line, without delimiters, case-insensitive. An appointment is hidden when its title, description or any of its categories matches one of them.
- Private appointments are hidden by default; cancelled ones always are. Results are cached (15 minutes by default). If Graph is unreachable, the last successful result is shown for up to a week.

Place the **Outlook calendar** block (category *Widgets*) or the shortcode `[centaur_calendar]` on a page. `[centaur_calendar months="no"]`, or the block's *Show month calendars* toggle, leaves out the month grids. In the same way `location="no"`, `categories="no"` and `description="no"` (block toggles *Show location*, *Show categories*, *Show description*) leave out those parts of each appointment; all are shown by default.

The output covers today, the rest of this month and all of next month:

- Month grids for this month and next. Days with appointments are marked and link to that day in the list; past days are dimmed.
- A list of the days that have appointments, each with a heading and its appointments: all-day appointments first and highlighted (multi-day ones repeat on every day with "day n of m"), then timed appointments with start time, end time and title. Below the title come the location, the categories as tags, and the description in smaller text.
- Every category gets a colored marker, and its tag uses the same color with black or white text, whichever contrasts more. The color comes from a CRC32 hash of the category name, which picks one of 16 colors, so a category always has the same color. Change the palette with the `centaur_graph_mail_category_colors` filter.

Times use the site's time zone and time format.

## Configuration in wp-config.php (optional)

Constants override the settings screen and lock the matching fields:

```php
define( 'CENTAUR_GRAPH_MAIL_TENANT_ID', '…' );
define( 'CENTAUR_GRAPH_MAIL_CLIENT_ID', '…' );
define( 'CENTAUR_GRAPH_MAIL_CLIENT_SECRET', '…' );
define( 'CENTAUR_GRAPH_MAIL_SENDER', 'noreply@contoso.com' );
```

Developers can change the request body with the `centaur_graph_mail_payload` filter.
