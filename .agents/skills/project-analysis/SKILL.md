---
name: project-analysis
description: Systematically inspects project state, verifies architecture records, checks database schema, and assesses impact before proposing or implementing changes.
---

# Project Analysis Skill

## 1. When to Use
Invoke this skill **before** starting any new feature, bug fix, or refactor. This guarantees that all work aligns with existing architecture, database tables, and business invariants without making false assumptions.

---

## 2. Step-by-Step Analysis Procedure

### Step 1: Knowledge & Governance Review
Before inspecting or modifying code, examine the project source of truth:
1. **Hard Rules & Invariants**:
   - Read `AGENTS.md` (root standards).
   - Read `.agents/rules/data-integrity-and-security.md` (transaction atomicity, financial immutability).
   - Read `.agents/rules/security-requirements.md` (auth, hashing, RBAC, XSS/CSRF rules).
2. **Domain Specifications**:
   - Check `docs/PROJECT_REQUIREMENTS.md` for scope and role permissions.
   - Check `docs/BUSINESS_RULES.md` for exact calculations and state transitions (e.g., COD order flow, Net Income formula).
   - Check `docs/DATABASE_SCHEMA.md` for exact table names, column data types, and foreign keys.
3. **Current Progress**:
   - Check `docs/DEVELOPMENT_ROADMAP.md` and `CHANGELOG.md` to determine current milestone status and completed dependencies.

---

### Step 2: Codebase Inspection
Map out the current code state relevant to the requested task:
1. **Module Check**:
   - Inspect `modules/<target_module>/` to verify if views, handlers, or services already exist.
   - Check for existing reusable utilities in `includes/` (`auth.php`, `rbac.php`, `csrf.php`, `audit.php`).
2. **Database Alignment**:
   - Confirm whether necessary tables are already defined in `docs/schema.sql` or if a new migration is required.
   - Ensure foreign key constraints and `deleted_at` soft-delete conventions are respected.
3. **UI / Asset Verification**:
   - Inspect `assets/css/` and `assets/js/` to identify existing CSS classes, components (buttons, badges, tables), and JavaScript utilities (`api.js`).

---

### Step 3: Impact & Dependency Assessment
Identify cross-module dependencies and risks:
- **Financial Impact**: Does the change touch sales, expenses, or payments? If yes, ensure it respects the `DELIVERED` + `COLLECTED` realized revenue rule.
- **Inventory Impact**: Does the action alter product quantities? If yes, ensure it requires an explicit PDO transaction and logs to `inventory_movements`.
- **RBAC Impact**: Which roles (`admin`, `manager`, `cashier`) are permitted to access or execute this action?
- **Breaking Changes**: Will any existing API contract or table schema be disrupted?

---

### Step 4: Analysis Output Synthesis
Deliver a concise analysis summary before writing code:
```text
[Analysis Summary]
- Target Domain: <e.g., Orders / COD Workflow>
- Current Roadmap Phase: <e.g., Phase 3>
- Relevant Specs: docs/DATABASE_SCHEMA.md (orders, cod_payments), docs/BUSINESS_RULES.md (Sec 3)
- Impacted Files:
  - modules/orders/handler.php (New/Modified)
  - modules/orders/index.php (UI)
- Required Guards: check_permission('orders_create'), CSRF validation
- Invariants to Uphold: Wrap stock deduction in PDO transaction; log to inventory_movements.
```
