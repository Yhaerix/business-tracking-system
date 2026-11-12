---
name: frontend-development
description: Standards, patterns, and conventions for developing accessible, responsive, high-performance HTML5 views, modular CSS3, and Vanilla JavaScript (Fetch API) in the Business Tracking System.
---

# Frontend Development Skill

## 1. When to Use
Invoke this skill whenever:
- Creating or updating user interface views in `modules/<domain>/index.php`.
- Styling components, cards, tables, status badges, forms, and responsive layouts in `assets/css/`.
- Writing or refactoring client-side JavaScript controllers in `assets/js/`.
- Integrating frontend user actions with backend JSON endpoints (`handler.php`).

---

## 2. Technical Stack & Architectural Constraints
- **Frameworkless Architecture**: Zero heavy frontend frameworks (no React, Vue, Angular, or jQuery). Zero Node.js build pipelines or bundlers (no Webpack, Vite, or Babel).
- **Standards-Compliant Stack**:
  - Semantic **HTML5** with accessibility attributes (`aria-*`, `role`).
  - Modular **CSS3** utilizing CSS Custom Properties (Design Tokens), Flexbox, and CSS Grid.
  - Modern **Vanilla JavaScript** (ES6+) with the native `fetch()` API and DOM manipulation.
- **Strict File Boundaries**:
  - Frontend developers write to `modules/<domain>/index.php`, `assets/css/*`, and `assets/js/*`.
  - Frontend developers do **not** write raw SQL queries or alter backend database connection logic.
  - All data operations must communicate through documented backend endpoints using standard JSON contracts.

---

## 3. Core UI Patterns & Best Practices

### Pattern 1: Safe DOM Rendering & XSS Prevention
When rendering server data in PHP views:
```php
<!-- Always escape dynamic strings -->
<td><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($product['sku'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
<td>₱<?= number_format((float)$product['price'], 2) ?></td>
```

When rendering dynamic data in Vanilla JavaScript:
- Prefer `element.textContent = data` for plain text.
- If constructing HTML dynamically, sanitize inputs before injection, or create DOM nodes (`document.createElement`).
- Never inject unescaped customer notes or user inputs directly into `element.innerHTML`.

### Pattern 2: AJAX & API Communication Contract
All client requests to backend handlers must follow this standard pattern:
```javascript
async function callApi(url, method = 'POST', payload = null) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') 
                   || document.querySelector('input[name="csrf_token"]')?.value;

    const headers = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    };

    const options = { method, headers };

    if (payload) {
        if (payload instanceof FormData) {
            if (csrfToken) payload.append('csrf_token', csrfToken);
            options.body = payload;
        } else {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify({ ...payload, csrf_token: csrfToken });
        }
    }

    try {
        const response = await fetch(url, options);
        const result = await response.json();
        
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Operation failed');
        }
        return result;
    } catch (err) {
        showToast(err.message, 'error');
        throw err;
    }
}
```

### Pattern 3: Responsive Sidebar Drawer
Ensure mobile navigation functions reliably across all device widths:
- Hamburger button toggles `.open` on `#appSidebar` and `#sidebarBackdrop`.
- Clicking the backdrop or navigating to a route automatically closes the drawer on viewports $< 768\text{px}$.
- Viewport meta tag is always present: `<meta name="viewport" content="width=device-width, initial-scale=1.0">`.

### Pattern 4: Modal & Lightview Controller
Modals must follow accessible, robust dialog patterns:
1. Open modal: Add `.active` or `.show` class to modal overlay, set `aria-hidden="false"`.
2. Close modal: Remove active class, reset forms if appropriate, set `aria-hidden="true"`.
3. Dismiss triggers:
   - Click close button (`.btn-close` / `[data-close]`).
   - Click backdrop overlay (`event.target === modalOverlay`).
   - Press `Escape` key.
4. Prevent background scrolling while modal is open (`document.body.classList.add('modal-open')`).

### Pattern 5: POS Shopping Cart & Real-Time Calculation
- Cart state is managed in a clean in-memory object keyed by product ID/SKU.
- Re-calculate totals instantly on quantity change:
  - $\text{Subtotal} = \sum (\text{item.price} \times \text{item.qty})$
  - $\text{Tax} = 0.00$ (Zero Tax invariant)
  - $\text{Grand Total} = \text{Subtotal} + \text{Delivery Fee} - \text{Discount}$
  - $\text{Change} = \max(0, \text{Tendered} - \text{Grand Total})$
- Stock validation: Enforce `item.qty <= item.max_stock` to prevent negative stock at the client level before submitting.

### Pattern 6: Print Stylesheet Standards
For printable views (`invoices`, `receipts`):
```css
@media print {
    body { background: #fff !important; color: #000 !important; }
    .app-sidebar, .app-header, .btn, .no-print { display: none !important; }
    .print-only { display: block !important; }
    .invoice-container { box-shadow: none !important; border: none !important; margin: 0 !important; }
    
    /* Thermal 80mm Receipt */
    @page { size: 80mm auto; margin: 2mm; }
}
```

---

## 4. UI Quality Checklist
Before handing off any frontend code:
- [ ] **Console Cleanliness**: Zero JavaScript errors, warnings, or broken asset paths.
- [ ] **String Safety**: Zero raw unescaped newlines in JavaScript string literals.
- [ ] **CSRF Protection**: All POST forms and AJAX requests pass the anti-CSRF token.
- [ ] **Visual Hierarchy**: Cards, tables, KPI metric badges, and typography strictly match design tokens.
- [ ] **Empty States**: Data tables render clear, styled empty states when no records exist.
- [ ] **Feedback**: Actions trigger visual loading states, success toasts, or error alerts.

