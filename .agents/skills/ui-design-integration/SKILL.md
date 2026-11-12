---
name: ui-design-integration
description: Procedural workflow and engineering standards for translating validated UI designs, prototypes, and layouts into the production PHP modular application.
---

# UI Design Integration Skill

## 1. When to Use
Invoke this skill whenever:
- Porting approved designs, prototypes, or styles from `preview.html` into the production PHP application.
- Harmonizing stylesheets, layouts, typography, and component tokens between prototype files and `modules/`.
- Refactoring existing PHP views to adopt new design system patterns without breaking backend data contracts.

---

## 2. Core Principles

### Principle 1: Zero Functional Regression
The porting process is strictly visual and structural. You must **never** break or strip out:
- PHP authentication guards (`require_login()`) and RBAC checks (`require_permission(...)`).
- Anti-CSRF token inputs (`<?= csrf_field() ?>` or `$_SESSION['csrf_token']`).
- Existing server-side PHP data loops (`foreach ($records as $item): ... endforeach`).
- Security escaping (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).

### Principle 2: Architectural Modularity & File Ownership
In accordance with `.agents/rules/agent-collaboration-and-contracts.md`:
- Shared stylesheets belong strictly in `assets/css/`.
- Shared layout chrome (navbars, drawer, footers) belongs in `includes/header.php`, `includes/sidebar.php`, `includes/footer.php`.
- Domain views belong in `modules/<domain>/index.php`.
- Client-side interactivity belongs in `assets/js/<domain>.js` or `assets/js/main.js`.
- Handlers (`handler.php`) and database schema must **not** be modified during visual integration unless explicitly requested.

### Principle 3: Design Token Uniformity
All colors, font sizes, borders, elevations, and layout dimensions must reference global CSS custom properties (variables) defined in `assets/css/` rather than arbitrary inline styles.

---

## 3. Step-by-Step Porting Pipeline

### Step 1: Design Tokens & CSS Extraction
1. Extract global CSS rules, variables, resets, and layout grids from the design into `assets/css/app.css` (or `assets/css/variables.css` and `assets/css/components.css`).
2. Verify token names match across all views:
   - Primary palette: `--color-primary`, `--color-primary-hover`, `--color-primary-light`
   - Dark sidebar: `--sidebar-bg`, `--sidebar-text`, `--sidebar-active-bg`
   - Card & Surface: `--bg-surface`, `--bg-page`, `--border-color`, `--shadow-sm`, `--shadow-md`
   - Status & Badges: `--badge-success`, `--badge-warning`, `--badge-danger`, `--badge-info`
   - Spacing & Radius: `--radius-sm`, `--radius-md`, `--radius-lg`

### Step 2: Global Shell Harmonization
1. **Sidebar (`includes/sidebar.php`)**:
   - Verify exactly 10 navigation links organized under the canonical 5 section headers.
   - Match classes (`.app-sidebar`, `.sidebar-brand`, `.sidebar-nav`, `.nav-section-title`, `.nav-link`, `.sidebar-footer`).
   - Retain PHP active state detection: `<?= ($current_page === 'dashboard') ? 'active' : '' ?>`.
   - Retain RBAC visibility guards: `<?php if (has_permission(...)): ?> ... <?php endif; ?>`.
2. **Top Header (`includes/header.php`)**:
   - Match mobile hamburger toggle (`#menuToggle`), page breadcrumb (`#pageBreadcrumb`), and user profile badge.
   - Retain server-side user display: `<?= htmlspecialchars($_SESSION['full_name']) ?>`.
3. **Footer (`includes/footer.php`)**:
   - Close layout containers (`</main>`, `</div>`, `</div>`).
   - Load modal containers and modular JavaScript scripts cleanly.

### Step 3: Domain View Modularization (`modules/<domain>/index.php`)
For each screen in the prototype:
1. Extract the `<section class="screen-view">` content into `modules/<domain>/index.php`.
2. Replace static mockup table rows and cards with dynamic PHP loops:
   ```php
   <?php foreach ($items as $item): ?>
       <tr>
           <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
           <td><?= htmlspecialchars($item['name']) ?></td>
           <td>₱<?= number_format($item['price'], 2) ?></td>
           ...
       </tr>
   <?php endforeach; ?>
   ```
3. Ensure every form includes the CSRF token:
   ```html
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
   ```
4. Verify empty states render cleanly when collections are empty (`<?php if (empty($items)): ?> ... <?php endif; ?>`).

### Step 4: Modals & Drawer Extraction
1. Extract modal dialogs into reusable partials in `includes/modals/` or within the corresponding module directory.
2. Ensure modal triggers (`onclick="openModal('...')"` or `data-modal-target="..."`) match the JavaScript modal controller.
3. Validate modal close buttons, backdrop click dismissals, and Escape key listeners.

### Step 5: Client-Side Interaction & AJAX Binding
1. Place client-side interactions in `assets/js/<domain>.js` or shared `assets/js/main.js`.
2. Connect AJAX calls (`fetch`) to the existing backend contracts in `modules/<domain>/handler.php`.
3. Ensure request payloads match handler expectations:
   - Method: `POST` with `Content-Type: application/json` or `FormData`.
   - Headers: Include `X-CSRF-Token` or form field `csrf_token`.
   - Response handling: Validate `{ success: true, message: "...", data: ... }`.

### Step 6: Quality & Visual Verification
1. Run automated PHP tests: `php tests/run_tests.php`.
2. Verify browser console has **0** errors (no uncaught exceptions or undefined tokens).
3. Test responsive views (mobile 375px, tablet 768px, desktop 1280px+).
4. Verify print stylesheets for invoices and receipts (thermal 80mm & A4).

