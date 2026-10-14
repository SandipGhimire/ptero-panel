# Engineering Plan: Cloudflare R2 (RC) Object Storage Integration

> **Target**: `extensions/advance-backups`  
> **Philosophy**: Zero modifications to main Pterodactyl core files. All functionality is self-contained within the extension.  
> **Storage Engine**: Cloudflare R2 (S3-compatible API, zero egress fees, edge-distributed object storage).

---

## 1. Mandatory Code Quality & Architectural Directives

All development on the Cloudflare R2 implementation must strictly enforce the following rules:

### A. Zero Nested `if` Statements
- Deeply nested conditional structures (`if (...) { if (...) { ... } }`) are strictly prohibited across both PHP and TypeScript/TSX codebases.
- Code must use:
  - Guard clauses with early returns (`if (!condition) return;`).
  - Flattened switch-case structures or dispatch maps.
  - Extracted single-responsibility helper functions.
  - Ternary operators for assignment fallback.
- Automated AST scanners must verify **0 nested ifs** before any phase is marked complete.

### B. Comprehensive & Standardized Docstrings
- Every function, method, interface, and React component must have a clean, informative docstring following the project's standard convention:
  ```php
  /**
   * Short one-line summary of what the method accomplishes.
   *
   * Detailed multi-line explanation of internal business logic, external
   * API interactions, and edge case behaviors when appropriate.
   *
   * @param Type $parameter Description of the parameter.
   * @return ReturnType Description of the returned value.
   * @throws SpecificException When a specific failure condition occurs.
   */
  ```
- No cluttered inline comments inside function bodies; self-documenting code with clear symbol names is enforced.

### C. Strict Element IDs & Semantic Class Names on Every UI Element
- In every `.tsx` component, **every single tag and element** (containers, forms, inputs, buttons, spans, headers, alerts, svgs/icons, table rows/cells) must have:
  - An explicit, unique `id` prefixed with `clytek-adv-*` (e.g., `id="clytek-adv-r2-test-btn"`).
  - A semantic class name prefixed with `clytek-adv-*` alongside standard Tailwind utility classes (e.g., `className="clytek-adv-r2-test-btn px-4 py-2 ..."`).
- This ensures full styling compatibility, hooks, and future licensing/telemetry instrumentation.

### D. Zero Core Repository Modifications
- Scope is strictly confined to `extensions/advance-backups/`.
- Root repository files (`app/`, `config/`, `routes/`, `resources/`) must remain 100% clean and untouched.

### E. Automated Verification Standards
- Pest test suite must achieve 100% pass rate.
- Vite bundle compilation must succeed with zero errors.
- Assets must be published via `php artisan p:extension:dev extensions/advance-backups`.

---

## 2. Architecture Overview: Cloudflare R2 Storage

Cloudflare R2 is an S3-compatible object storage service that eliminates egress bandwidth fees. Pterodactyl already includes `"aws/aws-sdk-php": "^3.369"` and `"league/flysystem-aws-s3-v3": "^3.30.1"`, allowing native S3 client integration without adding bloated third-party dependencies.

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                             ADMIN CONFIGURATION                                  │
│  Account ID | Bucket Name | Access Key ID | Secret Access Key | Jurisdiction     │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│                   CloudflareR2ClientService (Extensions Module)                  │
│       • Configures Aws\S3\S3Client with custom R2 endpoint                       │
│       • https://{account_id}[.{jurisdiction}].r2.cloudflarestorage.com           │
│       • Signature Version: v4 | Region: auto / us-east-1                         │
└───────┬───────────────────────────────┬──────────────────────────────┬───────────┘
        │                               │                              │
        ▼                               ▼                              ▼
┌──────────────┐             ┌─────────────────────┐        ┌──────────────────────┐
│  TEST CONN   │             │   BACKUP CREATION   │        │   RESTORE & DOWNLOAD │
│ HeadBucket / │             │ Presigned S3 PUT    │        │ Presigned S3 GET /   │
│ ListObjects  │             │ URL to Wings        │        │ Direct Edge Stream   │
└──────────────┘             └─────────────────────┘        └──────────────────────┘
```

---

## 3. Core Capabilities & Workflows

### 3.1. Connection Testing Engine
- **Endpoint**: `POST /api/admin/extensions/advance-backups/test-r2`
- **Controller**: `AdvanceSettingsController@testR2`
- **Methodology**: Executes a non-destructive `HeadBucket` or `ListObjectsV2` query (`MaxKeys => 1`) against the configured R2 bucket.
- **Diagnostics**: Returns structured error messages explaining whether the failure was due to invalid credentials (403 SignatureDoesNotMatch), non-existent bucket (404 NoSuchBucket), or bad endpoint URL.

### 3.2. Backup Creation Pipeline (Zero Node Egress Overhead)
1. **Initiation**: User or Schedule initiates backup when `external_service_provider === 'cloudflare_r2'`.
2. **Action Interception**: `ClytekInitiateBackup` detects `cloudflare_r2` provider:
   - Sets backup record `disk` attribute to `cloudflare_r2`.
   - Uses `ClytekPresignBackupUpload` to produce authenticated S3 multipart presigned PUT URLs via `CloudflareR2ClientService`.
   - Hands the presigned upload URLs to Wings Daemon.
3. **Direct Upload**: Wings streams the compressed archive directly into Cloudflare R2 via HTTP PUT.
4. **Completion**: Wings notifies panel webhook -> `ClytekCompleteBackup` verifies object existence in R2 bucket via `HeadObject`, updates backup status to `completed`, stores bytes size and sha256 checksum.

### 3.3. Backup Download Workflow
1. User requests backup download in panel.
2. `ClytekGenerateBackupDownloadLink` checks if `backup->disk === 'cloudflare_r2'`.
3. Calls `CloudflareR2ClientService::generatePresignedDownloadUrl($backup, 15)`.
4. Returns a direct Cloudflare R2 download link with 15-minute validity:
   - Streams from Cloudflare's nearest edge datacenter.
   - Consumes **zero bandwidth** from panel server or Wings node.
   - Incurs **zero egress fees**.

### 3.4. Backup Restoration Workflow
1. User clicks "Restore" on a Cloudflare R2 backup.
2. `ClytekRestoreBackup` verifies server state and generates a signed download URL.
3. Dispatches restore instructions with the signed R2 URL to Wings.
4. Wings streams the archive directly from Cloudflare R2 and extracts it into the server directory.

### 3.5. Backup Deletion & Lifecycle Management
1. User deletes backup or automated rotation prunes expired backups.
2. `ClytekDeleteBackup` intercepts `backup->disk === 'cloudflare_r2'`.
3. Invokes `CloudflareR2ClientService::deleteObject($backup->checksum . '.tar.gz')`.
4. Deletes database record upon successful R2 response.

---

## 4. Backend Components to Implement

### A. `src/Services/CloudflareR2ClientService.php`
- Initializes `Aws\S3\S3Client` using settings stored in `advance_settings` table.
- Methods:
  - `getClient(): S3Client`
  - `testConnection(?string $accountId, ?string $bucket, ?string $accessKey, ?string $secretKey, ?string $jurisdiction): array`
  - `generatePresignedUploadUrl(Backup $backup, int $expiryMinutes = 60): string`
  - `generatePresignedDownloadUrl(Backup $backup, int $expiryMinutes = 15): string`
  - `headObject(string $key): ?array`
  - `deleteObject(string $key): bool`
  - `listObjects(string $prefix = '', int $maxKeys = 100): array`

### B. Admin API Routes & Controller Methods
- `POST /api/admin/extensions/advance-backups/test-r2`
- Handled in `AdvanceSettingsController.php` with guard clauses and zero nested ifs.

### C. Backup Actions Enhancement
- Extend `ClytekInitiateBackup.php`, `ClytekCompleteBackup.php`, `ClytekGenerateBackupDownloadLink.php`, `ClytekRestoreBackup.php`, and `ClytekDeleteBackup.php` to seamlessly support `cloudflare_r2` alongside `pbs`.

---

## 5. Frontend Components to Implement & Enhance

### A. `CloudflareR2Settings.tsx`
- Add an interactive **Test Connection** button (`id="clytek-adv-r2-test-btn"`) with loading state and result alert.
- Include live endpoint calculation preview with jurisdiction support (`eu`, `fedramp`).
- Ensure all inputs, labels, buttons, and alert cards have explicit `id` and `clytek-adv-r2-*` classes.

### B. `AdvanceSettingsScreen.tsx`
- Add Cloudflare R2 to the `EXTERNAL_PROVIDER_OPTIONS` select menu:
  ```ts
  const EXTERNAL_PROVIDER_OPTIONS = [
      { value: 'pbs', label: 'Proxmox Backup Server (PBS)' },
      { value: 'cloudflare_r2', label: 'Cloudflare R2 Object Storage' },
  ];
  ```
- Ensure dynamic tab switching and unconfigured diagnostic alerts support Cloudflare R2 out-of-the-box.

---

## 6. Phased Implementation Roadmap

### Phase 1: Service Engine & Connection Testing [DONE]
- [x] Implement `CloudflareR2ClientService` with zero nested ifs and comprehensive PHPDocs.
- [x] Add `testR2` endpoint in `AdvanceSettingsController` and register route in `routes/admin.php`.
- [x] Update `CloudflareR2Settings.tsx` with "Test Connection" button and UI feedback.
- [x] Add Cloudflare R2 unit tests (`CloudflareR2ClientServiceTest.php`).

### Phase 2: Backup Action Integration (Initiate, Complete, Presign) [DONE]
- [x] Update `ClytekInitiateBackup` to dispatch Cloudflare R2 presigned upload credentials when provider is active.
- [x] Update `ClytekCompleteBackup` to verify R2 object storage and record byte sizes.
- [x] Add unit tests verifying Cloudflare R2 initiation and completion pipelines.

### Phase 3: Restore, Download & Deletion Workflows [DONE]
- [x] Implement presigned download link generation in `ClytekGenerateBackupDownloadLink`.
- [x] Implement direct R2 download stream routing in `ClytekRestoreBackup`.
- [x] Implement S3 object deletion in `ClytekDeleteBackup`.
- [x] Add unit tests for download, restore, and delete operations on Cloudflare R2.

### Phase 4: Final Verification & Standards Enforcement [DONE]
- [x] Run automated AST checker to guarantee **0 nested ifs** in both PHP and TS/TSX.
- [x] Verify informative docstrings on all new and updated functions.
- [x] Verify explicit `id` and semantic `clytek-adv-*` class names on all JSX tags.
- [x] Run full Pest test suite (`./vendor/bin/pest extensions/advance-backups/tests/`).
- [x] Run Vite bundle compilation (`pnpm --dir extensions/advance-backups build`).
- [x] Publish extension dev build (`php artisan p:extension:dev extensions/advance-backups`).
- [x] Verify `git status` on root repository remains completely clean.
