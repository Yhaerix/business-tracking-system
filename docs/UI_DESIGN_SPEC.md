# UI Design Specification & Design System

## 1. Design Philosophy & Overview
- **Aesthetic**: Clean, modern, high-contrast, data-dense interface designed for small business operational efficiency.
- **Theme Mode**: Crisp light theme with subtle dark slate accents for contrast, optimized for high legibility under retail/counter lighting.
- **Responsiveness**: Mobile-first responsive design supporting smartphones (for delivery riders/quick checks), tablets (POS counter checkout), and desktop monitors (management and reports).
- **Zero Heavy Frameworks**: Pure semantic HTML5 and vanilla CSS3 utilizing CSS Custom Properties (CSS variables) for lightweight, instantaneous page loads on local XAMPP/Apache.

---

## 2. Design Tokens & Palette

### 2.1. Color System
All colors are defined via CSS custom properties in `assets/css/main.css`:

```css
:root {
  /* Brand / Primary */
  --color-primary: #2563eb;          /* Vibrant Royal Blue */
  --color-primary-hover: #1d4ed8;
  --color-primary-light: #eff6ff;

  /* Financial Status Colors */
  --color-success: #16a34a;          /* Green: Delivered orders, COD collected, Net Profit */
  --color-success-bg: #f0fdf4;
  --color-success-border: #bbf7d0;

  --color-warning: #f59e0b;          /* Amber: Pending orders, Dispatched COD, Review */
  --color-warning-bg: #fffbeb;
  --color-warning-border: #fde68a;

  --color-danger: #dc2626;           /* Red: Expenses, Cancelled orders, Low stock alert */
  --color-danger-bg: #fef2f2;
  --color-danger-border: #fecaca;

  --color-info: #0284c7;             /* Sky: Restock movements, System notifications */
  --color-info-bg: #f0f9ff;
  --color-info-border: #bae6fd;

  /* Neutral Surface & Typography */
  --bg-body: #f8fafc;                /* Slate 50: Clean app background */
  --bg-surface: #ffffff;             /* Pure White: Cards, tables, modals */
  --bg-sidebar: #0f172a;             /* Slate 900: Dark contrasting sidebar */
  --bg-sidebar-hover: #1e293b;       /* Slate 800: Active navigation links */

  --text-main: #0f172a;              /* Slate 900: High-contrast primary text */
  --text-muted: #64748b;             /* Slate 500: Secondary labels, metadata */
  --text-inverse: #ffffff;           /* White text on dark sidebar & buttons */

  /* Borders & Dividers */
  --border-color: #e2e8f0;           /* Slate 200: Subtle card & table borders */
  --border-focus: #3b82f6;           /* Focus ring color */

  /* Shadows */
  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
  --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
  --shadow-modal: 0 20px 25px -5px rgb(0 0 0 / 0.15), 0 8px 10px -6px rgb(0 0 0 / 0.1);

  /* Radius */
  --radius-sm: 6px;
  --radius-md: 8px;
  --radius-lg: 12px;
  --radius-full: 9999px;
}
```

### 2.2. Typography
- **Font Family**: Modern system UI stack:
  ```css
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  ```
- **Monetary & Numbers**: Monospace or tabular figures for aligned currency columns:
  ```css
  font-variant-numeric: tabular-nums;
  ```
- **Scale**:
  - `Display / KPI`: `28px` / `32px` (Bold - `700`)
  - `H1 (Page Title)`: `22px` / `28px` (Semi-bold - `600`)
  - `H2 (Section Header)`: `18px` / `24px` (Semi-bold - `600`)
  - `Body / Standard`: `14px` / `20px` (Regular - `400`)
  - `Small / Metadata / Badges`: `12px` / `16px` (Medium - `500`)

---

## 3. Global App Shell & Layout Grid

### 3.1. Desktop Layout (Width $\ge 1024\text{px}$)
Fixed dark sidebar on the left, sticky top header bar, and scrollable main content area:

```text
+---------------------+---------------------------------------------------------------+
| LOGO / BRAND        | Top Nav: Breadcrumb | Low-Stock Badge | User Menu (Role) | Logout |
+---------------------+---------------------------------------------------------------+
| [icon] Dashboard    |                                                               |
| [icon] Products     |  Page Title / Actions Button (e.g. "+ New Order")             |
| [icon] Inventory    |                                                               |
| [icon] Orders & COD |  [ Stat Card 1 ] [ Stat Card 2 ] [ Stat Card 3 ] [ Stat 4 ]   |
| [icon] Expenses     |                                                               |
| [icon] Invoices     |  +---------------------------------------------------------+  |
| [icon] Reports      |  | Main Data Table / Content Container                     |  |
| [icon] Audit Trail  |  |                                                         |  |
| [icon] Settings     |  +---------------------------------------------------------+  |
+---------------------+---------------------------------------------------------------+
```

### 3.2. Mobile Layout (Width $< 1024\text{px}$)
- Sidebar collapses into an off-canvas slide-out drawer toggled by a hamburger button (`☰`).
- Stat cards stack into a 2-column or 1-column grid.
- Tables gain smooth horizontal scrolling (`overflow-x: auto`) with sticky header rows.

---

## 4. Reusable Component Standards (`assets/css/components.css`)

### 4.1. Stat Cards (Metrics & KPIs)
Compact white cards with border, icon badge, label, and large numeric value:
```text
+-------------------------------+
| Today's Net Income      [💰]  |
| ₱ 14,250.00                   |
| 🟢 +12% vs yesterday          |
+-------------------------------+
```

### 4.2. Status Badges
Pill-shaped badges with soft background and dark text for fast scanning:
- `.badge-pending`: Amber background (`#fffbeb`), Amber text (`#b45309`)
- `.badge-confirmed`: Blue background (`#eff6ff`), Blue text (`#1d4ed8`)
- `.badge-dispatched`: Purple background (`#faf5ff`), Purple text (`#7e22ce`)
- `.badge-delivered`: Green background (`#f0fdf4`), Green text (`#15803d`)
- `.badge-remitted`: Emerald background (`#ecfdf5`), Emerald text (`#047857`)
- `.badge-cancelled`: Red background (`#fef2f2`), Red text (`#b91c1c`)

### 4.3. Data Tables
- Clean white surface with subtle bottom borders (`--border-color`).
- Hover row highlighting (`#f8fafc`).
- Right-aligned numeric columns (Quantities, Unit Costs, Totals) with tabular numbers.
- Integrated search bar, date range picker, and pagination footer.

### 4.4. Modal Dialogs
- Centered overlay with backdrop blur (`backdrop-filter: blur(2px)`).
- Max width: `550px` for standard CRUD forms, `850px` for complex POS cart.
- Keyboard accessible (`Esc` to close) with clear primary and cancel action buttons.

---

## 5. Dedicated Key Screen Layouts

### 5.1. POS / Order Creation Interface (Split View)
Optimized for rapid checkout counter entry on tablet or desktop:
```text
+------------------------------------------+------------------------------------------+
| LEFT PANE: Product Selector & Lookup     | RIGHT PANE: COD Cart & Customer Details   |
|                                          |                                          |
| [ Search SKU / Product Name...       ]   | Customer: [ Recipient Name           ]   |
| Filter: [ All Categories ▼ ]             | Phone:    [ 0917-XXX-XXXX            ]   |
|                                          | Address:  [ Full delivery address... ]   |
| [Card: Widget A]  ₱250.00  Stock: 14 [+] |                                          |
| [Card: Widget B]  ₱120.00  Stock: 0  [x] | Cart Items:                              |
| [Card: Widget C]  ₱450.00  Stock: 3  [+] |  - 2x Widget A       ₱500.00  [x]        |
|                                          |  - 1x Widget C       ₱450.00  [x]        |
|                                          | ---------------------------------------- |
|                                          | Subtotal:           ₱950.00              |
|                                          | Discount:        -  ₱ 50.00              |
|                                          | Delivery Fee:    +  ₱ 80.00              |
|                                          | TOTAL (COD):        ₱980.00              |
|                                          |                                          |
|                                          | [ CONFIRM & DISPATCH ORDER (COD) ]       |
+------------------------------------------+------------------------------------------+
```

### 5.2. Print Stylesheets (`assets/css/print.css`)
- **Thermal Receipt (80mm)**:
  - Width fixed to `80mm` / `300px`, font size `12px` monospace.
  - Strips out web navigation, sidebar, background colors, and action buttons.
  - Centered business header, clean itemized rows, total COD collection due, and receipt barcode/serial.
- **Formal Invoice (A4)**:
  - Clean black-and-white A4 layout with business header, customer block, invoice serial (`INV-YYYY-XXXX`), line item table, and signature/delivery handover lines.

