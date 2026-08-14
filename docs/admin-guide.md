# FMS Admin Guide

This guide explains the normal tasks for an FMS administrator: preparing the system, adding a client, configuring the client database, and checking that everything works.

For Ubuntu server installation and repeatable client-instance deployment, see [deployment.md](deployment.md).

## 1. What the system does

FMS lets hotel staff ask questions about their hotel data in a chat. The system:

1. Receives the user's question from the Laravel web application.
2. Reads the client's database using a read-only database connection.
3. Checks the schema and the user's permissions before running SQL.
4. Uses the AI agent to analyse the result.
5. Can create tables, charts, and downloadable CSV files in the conversation.

The administrator controls the following:

- Client accounts and their analytics database connections.
- Client instance security keys.
- Database schema descriptions and sensitive data rules.
- Permission tokens and the tables/columns they can access.
- Business rules and glossary terms used by the AI.
- Administrator accounts.
- Client staff users and their access status.
- System usage, costs, and logs.

## 2. Before adding a client

Make sure the following are ready:

- The client's analytics database is reachable from the central/admin server.
- You have a database administrator username and password for that database. This account is used to create or repair the system's read-only agent account.
- A Laravel instance will be installed for the client. It must be able to reach the shared database and the Python agent.
- The central Python agent is running and its `/health` endpoint returns `status: ok`.
- The admin Laravel instance has an `ADMIN_PRIVATE_KEY`.
- The Python agent has the matching admin public key in `ADMIN_PUBLIC_KEY`.
- `CLIENT_CREDENTIALS_KEY` is set in the admin Laravel `.env`. This key encrypts stored client database passwords.

Do not put a client's database password in this document or in source control.

## 3. Create a new client

### Step 1: Open the Clients page

1. Sign in to the admin web application.
2. Open **Clients** in the left menu.
3. Click **New Client**.

### Step 2: Enter the client details

Complete the form:

- **Display Name**: the hotel or customer name shown in the admin console.
- **Analytics DB DSN**: the connection string for the client's analytics database. The form accepts a host/database value or a MySQL DSN, for example:
  `mysql://dbuser:password@db-host:3306/hotel_database`
- **DB Admin User**: an account that can connect to the analytics database and create the read-only agent user.
- **DB Admin Password**: the password for that account.
- **Monthly Budget (USD)**: optional monthly AI usage limit. Leave it empty for no limit.

Click **Test** beside the DSN before saving. Fix any connection error before continuing.

Click **Create** when the connection test succeeds.

### Step 3: Save the agent password

After creation, the system may display the password for the read-only analytics agent user. Save it in the approved password manager immediately. It is not shown again.

The system manages this read-only user automatically. It creates or repairs the user when needed and removes it when the client is permanently deleted.

### Step 4: Generate the client instance key

1. Open the new client from the Clients list.
2. In **Instance Authentication**, click **Generate Keys**.
3. Enter your admin password.
4. Copy and securely save the displayed **private key**. It is shown only once.

The public key is registered automatically for the client. The private key must be placed in the client's Laravel `.env` as:

```dotenv
CLIENT_PRIVATE_KEY=<client-private-key>
```

Never send the private key by email or commit it to Git. If the key is regenerated later, the old private key stops working immediately.

## 4. Install and configure the client instance

Install the Laravel application on the client's server using the same application code as the admin instance. The client server normally needs Apache, PHP, Composer, and access to the shared database. It does not need its own Python agent, MySQL server, or Docker sandbox.

Set the important values in the client's `laravel/.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://client.example.com

DB_CONNECTION=mysql
DB_HOST=<central-database-host>
DB_PORT=3306
DB_DATABASE=agent_db
DB_USERNAME=fms
DB_PASSWORD=<shared-database-password>

PYTHON_SERVICE_URL=http://<central-agent-host>:8000
DATA_PLANE_SELF_URL=https://client.example.com/api/internal/data/v1
CLIENT_PRIVATE_KEY=<private-key-from-the-admin-console>

SANCTUM_STATEFUL_DOMAINS=client.example.com
```

Then run the normal Laravel setup commands on the client server:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan storage:link
```

Copy the built frontend files into `laravel/public`, configure the web server for the client domain, and reload Apache.

Confirm the client instance role:

```bash
php artisan fms:instance
```

It should show `Resolved role: CLIENT` and the expected client ID.

The client server must be able to reach the central Python agent. The central Python agent must also be able to call the client's `DATA_PLANE_SELF_URL` back over the network.

## 5. Verify the new client

Complete these checks before giving the client access:

1. In **Clients**, confirm the client is **Active** and shows **Key set**.
2. Log in to the client URL with a client staff account.
3. Open the chat and ask a simple question, such as “How many reservations were created this month?”
4. Confirm that the answer is returned without a database or permission error.
5. In the admin console, open the client dashboard and confirm that usage is recorded.
6. Check **Logs** for connection, authentication, or analysis errors.

## 6. Configure the database schema

Open **Schema** in the admin menu.

### Discover the schema

1. Select the client from the discovery list.
2. Click **Discover**.
3. Wait for discovery to finish.
4. Review the tables and columns that were imported.

Discovery reads the client's database structure. It does not give the AI permission to access every table automatically.

### Add descriptions and rules

Open a table to edit:

- Table description.
- Column descriptions.
- Value mappings, for example `1 = Checked in` and `0 = Cancelled`.
- Virtual foreign keys, which describe relationships when the database does not declare a real foreign key.

Descriptions should use simple business language. For example, explain what `arrival_date`, `room_type`, or `status` means in the hotel system.

Click **Save** after editing.

### Mark sensitive data

Mark a whole table or individual columns as **Sensitive** when they contain regulated or personal data.

Sensitive tables and columns are blocked for every user, including administrators. A permission token cannot override this rule.

## 7. Configure permission tokens

Open **Permissions** in the admin menu.

A permission token is a reusable access profile, such as `RECEPTION`, `RESERVATION`, or `STATISTICS`.

To create one:

1. Click **New Token**.
2. Enter a unique **Code** using letters, numbers, and underscores.
3. Enter the display **Name** and an optional description.
4. Leave the token **Active**.
5. Select the tables it may access.
6. If required, expand a table and select only specific non-sensitive columns.
7. Click **Save**.

Do not grant sensitive tables. They are intentionally unavailable and cannot be granted.

Use the smallest access profile that is sufficient for the job. For example, front-desk users normally need reservation and reception data, not financial or personal-data tables.

## 8. Synchronize client staff users

Open a client from **Clients**, then use the **Users & permissions** section.

1. Click **Sync**.
2. Click **Discover & Sync**.
3. Review the users found in the client's hotel system.
4. Confirm that the user roles and permissions are correct.
5. Use the **Access** toggle to deactivate or reactivate a user.

The sync imports staff users from the client's hotel database. It does not change the original hotel-system password. A deactivated user cannot use the FMS client application.

## 9. Add business context

Open **Business Context** to add information that helps the AI understand the hotel's language and rules.

Useful entries include:

- Glossary definitions.
- Definitions of hotel KPIs.
- Rules for cancellations, revenue, occupancy, or arrivals.
- Notes about legacy fields or unusual database values.

For each entry, enter a clear **Title**, write the rule in **Content**, and optionally select the table and column to which it applies. Leave the table empty for a general rule. Keep entries short and factual, then click **Save**.

## 10. Manage admin accounts

Open **Admins** to manage people who can use the admin console.

- Click **Add admin** to create an account.
- Use **Edit** to change the name or password.
- Delete an account only when it is no longer needed.
- You cannot delete your own currently signed-in account.

Use a separate admin account for each administrator. Do not share the seeded or initial admin password.

## 11. Daily checks

Use the following quick routine:

- **Dashboard**: review active clients, documented tables, usage, and cost.
- **Clients**: check for inactive clients, missing keys, and database connection details.
- **Logs**: investigate critical, error, and warning entries.
- **Schema**: review newly discovered tables and sensitive markings after database changes.
- **Permissions**: review access profiles after role or data changes.

## 12. Deactivate or delete a client

### Temporary deactivation

From **Clients**, click the status action for the client and choose deactivate. This prevents use while keeping the client configuration and data.

Reactivate the client from the same list when it is ready to use again.

### Permanent deletion

Open the client, choose **Delete permanently**, and confirm with your admin password.

Permanent deletion removes the client, its local staff-user records, and the managed read-only database user. It cannot be undone. Export or record anything required before confirming.

## 13. Troubleshooting

### “Invalid signature” or HTTP 403

- Confirm `CLIENT_PRIVATE_KEY` on the client matches the key shown as registered for that client.
- Confirm the client ID is correct.
- If keys were regenerated, update the client's `.env` with the new private key.
- Restart or reload the client Laravel application after changing `.env`.
- Confirm the signing time window is consistent; the default is 300 seconds.

### Database connection failure

- Test the DSN again from **Clients**.
- Confirm the central server can reach the analytics database host and port.
- Confirm the DB admin username and password are correct.
- Confirm the database firewall allows the central server.
- Run `php artisan fms:provision` on the admin Laravel instance to reconcile read-only agent users.

### Analysis says a table is blocked

Check both places:

- **Schema**: the table or column may be marked Sensitive.
- **Permissions**: the user's token may not grant the table or column.

Sensitive data is always blocked and cannot be enabled through permissions.

### Sandbox or Python execution error

On the central server, confirm Docker is running and the sandbox image exists:

```bash
docker images
curl http://127.0.0.1:8000/health
```

Check the Python service log and make sure the service account can access Docker.

### Useful logs

- Laravel: `laravel/storage/logs/`
- Python agent: `journalctl -u fms-agent`
- Apache: `/var/log/apache2/`
- Admin console: **Logs**

## 14. Important security rules

- Keep all private keys and database passwords secret.
- Do not expose the Python agent directly to the public internet; Laravel is the public entry point.
- Keep `APP_DEBUG=false` in production.
- Use read-only analytics access for normal data queries.
- Mark personal or regulated data as Sensitive before allowing users to analyse the database.
- Do not grant more tables or columns than a user's job requires.
- Never rotate `CLIENT_CREDENTIALS_KEY` by editing `.env` manually. Use the supported Laravel rekey command so stored passwords are re-encrypted correctly.
