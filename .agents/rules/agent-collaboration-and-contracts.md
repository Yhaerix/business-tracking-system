---
trigger: always_on
description: Prohibits concurrent file editing and mandates contract-first API interface agreements between frontend and backend agents.
---

# Agent Collaboration & Contract-First Rules

All agents operating in this codebase MUST strictly adhere to the following file ownership and interface rules.

---

## 1. Contract-First Agreement Before Coding
Before any frontend view or backend handler is written:
1. **Interface Contract Lock**: Both agents must adhere to an agreed API contract defined in the plan:
   - Exact endpoint URL (e.g. `modules/products/handler.php?action=create`)
   - HTTP Method (`POST`, `GET`, etc.)
   - Expected Request Payload keys and data types (e.g. `{ name: string, price: float, stock: int }`)
   - Expected Response Schema `{ success: bool, message: string, data: any, errors: array }`
2. Once the contract is documented, the backend implements the endpoint to satisfy the contract, and the frontend builds the UI to consume that exact contract.

---

## 2. Strict File Ownership (Zero Concurrent File Editing)
To prevent race conditions, merge conflicts, and overwritten changes, agents have strict, non-overlapping file ownership boundaries:

| Agent | Permitted File Domains | Forbidden Files |
| :--- | :--- | :--- |
| **`backend-developer`** | - `modules/<domain>/handler.php`<br>- `config/*.php`<br>- `includes/*.php` (Auth, RBAC, CSRF, Audit) | **FORBIDDEN** from modifying HTML views (`index.php`), CSS files (`assets/css/*`), or client scripts. |
| **`frontend-developer`** | - `modules/<domain>/index.php` (Views)<br>- `assets/css/*.css`<br>- `assets/js/*.js` | **FORBIDDEN** from modifying backend handlers (`handler.php`), database connection pools, or server middleware. |
| **`database-specialist`**| - `docs/schema.sql`<br>- Migration scripts | **FORBIDDEN** from modifying application PHP code or frontend assets. |

---

## 3. Communication & Integration Protocol
1. **Frontend never writes raw queries**: Views only render data and call `API.get()` or `API.post()`.
2. **Backend never outputs HTML**: Handlers strictly output JSON headers (`Content-Type: application/json; charset=utf-8`) and standard payloads.
3. **Changes to Shared Schemas**: If a field name changes, the `planner` must update the contract first before either agent adjusts their respective files.

