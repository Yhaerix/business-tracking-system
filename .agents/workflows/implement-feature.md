---
name: implement-feature
trigger: /implement-feature
description: End-to-end automated development workflow orchestrating planner, database-specialist, backend-developer, frontend-developer, qa-tester, and code-reviewer.
---

# Feature Implementation Workflow (`/implement-feature`)

## 1. Overview & Purpose
This workflow automates the repeatable end-to-end software development lifecycle for any feature or milestone in the **Small Business Sales and Expense Tracking System**. It coordinates the 6 specialized subagents to guarantee that every change is planned, database-verified, securely coded on the backend, beautifully designed on the frontend, tested, and audited before completion.

---

## 2. Orchestration Pipeline

```mermaid
sequenceDiagram
    autonumber
    actor Coordinator as Coordinator (Main Agent)
    participant Planner as 1. planner
    participant DB as 2. database-specialist
    participant Backend as 3. backend-developer
    participant Frontend as 4. frontend-developer
    participant QA as 5. qa-tester
    participant Reviewer as 6. code-reviewer

    Coordinator->>Planner: 1. Decompose Task Request
    Note over Planner: Uses project-analysis skill.<br/>Reads docs/ specs & rules.<br/>Outputs Plan & Acceptance Criteria.
    Planner-->>Coordinator: Implementation Plan Artifact

    Coordinator->>DB: 2. Prepare Data Layer
    Note over DB: Uses database-management skill.<br/>Applies schema changes to docs/schema.sql.<br/>Enforces InnoDB, DECIMAL(12,2), FKs.
    DB-->>Coordinator: DDL Execution & Migration Report

    Coordinator->>Backend: 3. Implement PHP & APIs
    Note over Backend: Uses php-development skill.<br/>Enforces CSRF, RBAC, PDO transactions.<br/>Returns standard JSON response.
    Backend-->>Coordinator: Backend Handlers & API Endpoints

    Coordinator->>Frontend: 4. Build UI Views & Interactions
    Note over Frontend: Adheres to docs/UI_DESIGN_SPEC.md.<br/>Creates semantic HTML5, CSS tokens,<br/>modals, tables & Vanilla JS Fetch.
    Frontend-->>Coordinator: Responsive Views & CSS Deliverables

    Coordinator->>QA: 5. Execute Verification Suite
    Note over QA: Uses testing-verification skill.<br/>Tests math against BUSINESS_RULES.md.<br/>Probes edge cases & status codes.
    alt Tests Fail
        QA-->>Backend: Bug Report (Loop back to fix)
    else Tests Pass
        QA-->>Coordinator: QA Test Report [PASS]
    end

    Coordinator->>Reviewer: 6. Perform Final Audit
    Note over Reviewer: Uses security-review skill.<br/>Audits 5 dimensions (OWASP, DB, UX).
    alt Changes Requested
        Reviewer-->>Backend: Remediation Instructions
    else Approved
        Reviewer-->>Coordinator: Code Review Report [APPROVED]
    end

    Coordinator->>Coordinator: 7. Update Roadmap & Changelog
```

---

## 3. Step-by-Step Execution Protocol

### Step 1: Planning & Architecture Analysis
- **Assigned Agent**: `planner`
- **Skill**: `project-analysis`
- **Actions**:
  1. Inspect current state in `docs/DEVELOPMENT_ROADMAP.md`.
  2. Cross-reference `docs/PROJECT_REQUIREMENTS.md`, `docs/DATABASE_SCHEMA.md`, and `docs/BUSINESS_RULES.md`.
  3. Output a structured plan with clear acceptance criteria and subagent task assignments.

### Step 2: Database Preparation & Migrations
- **Assigned Agent**: `database-specialist`
- **Skill**: `database-management`
- **Actions**:
  1. Write idempotent DDL in `docs/schema.sql` (`CREATE TABLE IF NOT EXISTS`).
  2. Verify `ENGINE=InnoDB`, `utf8mb4`, `DECIMAL(12,2)` for money, and `ON DELETE RESTRICT`.
  3. Execute migration and seed any required baseline data.

### Step 3: Backend Handler & UI Construction
- **Assigned Agent**: `backend-developer`
- **Skill**: `php-development`, `feature-implementation`
- **Actions**:
  1. Implement `handler.php` with session guard (`require_login()`), RBAC check (`require_permission(...)`), and anti-CSRF token verification.
  2. Implement business logic wrapped in PDO transactions (`beginTransaction`, `commit`, `rollBack`).
  3. Render UI in `index.php` using shared layout (`header.php`, `sidebar.php`, `footer.php`) and escape outputs.
  4. Ensure AJAX endpoints return `{ success, message, data, errors }`.

### Step 4: QA & Acceptance Verification
- **Assigned Agent**: `qa-tester`
- **Skill**: `testing-verification`
- **Actions**:
  1. Execute unit and integration tests in `tests/`.
  2. Verify calculation math against `docs/BUSINESS_RULES.md`.
  3. Test negative stock prevention and COD state transitions.
  4. Confirm all acceptance criteria from Step 1 are satisfied.

### Step 5: Security & Standards Code Review
- **Assigned Agent**: `code-reviewer`
- **Skill**: `security-review`
- **Actions**:
  1. Evaluate code across the 5 dimensions: Security (OWASP), Correctness, Architecture, Database discipline, and Cleanliness.
  2. Block completion if any security vulnerability or rule violation is detected.
  3. Issue final `APPROVED` verdict.

### Step 6: Progress & Documentation Finalization
- **Assigned Agent**: Coordinator (Main)
- **Actions**:
  1. Check off completed items in `docs/DEVELOPMENT_ROADMAP.md` (`[x]`).
  2. Record the addition in `CHANGELOG.md` under `[Unreleased]`.
