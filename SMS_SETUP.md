# SMS notifications (PhilSMS)

The system texts tenants through [PhilSMS](https://philsms.com). Until you connect an
account, nothing is sent: every message that *would* have gone out is listed under
**Admin → Settings → SMS Notifications → Recent SMS** as "Skipped", and everything else works
as normal.

## What gets texted

| When | Who | Message |
| --- | --- | --- |
| Admin adds a tenant | The new tenant | Welcome: room, move-in date, advance and deposit to pay, username and temporary password |
| Admin verifies a payment (or records the move-in payment) | The tenant who paid | Payment verified: amount, method, date, remaining balance |
| A payment covered a roommate's share | That roommate | Their share was paid, remaining balance |
| Admin rejects a payment | The tenant | Payment not approved, please check and resend |
| 3 days before the rent is due | Tenants who owe and aren't late yet | Reminder: monthly rent, due date, outstanding balance |
| On the due date | The same tenants, if still unpaid | The same reminder again |
| Rent is overdue | Tenants with a late balance, at most once a week | Overdue: amount and since when |
| Forgot password / admin resets a password | The tenant (or the admin's recovery number) | A reset code, or the new temporary password |
| Admin posts an announcement and ticks **Also text it** | All active tenants | The announcement |

The four main messages use the wording the owner asked for, for example:

```
RIVEROS BOARDING HOUSE

Hello, Ana Reyes! This is a reminder that your monthly rent of PHP 2,000.00 is due on October 28, 2026.

Outstanding Balance: PHP 2,000.00

Please submit your payment and upload your proof of payment through the system. Thank you!
```

The first line is the boarding house name from **Settings → Business Information**. Amounts
are written `PHP 2,000.00` instead of with the peso sign on purpose: a single `₱` makes the
whole text use the 70-characters-per-part SMS encoding instead of 160, so every message
would cost two to three times as many credits. The wording lives in
`includes/sms_templates.php`.

## 1. Get your PhilSMS API token

1. Sign in at <https://dashboard.philsms.com> (or register an account there first).
2. Load credits to your account. Each text uses credits, and a message longer than 160
   characters counts as two or more.
3. Open the **Developers** page of the PhilSMS dashboard and copy your **API token**. It's a
   long string of letters and numbers. Treat it like a password: anyone with it can send texts
   on your credits.

**Sender name:** texts show "PhilSMS" as the sender unless PhilSMS has approved a sender name
of your own (requested under **Sender ID** in their dashboard). Leave it as `PhilSMS` until
then; an unapproved name is rejected.

## 2. Connect it to the system

1. Log in as admin and go to **Settings**. The **SMS Notifications (PhilSMS)** card is at the
   bottom of the left column.
2. Paste the token into **PhilSMS API Token**, leave **Sender Name** as `PhilSMS`, and press
   **Save SMS Settings**. The card's badge changes to **Set up**.
3. Type your own mobile number under **Send a test SMS to** and press **Send Test**. The text
   should arrive within a minute.
4. **Check Credits** shows how many credits are left.

The token is never displayed again after saving: the box shows "Saved. Leave blank to keep it".
To replace it, paste a new one; to stop sending texts, tick **Remove the saved token**.

This works the same on your WAMP copy and on the deployed site. Each one keeps its own token,
because each has its own database. On Railway you can instead set the token as the environment
variable `PHILSMS_API_TOKEN` (and optionally `PHILSMS_SENDER_ID`); a token saved on the
Settings page takes priority over it.

## 3. Make sure tenants have mobile numbers

Texts go to each tenant's **Contact Number**. It has to be a Philippine mobile number. Any of
these formats work: `0917 123 4567`, `09171234567`, `+63 917 123 4567`. The add/edit tenant
forms and the tenant's own profile page now refuse anything else. Tenants added before this
update may have a missing or invalid number. They are shown in the reminders window, and
every text they miss is logged as "Skipped: Not a valid PH mobile number".

## 4. Rent reminders

**Automatically (nothing to set up):** every day from 8:00 AM, the system texts tenants whose
rent is due in 3 days, texts them again on the due date if it is still unpaid, and texts
overdue tenants once a week until they pay. The first page anyone opens after 8:00 AM (admin
or tenant) starts it; on Railway it runs in a separate process so nobody waits for the texts.
The admin gets a "Rent reminders sent automatically" notification, and **Settings → SMS
Notifications** shows when it last ran. Nobody ever gets the same reminder twice.

On a day when nobody opens the site at all, the reminders go out with the first visit after
that. If you want them to go out at 8:00 sharp no matter what, also schedule
`cron/daily.php` (it does the same thing and is safe to run in addition):

* **WAMP (Windows Task Scheduler):** Create Basic Task → Daily, 8:00 AM → Start a program:
  * Program: `C:\wamp64\bin\php\php8.x.x\php.exe` (the folder of the PHP version WAMP uses)
  * Arguments: `C:\wamp64\www\riveros-boardinghouse-management\cron\daily.php`
* **Railway:** add a second service from the same repo with the start command
  `php cron/daily.php` and a cron schedule of `0 0 * * *` (midnight UTC = 8:00 AM in Manila).
  Give it the same `DATABASE_URL` variable as the web service.

**By hand:** on **Payments**, press **Send Rent Reminders**. A window lists exactly who will be
texted and what it says; press **Send** to send them.

The script does nothing when opened in a browser.

## Troubleshooting

Every text, sent or not, is in **Settings → Recent SMS** with the reason it failed.

| Message | Fix |
| --- | --- |
| `The API token was not accepted` / `Unauthenticated` | Only part of the token was copied, or it was regenerated since. PhilSMS tokens look like `123\|AbCdEf...`; double-clicking selects only one half. Select the whole token (or use its copy button), paste it, save, and compare it with the **Saved token** hint under the box (first 6 and last 4 characters, and the length). |
| `Sender ID ... is not authorized` (or similar) | Set **Sender Name** back to `PhilSMS`, or wait for PhilSMS to approve yours. |
| A message about balance or credits | Load more credits in PhilSMS. |
| `HTTPS certificate check failed` | The app carries its own certificate list for WAMP (`includes/certs/cacert.pem`). Check that the file exists. If it does, PHP's `curl.cainfo` setting is pointing somewhere broken: clear it in WAMP's `php.ini` and restart WAMP. |
| `Could not reach PhilSMS` | No internet connection, or a firewall blocking `dashboard.philsms.com`. |
| `PhilSMS did not answer within 30 seconds` (log: **No reply**) | The request got through but PhilSMS never replied, so the text may still arrive: check the phone before resending. Then press **Check Credits**. If that works, PhilSMS was just slow to send. If it times out too, something on the computer or network is holding the connection: try turning off the antivirus "web shield"/HTTPS scanning or a VPN, or test from another network (e.g. a phone hotspot). |
| `Skipped: SMS is not set up yet` | No token is saved. See step 2. |
| `Skipped: Not a valid PH mobile number` | Fix the tenant's Contact Number. |
