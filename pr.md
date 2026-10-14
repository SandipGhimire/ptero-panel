# fix(server): resolve transfer locks, websocket errors, and provide safe cancel capability

## Description
This PR addresses three critical operational, stability, and usability issues with the Server Transfer subsystem across both the Panel Backend and React Frontend:

1. **State-Safe Transfer Cancellation (Bug 12)**:
   - When a transfer hung or experienced a silent network disconnect between nodes, the server was trapped in `is_transferring` indefinitely. Client API requests failed with `ServerStateConflictException`, SFTP logins broke, and reserved target allocations remained unreleased.
   - **Fix**: Implemented `DELETE /api/admin/servers/{server}/transfer` with safety guards against uncancellable states (checks active transfer existence and prevents split-brain if node ownership already committed). Signals the source daemon to abort outbound archive streaming via `cancelTransfer()`, rolls back reserved allocations via `FailTransfer`, and emits audit activity logs. Added a "Cancel Transfer" confirmation button in the Admin Server Manage tab.

2. **Admin Area Freeze on Server Transfer (Bug 11)**:
   - When initiating a server transfer in `ServerManageTab`, the action card entered a disabled state with a generic warning and never updated upon transfer completion. Administrators had to manually reload the entire browser page to view updated node allocations.
   - **Fix**: Added `refetchInterval?: number | false;` to `ResourceQueryOverrides`, configured `useAdminServerTransferProgress` to poll every 3 seconds while active, and automatically reloads server details upon completion with an informative success/failure toast.

3. **Server Owners Forbidden from WebSocket During Transfer (Bug 10)**:
   - When a server is transferring, Wings websocket credentials require `admin.websocket.transfer`, which is restricted to `root_admin`. Non-admin server owners visiting their server would trigger background `403 Forbidden` websocket errors, spamming error toasts ("Failed to start server connection").
   - **Fix**: Conditionally mounts `<WebsocketHandler />` in `ServerLayout` only when `!server.attributes.is_transferring || rootAdmin`. Server owners see the dedicated clean "Transferring" screen (`ConflictStateRenderer`) without websocket errors or toasts. Added automatic 5-second server query polling in `TransferListener` while `is_transferring` is true so the UI recovers automatically as soon as the transfer finishes.

## Changes
### Panel Backend
- `app/Http/Controllers/Api/Admin/Servers/CancelTransferController.php`: New endpoint with state safety checks and daemon abort signalling.
- `app/Services/Daemon/ServerClient.php`: Added `cancelTransfer()` method issuing `DELETE /api/servers/{uuid}/transfer` to Wings.
- `routes/api-admin.php`: Registered `DELETE api/admin/servers/{server:admin_identifier}/transfer`.

### Frontend & SDK
- `resources/scripts/api/resourceQuery.ts`: Added `refetchInterval` override to `ResourceQueryOverrides`.
- `resources/scripts/api/admin/servers/queries.ts`:
  - Added `cancelTransferAdminServerInput` and `useCancelTransferAdminServer` mutation hook.
- `resources/scripts/components/admin/servers/ServerManageTab.tsx`:
  - Configured 3-second refetch interval on `useAdminServerTransferProgress`.
  - Added transfer completion watcher with early-return guard clauses to trigger `reload()` and toast notifications.
  - Rendered "Cancel Transfer" confirmation dialog button during active transfers.
- `resources/scripts/router/layouts/ServerLayout.tsx`:
  - Only mount `<WebsocketHandler />` if the server is not transferring or if the current user is a root administrator.
- `resources/scripts/components/server/TransferListener.tsx`:
  - Added automatic 5-second interval cache invalidation while `is_transferring` is active to auto-recover server state upon transfer completion.

## Testing Done
- **Backend Tests**: Added integration tests to `tests/Integration/Api/Admin/Servers/ServerTransferControllerTest.php` and `FakeDaemonTransfer.php` verifying:
  - Active server transfer cancellation via `DELETE /api/admin/servers/{id}/transfer`.
  - Database status assertion (`successful = false`) and allocation unassignment (`server_id = null`).
  - Daemon cancel signal (`$fake->assertCancelled()`).
  - `409 Conflict` response when attempting to cancel with no active transfer.
- Ran PHP syntax check `php -l` on all new and modified backend files (0 errors).
- Verified artisan route registration: `php artisan route:list | grep -E "transfer"`.
- Ran `tsc --noEmit` (0 type errors).
- Ran `oxlint` and `oxfmt` on all modified files (0 warnings, 0 errors).
- Ran `vitest run resources/scripts/components/admin/servers/` (5/5 suites, 14/14 tests passing).
- Ran `vitest run resources/scripts/api/admin/servers/queries.spec.tsx` (6/6 tests passing, including transfer cancellation cache invalidations).
- Ran `vitest run resources/scripts/components/server/TransferListener.spec.tsx` (1/1 suite, 3/3 tests passing).
