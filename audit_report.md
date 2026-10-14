# Pterodactyl V2 Server Transfer Technical Audit Report

## Executive Summary

This audit report provides an exhaustive, low-level technical investigation into the **Server Transfer** feature across the entire Pterodactyl stack, spanning:
1. **Panel Backend (Laravel / PHP 8.3+)**
2. **Daemon (Wings / Go 1.23+)**
3. **Frontend (React 19 / TypeScript / TanStack)**

The warning currently displayed in the admin management panel (`"This feature may have bugs."`) is **well-warranted**. The subsystem suffers from multiple critical defects:
- **Port and IP collisions** on the destination node due to stale configuration generation.
- **Nil-pointer dereference panics** in Wings that crash HTTP handlers.
- **Disk leaks and ghost files** caused by inverted cleanup conditions.
- **Race conditions with HTTP client timeouts** that desynchronize database transactions from daemon state.
- **WebSocket permission lockouts** that prevent regular server owners from viewing transfer progress.
- **Unreachable local backups** after transfer completion.

Below is a detailed breakdown of each defect, its root cause, what it breaks, and precise, copy-pasteable fix implementations.

---

## Architecture & Lifecycle Overview

```
 [Admin Browser] ──(1. Transfer Request)──> [Laravel Panel]
                                                   │
                ┌──────────────────────────────────┴──────────────────────────────────┐
                │                                                                     │
     (2. Reserve Target Allocations                                      (4. Return Settings & Process Config)
         & Generate JWT)                                                              │
                │                                                                     ▼
                ▼                                                             [Destination Wings]
        [Source Wings] ───(3. Stream Multipart Archive + SHA256)───>        (Node B: Port & IP Bindings)
      (Node A: Tar & Gzip)                                                            │
                │                                                                     │
                │                                                          (5. POST /transfer/success)
                │                                                                     │
                ▼                                                                     ▼
        [Source Wings] <──────────(6. DELETE /api/servers/{uuid})───────────── [Laravel Panel]
      (Deletes Local Volume)                                                (Swaps Node ID & Allocations)
```

1. **Initiation**: The Admin invokes `POST /api/admin/servers/{id}/transfer`. The Panel reserves target allocations in a database transaction, generates a 15-minute JWT, and sends `POST /api/servers/{uuid}/transfer` to the **source** node.
2. **Source Processing**: Source Wings optionally waits up to 15 seconds for the server container to stop, then streams a tar.gz archive over HTTP to the **destination** node's `/api/transfers` endpoint.
3. **Destination Processing**: Destination Wings calls the Panel's `GET /api/remote/servers/{uuid}` to retrieve server configuration, extracts the archive stream into `/var/lib/pterodactyl/volumes/{uuid}`, verifies the SHA256 checksum, and creates the container environment.
4. **Completion**: Destination Wings notifies the Panel via `POST /api/remote/servers/{uuid}/transfer/success`. The Panel updates the server's node and allocation records in the database, releases old allocations, and calls `DELETE /api/servers/{uuid}` on the source node.

---

## Detailed Bug Findings & Remediation

---

### Bug 1: Panel Returns Source Node Allocations & IP to Destination Node

* **Severity:** **CRITICAL (Blocks Functionality)**
* **Component:** Panel Backend
* **Files:**
  * [`app/Http/Controllers/Api/Remote/Servers/ServerDetailsController.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Http/Controllers/Api/Remote/Servers/ServerDetailsController.php#L25-L45)
  * [`app/Services/Servers/ServerConfigurationStructureService.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Services/Servers/ServerConfigurationStructureService.php#L56-L103)
  * [`app/Models/Server.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Models/Server.php#L193-L204)

#### Root Cause
When the destination node receives an incoming transfer, it invokes `installer.New()`, which queries `GET /api/remote/servers/{uuid}` on the Panel. In `ServerDetailsController.php`:
```php
$valid = $transfer
    ? $node->id === $transfer->old_node || $node->id === $transfer->new_node
    : $node->id === $server->node_id;

throw_unless($valid, HttpForbiddenException::class, 'Requesting node does not have permission to access this server.');

return new JsonResponse([
    'settings' => $configuration->handle($server),
    'process_configuration' => $eggConfiguration->handle($server),
]);
```
At this point, the transfer is still in progress:
- `$server->node_id` is still `transfer.old_node`.
- `$server->allocation_id` is still `transfer.old_allocation`.

Inside `ServerConfigurationStructureService`:
```php
'allocations' => [
    'force_outgoing_ip' => $egg->force_outgoing_ip,
    'default' => [
        'ip' => $allocation->ip,
        'port' => $allocation->port,
    ],
    'mappings' => $server->getAllocationMappings(),
],
```
And inside `Server::getAllocationMappings()`:
```php
public function getAllocationMappings(): array
{
    $mappings = [];
    foreach ($this->allocations as $allocation) {
        if ($allocation->node_id === $this->node_id) { // <-- Evaluates against old_node!
            $mappings[$allocation->ip] ??= [];
            $mappings[$allocation->ip][] = $allocation->port;
        }
    }
    return $mappings;
}
```
Because `$this->node_id` is the old node:
1. `$server->allocation` provides the old node's primary allocation IP and port.
2. `getAllocationMappings()` discards all new node allocations and includes **only the old node's allocations**.
3. Environment variables `SERVER_PORT` and `SERVER_IP` are constructed using the old node's network parameters.

#### What It Breaks
1. **Port Collisions on Target Node**: If another server on the destination node is already utilizing the port used by the server on the source node, Docker container creation fails immediately with:
   `driver failed programming external connectivity on endpoint ...: Bind for 0.0.0.0:25565 failed: port is already allocated`
2. **Invalid Network Bindings**: If Node A and Node B have different public IP addresses (standard across independent machines), Docker on Node B attempts to bind to Node A's IP and fails with:
   `cannot assign requested address`
3. **Ghost Port Configuration**: If the container does successfully initialize, it binds to the old node's port instead of the allocation the administrator selected during transfer setup.

#### Fix Suggestion
In `ServerDetailsController.php`, detect if the requesting node is `transfer->newNode`. If so, clone the server model and override `node_id`, `allocation_id`, and reload `allocations` to reflect the target node before generating configuration structures.

```php
// app/Http/Controllers/Api/Remote/Servers/ServerDetailsController.php

public function __invoke(Request $request, EggConfigurationService $eggConfiguration, ServerConfigurationStructureService $configuration, string $uuid): JsonResponse
{
    $node = RemoteRequestNode::get($request);

    $server = Server::query()->with(['node', 'allocations'])->whereUuidOrShort($uuid)->firstOrFail();
    $transfer = $server->transfer;

    $valid = $transfer
        ? $node->id === $transfer->old_node || $node->id === $transfer->new_node
        : $node->id === $server->node_id;

    throw_unless($valid, HttpForbiddenException::class, 'Requesting node does not have permission to access this server.');

    // If the target node is requesting the server configuration mid-transfer,
    // evaluate the server using the destination node parameters.
    if ($transfer && $node->id === $transfer->new_node) {
        $server = clone $server;
        $server->node_id = $transfer->new_node;
        $server->allocation_id = $transfer->new_allocation;
        $server->setRelation('node', $transfer->newNode);
        $server->setRelation(
            'allocations',
            Allocation::query()->whereIn('id', [$transfer->new_allocation, ...($transfer->new_additional_allocations ?? [])])->get()
        );
        $server->setRelation(
            'allocation',
            $server->allocations->firstWhere('id', $transfer->new_allocation)
        );
    }

    return new JsonResponse([
        'settings' => $configuration->handle($server),
        'process_configuration' => $eggConfiguration->handle($server),
    ]);
}
```

---

### Bug 2: `CompleteTransfer` Never Synchronizes New Wings Node

* **Severity:** **CRITICAL (Stale State & Broken Rebuilds)**
* **Component:** Panel Backend
* **File:** [`app/Actions/Transfers/CompleteTransfer.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Actions/Transfers/CompleteTransfer.php#L26-L54)

#### Root Cause
In `CompleteTransfer.php`:
```php
public function complete(ServerTransfer $transfer): Server
{
    $server = DB::transaction(function () use ($transfer): Server {
        Allocation::query()
            ->whereIn('id', [$transfer->old_allocation, ...($transfer->old_additional_allocations ?? [])])
            ->update(['server_id' => null]);

        $server = $transfer->server;
        $server->update([
            'allocation_id' => $transfer->new_allocation,
            'node_id' => $transfer->new_node,
        ]);

        $transfer->update(['successful' => true]);
        Event::dispatch(new OperationCompleted($server->uuid, 'transfer', true, $server->uuid));

        return $server->refresh();
    });

    // Point the delete at the old node so the freshly transferred copy is left alone.
    try {
        Daemon::server($server, $transfer->oldNode)->delete();
    } catch (DaemonConnectionException $daemonConnectionException) {
        Log::warning($daemonConnectionException->getMessage(), ['exception' => $daemonConnectionException, 'transfer_id' => $transfer->id]);
    }

    return $server;
}
```
1. The Panel never issues `Daemon::server($server, $transfer->newNode)->sync();`.
2. As a result, Wings on Node B never refreshes its cached configuration or writes the finalized JSON to `/etc/pterodactyl/servers/{uuid}.json`.
3. In [`wings/environment/docker/container.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/environment/docker/container.go#L153):
   ```go
   if _, err := e.ContainerInspect(ctx); err == nil {
       return nil
   }
   ```
   Because the container was already created during `postTransfers`, any normal boot check returns early and never updates port mappings or environment variables.

#### What It Breaks
- Wings on the new node operates with uncommitted in-memory configuration until Wings is manually restarted.
- Docker containers run with missing or invalid environment variables.

#### Fix Suggestion
Add an explicit daemon sync call to the destination node inside `CompleteTransfer.php`:

```php
// app/Actions/Transfers/CompleteTransfer.php

// Sync the finalized configuration to the new node to ensure all mappings and settings match.
try {
    Daemon::server($server, $transfer->newNode)->sync();
} catch (DaemonConnectionException $e) {
    Log::error('Failed to sync server with new node after transfer completion.', [
        'server_uuid' => $server->uuid,
        'new_node_id' => $transfer->new_node,
        'error' => $e->getMessage(),
    ]);
}
```

---

### Bug 3: Wings Nil-Pointer Dereference Panic on Installer Failure

* **Severity:** **CRITICAL (Daemon Crash)**
* **Component:** Wings (Go)
* **File:** [`wings/router/router_transfer.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/router/router_transfer.go#L62-L80)

#### Root Cause
Look at lines 63–79 in `wings/router/router_transfer.go`:
```go
trnsfr := transfer.Incoming().Get(u.String())
if trnsfr == nil {
    trnsfr = transfer.New(c, nil) // <-- Second argument (Server) is nil!

    ctx, cancel = context.WithCancel(trnsfr.Context())
    defer cancel()

    i, err := installer.New(ctx, manager, installer.ServerDetails{
        UUID:              u.String(),
        StartOnCompletion: false,
    })
    if err != nil {
        // FATAL: trnsfr.Server is nil! Calling .ID() causes an instant runtime panic!
        if err := manager.Client().SetTransferStatus(context.Background(), trnsfr.Server.ID(), false); err != nil {
            trnsfr.Log().WithField("status", false).WithError(err).Error("failed to set transfer status")
        }
        middleware.CaptureAndAbort(c, err)
        return
    }
```
If `installer.New` fails (e.g. target node cannot reach Panel, rate limit, or invalid response), `trnsfr.Server` is still `nil`.
Calling `trnsfr.Server.ID()` dereferences a null pointer, causing Gin/Go to panic:
`runtime error: invalid memory address or nil pointer dereference`

#### What It Breaks
- Wings crashes or terminates the HTTP connection ungracefully with HTTP 500.
- Panel is never notified that the transfer failed; the server remains frozen in the database.
- Go stack trace fills the Wings log.

#### Fix Suggestion
Use `u.String()` directly instead of `trnsfr.Server.ID()`:

```go
// wings/router/router_transfer.go

if err != nil {
    if err := manager.Client().SetTransferStatus(context.Background(), u.String(), false); err != nil {
        log.WithField("server", u.String()).WithField("status", false).WithError(err).Error("failed to set transfer status")
    }
    middleware.CaptureAndAbort(c, err)
    return
}
```

---

### Bug 4: Inverted File Cleanup Condition Leaks Disk Space on Failed Transfers

* **Severity:** **HIGH (Disk Leak & Corrupted Retries)**
* **Component:** Wings (Go)
* **File:** [`wings/router/router_transfer.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/router/router_transfer.go#L108-L122)

#### Root Cause
In `wings/router/router_transfer.go`:
```go
defer func(ctx context.Context, trnsfr *transfer.Transfer) {
    transfer.Incoming().Remove(trnsfr)

    if !successful {
        trnsfr.Server.Events().Publish(server.TransferStatusEvent, "failure")
        manager.Remove(func(match *server.Server) bool {
            return match.ID() == trnsfr.Server.ID()
        })
    }

    if err := manager.Client().SetTransferStatus(context.Background(), trnsfr.Server.ID(), successful); err != nil {
        // BUG: This cleanup only triggers if SetTransferStatus RETURNED AN ERROR!
        if !successful && err != nil {
            // Delete all extracted files.
            go func(trnsfr *transfer.Transfer) {
                _ = trnsfr.Server.Filesystem().UnixFS().Close()
                if err := os.RemoveAll(trnsfr.Server.Filesystem().Path()); err != nil && !os.IsNotExist(err) {
                    trnsfr.Log().WithError(err).Warn("failed to delete local server files")
                }
            }(trnsfr)
        }

        trnsfr.Log().WithField("status", successful).WithError(err).Error("failed to set transfer status on panel")
        return
    }

    trnsfr.Server.SetTransferring(false)
    trnsfr.Server.Events().Publish(server.TransferStatusEvent, "success")
}(ctx, trnsfr)
```
The file cleanup routine (`os.RemoveAll(trnsfr.Server.Filesystem().Path())`) is placed **inside** `if err := manager.Client().SetTransferStatus(...) != nil`.
When a transfer fails normally (e.g. checksum mismatch, archive decompression failure, cancelled):
1. `SetTransferStatus(..., false)` is called.
2. The Panel responds HTTP 204 No Content (`err == nil`).
3. Because `err == nil`, the entire cleanup code block is skipped!
4. The server object is removed from memory (`manager.Remove`), but **all extracted files remain on disk** in `/var/lib/pterodactyl/volumes/{uuid}`.

#### What It Breaks
- **Disk exhaustion**: Failed transfers of large game servers leave 10–50 GB orphan directories on the target node.
- **Corrupted subsequent transfers**: If an administrator attempts to transfer the server to the same node again, new files mix with old orphaned files or throw `file exists` errors.

#### Fix Suggestion
Move cleanup logic outside the error block so it executes unconditionally whenever `!successful`:

```go
// wings/router/router_transfer.go

defer func(ctx context.Context, trnsfr *transfer.Transfer) {
    transfer.Incoming().Remove(trnsfr)

    if !successful {
        trnsfr.Server.Events().Publish(server.TransferStatusEvent, "failure")
        manager.Remove(func(match *server.Server) bool {
            return match.ID() == trnsfr.Server.ID()
        })

        // Clean up partially extracted data and container on failure
        go func(s *server.Server) {
            _ = s.Filesystem().UnixFS().Close()
            if err := os.RemoveAll(s.Filesystem().Path()); err != nil && !os.IsNotExist(err) {
                s.Log().WithError(err).Warn("failed to delete local server files after failed transfer")
            }
            if err := s.Environment.Destroy(); err != nil {
                s.Log().WithError(err).Warn("failed to destroy environment after failed transfer")
            }
        }(trnsfr.Server)
    }

    if err := manager.Client().SetTransferStatus(context.Background(), trnsfr.Server.ID(), successful); err != nil {
        trnsfr.Log().WithField("status", successful).WithError(err).Error("failed to set transfer status on panel")
        return
    }

    if successful {
        trnsfr.Server.SetTransferring(false)
        trnsfr.Server.Events().Publish(server.TransferStatusEvent, "success")
    }
}(ctx, trnsfr)
```

---

### Bug 5: `DELETE /api/transfers/:server` Route Guaranteed to Panic

* **Severity:** **HIGH (Endpoint Broken)**
* **Component:** Wings (Go)
* **Files:**
  * [`wings/router/router.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/router/router.go#L68)
  * [`wings/router/router_transfer.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/router/router_transfer.go#L258-L279)
  * [`wings/router/middleware/middleware.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/router/middleware/middleware.go#L215-L220)

#### Root Cause
In `wings/router/router.go`:
```go
protected := router.Use(middleware.RequireAuthorization())
...
protected.DELETE("/api/transfers/:server", deleteTransfer)
```
Notice that `protected` only uses `RequireAuthorization()`. It does **not** use `middleware.ServerExists()`.
Now look at `deleteTransfer`:
```go
func deleteTransfer(c *gin.Context) {
    s := ExtractServer(c) // <-- Panics!
```
And in `middleware/middleware.go`:
```go
func ExtractServer(c *gin.Context) *server.Server {
    v, ok := c.Get("server")
    if !ok {
        panic("middleware/middleware: cannot extract server: not present in request context")
    }
    return v.(*server.Server)
}
```
Because `ServerExists()` middleware was never mounted for this route, `c.Get("server")` is never populated. Any HTTP request to cancel an incoming transfer panics immediately.

#### What It Breaks
- Calling `DELETE /api/transfers/:server` returns HTTP 500 and crashes the request handler with a panic.
- Incoming transfers cannot be cancelled via this endpoint.

#### Fix Suggestion
Extract the server ID parameter directly from `c.Param("server")` and query the transfer manager:

```go
// wings/router/router_transfer.go

func deleteTransfer(c *gin.Context) {
    serverId := c.Param("server")
    trnsfr := transfer.Incoming().Get(serverId)
    if trnsfr == nil {
        c.AbortWithStatusJSON(http.StatusNotFound, gin.H{
            "error": "No incoming transfer found for this server.",
        })
        return
    }

    trnsfr.Cancel()
    c.Status(http.StatusAccepted)
}
```

---

### Bug 6: Guzzle 15-Second Timeout vs Wings Synchronous `WaitForStop(15s)`

* **Severity:** **HIGH (Transaction Rollback & Desynchronization)**
* **Component:** Panel Backend & Wings
* **Files:**
  * [`config/pterodactyl.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/config/pterodactyl.php#L82)
  * [`wings/router/router_server_transfer.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/router/router_server_transfer.go#L64-L74)
  * [`app/Actions/Transfers/InitiateTransfer.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Actions/Transfers/InitiateTransfer.php#L37-L80)

#### Root Cause
1. In `config/pterodactyl.php`:
   ```php
   'guzzle' => [
       'timeout' => env('GUZZLE_TIMEOUT', 15),
       'connect_timeout' => env('GUZZLE_CONNECT_TIMEOUT', 5),
   ],
   ```
2. When the Panel starts a transfer, it holds an open database transaction:
   ```php
   DB::transaction(function () use (...): ServerTransfer {
       ...
       Daemon::server($server)->transfer($node, $token);
       return $transfer->refresh();
   });
   ```
3. Inside Wings `postServerTransfer`:
   ```go
   if s.Environment.State() != environment.ProcessOfflineState {
       if err := s.Environment.WaitForStop(
           s.Context(),
           time.Second*15, // Waits 15 seconds SYNCHRONOUSLY!
           false,
       ); err != nil && ...
   ```
If the container takes 14–15 seconds to flush memory and stop, Guzzle times out on the Panel.
The Panel catches the timeout exception and **rolls back the database transaction**.
However, Wings on Node A has already received the payload, finishes stopping, and launches `PushArchiveToTarget` in the background!
When Node B finishes receiving the archive and contacts the Panel (`POST /transfer/success`), the Panel rejects the request with `409 Conflict: Server is not being transferred` because the database record was rolled back.

#### What It Breaks
- Transfers fail after moving full data archives.
- The server on Node A remains stopped with `is_transferring` locked in memory.
- False failure error toasts shown to administrators.

#### Fix Suggestion
1. **In Wings (`router_server_transfer.go`)**: Move `WaitForStop` into the background goroutine instead of blocking the initial HTTP response.
2. Return HTTP 202 Accepted immediately once validation passes.

```go
// wings/router/router_server_transfer.go

// Block the server from starting while we are transferring it.
s.SetTransferring(true)

trnsfr := transfer.New(context.Background(), s)
transfer.Outgoing().Add(trnsfr)

go func() {
    defer transfer.Outgoing().Remove(trnsfr)

    // Stop server asynchronously in the background worker
    if s.Environment.State() != environment.ProcessOfflineState {
        trnsfr.SendMessage("Stopping server for transfer...")
        if err := s.Environment.WaitForStop(s.Context(), time.Minute*2, false); err != nil && !strings.Contains(strings.ToLower(err.Error()), "no such container") {
            trnsfr.Error(err, "Failed to stop server container for transfer.")
            notifyPanelOfFailure()
            return
        }
    }

    if _, err := trnsfr.PushArchiveToTarget(data.URL, data.Token); err != nil {
        notifyPanelOfFailure()
        if err == context.Canceled {
            trnsfr.SendMessage("Transfer canceled.")
            return
        }
        trnsfr.Log().WithError(err).Error("failed to push archive to target")
        return
    }

    trnsfr.Log().Debug("transfer complete")
}()

c.Status(http.StatusAccepted)
```

---

### Bug 7: Local Wings Backups (`disk = 'wings'`) Broken Post-Transfer

* **Severity:** **HIGH (Data Loss)**
* **Component:** Panel Backend
* **Files:**
  * [`app/Models/Backup.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Models/Backup.php#L49)
  * [`app/Actions/Transfers/CompleteTransfer.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Actions/Transfers/CompleteTransfer.php#L34-L43)

#### Root Cause
When backups are stored locally (`disk === 'wings'`), the archives exist only on the host filesystem of Node A (`/var/lib/pterodactyl/backups/{backup_uuid}.tar.gz`).
1. Transfer archives only the server volume directory `/var/lib/pterodactyl/volumes/{uuid}`.
2. When the transfer completes, `$server->node_id` is updated to Node B.
3. When users attempt to restore, download, or delete their backups, requests route to Node B.
4. Node B does not have the files; calls return HTTP 404 Not Found.
5. On Node A, the old backup files remain orphaned indefinitely.

#### What It Breaks
- Users permanently lose access to existing local backups upon transferring nodes.
- Attempting to restore backups on Node B fails with missing file errors.

#### Fix Suggestion
1. If backups use `disk === 'wings'`, warn the administrator in `TransferServerModal.tsx` that local backups cannot be migrated automatically, or
2. Add a backend check that either requires backups to be stored on S3/remote storage, or marks local backups as orphaned/inaccessible on the previous node.

---

### Bug 8: SHA256 Checksum Mismatch from Un-Drained Multipart Part

* **Severity:** **MEDIUM / HIGH (Intermittent Transfer Aborts)**
* **Component:** Wings (Go)
* **File:** [`wings/router/router_transfer.go`](file:///var/www/ClytekStudio/PterodactylV2/Theme/wings/router/router_transfer.go#L179-L221)

#### Root Cause
In `wings/router/router_transfer.go`:
```go
case "archive":
    trnsfr.Log().Debug("received archive")
    if err := trnsfr.Server.EnsureDataDirectoryExists(); err != nil {
        middleware.CaptureAndAbort(c, err)
        return
    }

    tee := io.TeeReader(p, h)
    if err := trnsfr.Server.Filesystem().ExtractStreamUnsafe(ctx, "/", tee); err != nil {
        middleware.CaptureAndAbort(c, err)
        return
    }

    hasArchive = true
```
`ExtractStreamUnsafe` parses the tar archive and exits as soon as it reads the tar end-of-archive marker.
However, in gzip-compressed multipart parts, there are 8 trailer bytes (CRC32 + ISIZE) and potential boundary padding.
Because `p` is **not drained to EOF**, `tee` never reads those trailing bytes into hasher `h`.
When the sender calculates SHA256, it hashes the **entire** uncompressed stream sent into `dest`.
When the destination compares checksums in the next multipart chunk:
```go
if !bytes.Equal(expected[:n], actual) {
    middleware.CaptureAndAbort(c, errors.New("checksums don't match"))
    return
}
```
The comparison fails, aborting the transfer even though all files were validly extracted.

#### What It Breaks
- Random `"checksums don't match"` errors on large transfers.

#### Fix Suggestion
Drain any remaining bytes from `p` into `io.Discard` through the tee reader after extraction:

```go
// wings/router/router_transfer.go

tee := io.TeeReader(p, h)
if err := trnsfr.Server.Filesystem().ExtractStreamUnsafe(ctx, "/", tee); err != nil {
    middleware.CaptureAndAbort(c, err)
    return
}

// Drain any trailing bytes (e.g. gzip trailer, block padding) to ensure hash matches sender
if _, err := io.Copy(io.Discard, tee); err != nil {
    middleware.CaptureAndAbort(c, err)
    return
}

hasArchive = true
```

---

### Bug 9: `Daemon::server($server, $transfer->oldNode)` Deletes New Node if `oldNode` is Null

* **Severity:** **HIGH (Catastrophic Data Loss Risk)**
* **Component:** Panel Backend
* **Files:**
  * [`app/Actions/Transfers/CompleteTransfer.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Actions/Transfers/CompleteTransfer.php#L47)
  * [`app/Services/Daemon/ServerClient.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Services/Daemon/ServerClient.php#L17-L20)

#### Root Cause
In `CompleteTransfer.php`:
```php
$server->update([
    'allocation_id' => $transfer->new_allocation,
    'node_id' => $transfer->new_node,
]);
...
Daemon::server($server, $transfer->oldNode)->delete();
```
And in `ServerClient.php`:
```php
public static function forServer(Server $server, ?Node $node = null): self
{
    return new self($server->uuid, DaemonConnection::forNode($node ?? $server->node));
}
```
If `$transfer->oldNode` evaluates to `null` (e.g. relation cache missed, foreign key issue, or old node deleted):
`$node ?? $server->node` defaults to `$server->node` — which was **just updated to the NEW node**!
The Panel calls `DELETE /api/servers/{uuid}` on the destination node, instantly wiping out the server that was just transferred.

#### What It Breaks
- Instant deletion of the server on the destination node if `oldNode` is null.

#### Fix Suggestion
Explicitly guard against a null `oldNode` in `CompleteTransfer.php`:

```php
// app/Actions/Transfers/CompleteTransfer.php

$oldNode = $transfer->oldNode;
if ($oldNode instanceof Node) {
    try {
        Daemon::server($server, $oldNode)->delete();
    } catch (DaemonConnectionException $daemonConnectionException) {
        Log::warning($daemonConnectionException->getMessage(), [
            'exception' => $daemonConnectionException,
            'transfer_id' => $transfer->id,
        ]);
    }
} else {
    Log::error('Could not delete server from source node: oldNode relationship returned null.', [
        'transfer_id' => $transfer->id,
        'old_node_id' => $transfer->old_node,
    ]);
}
```

---

### Bug 10: Server Owners Forbidden from WebSocket During Transfer [RESOLVED / COMPLETED]

* **Severity:** **MEDIUM / UX BUG**
* **Status:** **RESOLVED / COMPLETED**
* **Component:** Panel Frontend
* **Files:**
  * [`resources/scripts/router/layouts/ServerLayout.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/router/layouts/ServerLayout.tsx)
  * [`resources/scripts/components/server/TransferListener.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/server/TransferListener.tsx)
  * [`resources/scripts/components/server/ConflictStateRenderer.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/server/ConflictStateRenderer.tsx)

#### Root Cause
In `WebsocketController.php`:
```php
if (($server->transfer) !== null) {
    // Check if the user has permissions to receive transfer logs.
    throw_unless(in_array('admin.websocket.transfer', $permissions), HttpForbiddenException::class, 'You do not have permission to view server transfer logs.');
```
Because `admin.websocket.transfer` is only granted to `root_admin` users in `GetUserPermissionsService.php`, non-admin server owners attempting to connect to the server's WebSocket during a transfer triggered `403 Forbidden` errors, spamming error toasts ("Failed to start server connection").

#### Resolution Applied
Rather than leaking low-level Wings daemon logs and node internals to server owners via daemon WebSockets:
1. **Isolated Daemon WebSocket**: In [`ServerLayout.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/router/layouts/ServerLayout.tsx), `<WebsocketHandler />` is conditionally mounted only when `!server.attributes.is_transferring || rootAdmin`. Non-admins never attempt to connect to Wings during transfers, completely eliminating 403 Forbidden errors and error toasts.
2. **Clear User Messaging**: Server owners cleanly see the built-in `ConflictStateRenderer` screen:
   > **"Transferring"**  
   > *Your server is being transferred to a new node, please check back later.*
3. **Automatic Recovery**: In [`TransferListener.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/server/TransferListener.tsx), added a 5-second server query poll while `is_transferring` is true. As soon as the transfer finishes or fails on the backend, the server details update, the conflict screen automatically unmounts, and the console/controls restore smoothly without requiring a manual page refresh.
4. **Verified**: Passed `tsc --noEmit` (0 errors), `oxlint` (0 errors), `oxfmt`, and all unit tests.

---

### Bug 11: Admin Area "Transfer Server" Freezes Without Polling [RESOLVED / COMPLETED]

* **Severity:** **LOW / UX BUG**
* **Status:** **RESOLVED / COMPLETED**
* **Component:** Frontend
* **Files:**
  * [`resources/scripts/components/admin/servers/ServerManageTab.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/admin/servers/ServerManageTab.tsx)
  * [`resources/scripts/api/admin/servers/queries.ts`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/api/admin/servers/queries.ts)
  * [`resources/scripts/api/resourceQuery.ts`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/api/resourceQuery.ts)

#### Root Cause
In `ServerManageTab.tsx`:
```tsx
const { data: transfer } = useAdminServerTransferProgress(attributes.id);
const activeTransfer = isObject(transfer);
```
`useAdminServerTransferProgress` had **no `refetchInterval`** and did not listen to WebSockets.
When an administrator initiated a transfer, `activeTransfer` became `true`. When the transfer completed, the query cache was never refreshed. The action card stayed disabled with `"This server is currently being transferred to another node."` until the administrator manually reloaded the browser tab.

#### What It Breaks
- Admin UI appeared frozen/stuck indefinitely until hard browser page reload.
- Server detail information (new node and allocations) remained stale until manual refresh.

#### Resolution Applied
1. **Type-Safe Overrides**: Added `refetchInterval?: number | false;` to `ResourceQueryOverrides` in [`resourceQuery.ts`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/api/resourceQuery.ts).
2. **Built-in Polling**: In [`ServerManageTab.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/admin/servers/ServerManageTab.tsx), passed `{ refetchInterval: 3000 }` directly into `useAdminServerTransferProgress`. TanStack Query automatically polls the endpoint every 3 seconds while mounted.
3. **Automatic Server Reload & Clean Success/Failure Handling**: Connected `useServerDetail().reload` via a state transition hook. When the active transfer completes, it compares `attributes.node` against `targetNode` using early guard returns:
   - If the server did not migrate to the destination node, it emits `toast.error('Server transfer failed')`.
   - If the node matches the target destination, it emits `toast.success('Server transfer completed')`.
4. **Enhanced UI States**: Replaced the generic warning during active transfers with an informative progress alert, and changed the button label to `"Transferring..."` while disabled.
5. **Verified**: Passed `tsc --noEmit` (0 errors), `oxlint` (0 errors), `oxfmt`, and all 5 Vitest unit test suites (14 tests passed).

---

### Bug 12: Missing Panel Cancel Endpoint & Stuck Transfer Timeout [RESOLVED / COMPLETED]

* **Severity:** **MEDIUM (Operational Bottleneck)**
* **Status:** **RESOLVED / COMPLETED**
* **Component:** Panel Backend & Frontend
* **Files:**
  * [`app/Http/Controllers/Api/Admin/Servers/CancelTransferController.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Http/Controllers/Api/Admin/Servers/CancelTransferController.php)
  * [`app/Services/Daemon/ServerClient.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Services/Daemon/ServerClient.php#L125-L128)
  * [`routes/api-admin.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/routes/api-admin.php#L170-L172)
  * [`resources/scripts/api/admin/servers/queries.ts`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/api/admin/servers/queries.ts)
  * [`resources/scripts/components/admin/servers/ServerManageTab.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/admin/servers/ServerManageTab.tsx)

#### Root Cause
There was no endpoint, UI control, or Artisan command to cancel or time out stuck transfers.
If a network disconnect occurred between nodes and Wings failed to report failure:
- `$server->transfer` remained in the database with `successful = null` forever.
- `AuthenticateServerAccess` middleware blocked every Client API request with `ServerStateConflictException`.
- SFTP authentication failed.
- The server was locked out until someone manually modified the database.

#### Resolution Applied
1. **State-Safe Backend Endpoint**: Created `DELETE /api/admin/servers/{server}/transfer` in [`CancelTransferController.php`](file:///var/www/ClytekStudio/PterodactylV2/Theme/app/Http/Controllers/Api/Admin/Servers/CancelTransferController.php):
   - **Active Transfer Guard**: Throws `409 Conflict` if no transfer is currently running or if it has already finalized (`successful !== null`).
   - **Split-Brain Guard**: Checks if the server's `node_id` has already migrated to `transfer->new_node`. If so, aborts cancellation to prevent accidental data loss.
   - **Daemon Signal**: Calls `Daemon::server($server, $transfer->oldNode)->cancelTransfer()` to signal Wings on the source node to stop archive transmission.
   - **Allocation Cleanup**: Executes `FailTransfer::fail($transfer)` to release reserved destination allocations and mark the transfer failed.
2. **Client SDK & Type Generation**: Generated OpenAPI schema via Scribe and HeyAPI openapi-ts; added `cancelTransferAdminServerInput` helper and `useCancelTransferAdminServer` mutation in [`queries.ts`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/api/admin/servers/queries.ts).
3. **Admin UI Confirmation Button**: In [`ServerManageTab.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/admin/servers/ServerManageTab.tsx), when `activeTransfer` is true, the card provides an interactive `"Cancel Transfer"` button with a confirmation modal dialog.
4. **Verified**: Passed `tsc --noEmit` (0 errors), `oxlint` (0 errors), `oxfmt`, and all 5 Vitest test suites (14/14 tests passed).

---

## Complete Audit Summary Table

| ID | Component | Severity | Bug Description | Real-World Failure Mode |
|---|---|---|---|---|
| **1** | Panel Backend | **CRITICAL** | `ServerDetailsController` sends Node A allocations to Node B | Docker port collisions, bind failures, broken container ports |
| **2** | Panel Backend | **CRITICAL** | `CompleteTransfer` never syncs Node B Wings | Stale config file, container permanently running with wrong ports |
| **3** | Wings (Go) | **CRITICAL** | Nil-pointer panic on `installer.New` error | Wings HTTP handler crashes on nil `trnsfr.Server.ID()` |
| **4** | Wings (Go) | **HIGH** | Inverted cleanup condition in `router_transfer.go` | Orphaned volume directories leak gigabytes on failed transfers |
| **5** | Wings (Go) | **HIGH** | `DELETE /api/transfers/:server` lacks `ServerExists` middleware | Unconditional panic when canceling incoming transfers |
| **6** | Panel / Wings | **HIGH** | Guzzle 15s timeout vs Wings 15s synchronous `WaitForStop` | Panel transaction rolls back while transfer proceeds |
| **7** | Panel Backend | **HIGH** | Local backups (`disk = 'wings'`) not transferred | Users get 404 on backup restore/download after transfer |
| **8** | Wings (Go) | **HIGH** | Multipart archive part not drained before checksum | Random `"checksums don't match"` transfer failures |
| **9** | Panel Backend | **HIGH** | `CompleteTransfer` deletes new node if `oldNode` is null | Risk of deleting newly transferred server |
| **10** | Panel Frontend | **RESOLVED** | Server owners forbidden from transfer WebSocket (403) | **FIXED**: Suppressed daemon socket for non-admins during transfer; added auto-poll recovery |
| **11** | Frontend | **RESOLVED** | `useAdminServerTransferProgress` has no polling | **FIXED**: Added 3s query polling, auto-reload hook & toast notification |
| **12** | Backend / Frontend | **RESOLVED** | No cancel endpoint or UI button for stuck transfers | **FIXED**: Added state-safe DELETE endpoint, daemon signal, allocation rollback & UI cancel dialog |

---

## Final Recommendation

Once these changes are applied across the Panel and Wings:
1. Target node configuration generation will produce valid port bindings.
2. Failed transfers will clean up completely without leaving disk artifacts.
3. Daemon panics and timeout race conditions will be eliminated.
4. The warning in [`ServerManageTab.tsx`](file:///var/www/ClytekStudio/PterodactylV2/Theme/resources/scripts/components/admin/servers/ServerManageTab.tsx#L232) (`{ type: 'warning', title: 'Warning!', message: 'This feature may have bugs.' }`) can be safely removed.

