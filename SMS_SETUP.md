# SMS notifications (PhilSMS)

The system texts tenants through [PhilSMS](https://philsms.com). Until you connect an
account, nothing is sent: every message that *would* have gone out is listed under
**Admin → Settings → SMS Notifications → Recent SMS** as "Skipped", and everything else works
as normal.

## What gets texted

| When | Who | Example |
| --- | --- | --- |
| Admin verifies a payment | The tenant who paid | `RIVEROS BOARDING HOUSE: Your payment of PHP 2,000.00 has been VERIFIED. Receipt: RCP-000010. Thank you!` |
| A payment covered a roommate's share | That roommate | `... Your rent of PHP 1,000.00 was paid by Ana. Receipt: RCP-000011` |
| Admin rejects a payment | The tenant | `... Your payment submission of PHP 500.00 (ref 1234) was REJECTED. Please check it and submit again.` |
| Rent reminder: due within 3 days | Tenants who owe and aren't late yet | `... Hi Cara, your rent of PHP 4,000.00 is due today. Please pay via GCash ...` |
| Rent reminder: overdue | Tenants with a late balance, at most once a week | `... Hi Ben, you have an overdue rent balance of PHP 6,000.00 (due since Aug 5) ...` |
| Admin posts an announcement and ticks **Also text it** | All active tenants | `... Water interruption - No water on Saturday 8AM-12NN.` |

Messages start with the boarding house name from **Settings → Business Information**.

## 1. Get your PhilSMS API token

1. Sign in at <https://app.philsms.com> (or register an account there first).
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

**By hand:** on **Payments**, press **Send Rent Reminders**. A window lists exactly who will be
texted and what it says; press **Send** to send them. The number on the button is how many
are waiting. Nobody gets the same reminder twice, so pressing it again later is safe.

**Automatically (optional):** `cron/send_reminders.php` does the same thing from the command
line, so it can run every morning on a schedule.

* **WAMP (Windows Task Scheduler):** Create Basic Task → Daily, 8:00 AM → Start a program:
  * Program: `C:\wamp64\bin\php\php8.x.x\php.exe` (the folder of the PHP version WAMP uses)
  * Arguments: `C:\wamp64\www\riveros-boardinghouse-management\cron\send_reminders.php`

  The computer has to be on at that time. Run the same command once in Command Prompt first;
  it should print a line like `Rent reminders: 1 sent (0 due soon, 1 overdue) ...`.
* **Railway:** add a second service from the same repo with the start command
  `php cron/send_reminders.php` and a cron schedule of `0 0 * * *` (midnight UTC = 8:00 AM in
  Manila). Give it the same `DATABASE_URL` variable as the web service.

The script does nothing when opened in a browser.

## Troubleshooting

Every text, sent or not, is in **Settings → Recent SMS** with the reason it failed.

| Message | Fix |
| --- | --- |
| `The API token was not accepted` | The token was mistyped or regenerated. Copy it again from the PhilSMS Developers page and save it. |
| `Sender ID ... is not authorized` (or similar) | Set **Sender Name** back to `PhilSMS`, or wait for PhilSMS to approve yours. |
| A message about balance or credits | Load more credits in PhilSMS. |
| `HTTPS certificate check failed` | The app carries its own certificate list for WAMP (`includes/certs/cacert.pem`). Check that the file exists. If it does, PHP's `curl.cainfo` setting is pointing somewhere broken: clear it in WAMP's `php.ini` and restart WAMP. |
| `Could not reach PhilSMS` | No internet connection, or a firewall blocking `app.philsms.com`. |
| `PhilSMS did not answer within 30 seconds` (log: **No reply**) | The request got through but PhilSMS never replied, so the text may still arrive: check the phone before resending. Then press **Check Credits**. If that works, PhilSMS was just slow to send. If it times out too, something on the computer or network is holding the connection: try turning off the antivirus "web shield"/HTTPS scanning or a VPN, or test from another network (e.g. a phone hotspot). |
| `Skipped: SMS is not set up yet` | No token is saved. See step 2. |
| `Skipped: Not a valid PH mobile number` | Fix the tenant's Contact Number. |
