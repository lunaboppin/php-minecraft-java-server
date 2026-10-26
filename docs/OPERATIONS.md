# Main server deployment policy

The owner requires the hosted main Docker server to be updated and restarted after every pull request merged into `main`.

1. Require the PR's checks to pass, merge it, and update the deployment checkout to the merged commit. Build the Docker image while the current server is still running; retain the previous image for rollback.
2. Stop through Docker's graceful shutdown. Connected players must receive a descriptive kick reason naming the deployed changes, for example: **Server restarting: mob damage fixes added. Please reconnect shortly.** Allow the configured shutdown grace period to finish so player and world state is saved.
3. With the server stopped, back up `data/server.json` and every world SQLite database using SQLite's backup API. Check each backup with `PRAGMA integrity_check`. Preserve all world/dimension files and player records; never reset them to deploy an update.
4. Recreate the main service from the new image with the persistent `data/` volume. Preserve the current `onlineMode: true`, configured operators and published TCP port **25676**.
5. Verify Docker health and the Minecraft status response, then confirm the running code matches the merged revision. If startup fails, inspect the failure and use the retained image to recover with the saved configuration/worlds as appropriate.

Keep daily logs as readable `.log` or `.txt`, with the existing 90-day retention. Do not archive logs into gzip files. Never publish private world/player backups or a ZIP containing live player records to a public release.
