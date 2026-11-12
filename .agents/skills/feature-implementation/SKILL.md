---
name: feature-implementation
description: End-to-end procedural workflow for planning, implementing, verifying, and documenting new features and modules in thin, testable slices.
---

# Feature Implementation Skill

## 1. When to Use
Invoke this skill whenever picking up a new task or phase from `docs/DEVELOPMENT_ROADMAP.md`, building a new module, or adding functionality to an existing domain.

---

## 2. The Thin-Slice Engineering Discipline
Do **not** attempt to build multiple modules or hundreds of lines of unverified code in a single step. Implement each feature in **thin, verifiable vertical slices**:
1. Schema & Migration $\rightarrow$ 
2. Backend Handler & Services $\rightarrow$ 
3. Frontend View & AJAX $\rightarrow$ 
4. Verification & Audit $\rightarrow$ 
5. Documentation.

---

## 3. Step-by-Step Implementation Procedure

### Step 1: Pre-Implementation Analysis
- Invoke the `project-analysis` skill.
- Review requirements in `docs/PROJECT_REQUIREMENTS.md`.
- Review domain logic in `docs/BUSINESS_RULES.md` (e.g., calculations, status state machines).
- Confirm current phase in `docs/DEVELOPMENT_ROADMAP.md`.

### Step 2: Data Layer Preparation
- Reference `docs/DATABASE_SCHEMA.md` and use the `database-management` skill.
- Ensure the required tables, columns, indexes, and foreign keys exist.
- If a schema modification is needed, update `docs/schema.sql`.

### Step 3: Backend Handler & Logic
- Create or update the module handler (e.g., `modules/<domain>/handler.php`) following the `php-development` skill.
- **Enforce Security Pipeline**:
  1. Authenticate user (`require_login()`).
  2. Verify permission (`require_permission('<module>_<action>')`).
  3. Validate anti-CSRF token on all POST/PUT/DELETE requests.
  4. Sanitize and validate all incoming inputs.
- **Execute Database Queries**:
  - Use parameterized PDO prepared statements.
  - Wrap multi-table or inventory mutations in `beginTransaction()` / `commit()` / `rollBack()`.
  - Log state changes to `audit_logs`.
- **Return Standard Response**:
  - Return `{ success, message, data, errors }` via `json_response()`.

### Step 4: Frontend View & UI Integration
- Build or update the view template (e.g., `modules/<domain>/index.php`).
- Wrap the view in the shared layout:
  ```php
  <?php
  require_once __DIR__ . '/../../includes/auth.php';
  require_once __DIR__ . '/../../includes/header.php';
  require_once __DIR__ . '/../../includes/sidebar.php';
  ?>

  <!-- Module Content -->

  <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
  ```
- Use the shared design classes (`assets/css/`) and the central Fetch wrapper (`assets/js/api.js`).
- Escape all dynamic outputs with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

### Step 5: Verification & Quality Gate
- Manually or programmatically verify that:
  - Valid requests succeed and produce expected database records.
  - Invalid inputs (negative numbers, empty strings, unauthorized roles) are blocked with user-friendly errors.
  - Calculations match `docs/BUSINESS_RULES.md` exactly.
  - Browser console has zero JavaScript errors.

### Step 6: Progress & Documentation Update
- Mark completed deliverables in `docs/DEVELOPMENT_ROADMAP.md` from `[ ]` to `[x]`.
- Record what was built in `CHANGELOG.md` under `[Unreleased]`.
