<?php
/**
 * Split-screen POS / Order Creation Interface (Cash on Delivery)
 * Small Business Sales and Expense Tracking System
 *
 * File: modules/orders/create.php
 */

declare(strict_types=1);

$pageTitle = 'POS / Create COD Order';

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_login();
require_permission('orders_create');

$db = get_db();

// Pre-load active catalog categories for the filter toolbar
$stmtCat = $db->query("SELECT id, name FROM categories ORDER BY name ASC");
$categories = $stmtCat->fetchAll();

// Pre-load active products for instant POS response with zero network latency
$stmtProd = $db->query("
    SELECT 
        p.id, 
        p.category_id, 
        p.name, 
        p.sku, 
        p.selling_price, 
        p.current_stock, 
        p.low_stock_threshold, 
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.deleted_at IS NULL AND p.status = 'active'
    ORDER BY p.name ASC
");
$products = $stmtProd->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* POS Split-Screen Layout */
.pos-container {
    display: grid;
    grid-template-columns: 1fr 440px;
    gap: 24px;
    align-items: flex-start;
}

@media (max-width: 1024px) {
    .pos-container {
        grid-template-columns: 1fr;
    }
}

/* Catalog Column */
.pos-catalog-column {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
}

.pos-search-bar {
    display: flex;
    gap: 12px;
    align-items: center;
    background: var(--bg-surface);
    padding: 14px 18px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-color);
}

.pos-category-pills {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: thin;
}

.pos-category-pills::-webkit-scrollbar {
    height: 4px;
}

.pos-category-pills::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 4px;
}

.category-pill {
    padding: 6px 14px;
    font-size: 12.5px;
    font-weight: 500;
    border-radius: var(--radius-full);
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
    color: var(--text-muted);
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}

.category-pill:hover,
.category-pill.active {
    background: var(--color-primary);
    color: #ffffff;
    border-color: var(--color-primary);
}

/* Product Cards Grid */
.pos-product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 14px;
}

.pos-product-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 14px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 145px;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    user-select: none;
}

.pos-product-card:hover:not(.out-of-stock) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--border-focus);
}

.pos-product-card.out-of-stock {
    opacity: 0.55;
    cursor: not-allowed;
    background: #f8fafc;
}

.pos-product-sku {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
    letter-spacing: 0.02em;
    margin-bottom: 4px;
}

.pos-product-name {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-main);
    line-height: 1.35;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.pos-product-price {
    font-size: 15px;
    font-weight: 700;
    color: var(--color-primary);
    font-variant-numeric: tabular-nums;
    margin-bottom: 6px;
}

.pos-product-stock-tag {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    font-weight: 600;
    border-radius: var(--radius-sm);
    padding: 2px 6px;
    width: fit-content;
}

.pos-product-stock-tag.in-stock {
    background: var(--color-success-bg);
    color: var(--color-success);
    border: 1px solid var(--color-success-border);
}

.pos-product-stock-tag.low-stock {
    background: var(--color-warning-bg);
    color: #b45309;
    border: 1px solid var(--color-warning-border);
}

.pos-product-stock-tag.out-of-stock-tag {
    background: var(--color-danger-bg);
    color: var(--color-danger);
    border: 1px solid var(--color-danger-border);
}

/* Right Column: Checkout & Cart */
.pos-checkout-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: 20px;
    position: sticky;
    top: calc(var(--header-height) + 16px);
}

.pos-cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 12px;
}

.pos-cart-items-container {
    max-height: 230px;
    overflow-y: auto;
    margin-bottom: 16px;
    padding-right: 4px;
}

.pos-cart-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px dashed var(--border-color);
    gap: 8px;
}

.pos-cart-item:last-child {
    border-bottom: none;
}

.pos-cart-item-info {
    flex: 1;
    min-width: 0;
}

.pos-cart-item-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pos-cart-item-unit {
    font-size: 11.5px;
    color: var(--text-muted);
}

.pos-cart-qty-ctrl {
    display: flex;
    align-items: center;
    gap: 4px;
}

.qty-btn {
    width: 24px;
    height: 24px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-color);
    background: var(--bg-body);
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text-main);
    transition: background 0.15s ease;
}

.qty-btn:hover:not(:disabled) {
    background: var(--border-color);
}

.qty-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.qty-num {
    font-size: 13px;
    font-weight: 600;
    min-width: 24px;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.pos-cart-item-total {
    font-size: 13.5px;
    font-weight: 600;
    min-width: 65px;
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.pos-cart-item-remove {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 14px;
    padding: 4px;
    border-radius: 4px;
    transition: color 0.15s ease;
}

.pos-cart-item-remove:hover {
    color: var(--color-danger);
}

/* Empty Cart State */
.empty-cart-box {
    text-align: center;
    padding: 24px 12px;
    color: var(--text-muted);
    font-size: 13px;
}

/* Calculations & Totals */
.pos-calculations {
    background: #f8fafc;
    border-radius: var(--radius-md);
    padding: 12px 14px;
    margin-bottom: 16px;
    border: 1px solid var(--border-color);
}

.calc-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    margin-bottom: 8px;
}

.calc-row:last-child {
    margin-bottom: 0;
}

.calc-input-wrap {
    width: 110px;
}

.calc-input-wrap input {
    text-align: right;
    padding: 4px 8px;
    font-size: 13px;
}

/* COD Total Due Highlight Box */
.cod-total-box {
    background: var(--color-primary-light);
    border: 1.5px solid #bfdbfe;
    border-radius: var(--radius-md);
    padding: 12px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.cod-total-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--color-primary);
    letter-spacing: 0.04em;
}

.cod-total-value {
    font-size: 24px;
    font-weight: 800;
    color: var(--color-primary);
    font-variant-numeric: tabular-nums;
}

/* Recipient Form Details */
.recipient-section {
    border-top: 1px solid var(--border-color);
    padding-top: 14px;
    margin-bottom: 16px;
}

.recipient-section .section-title {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 10px;
    letter-spacing: 0.03em;
}

.recipient-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 8px;
}

.checkout-actions {
    display: flex;
    gap: 10px;
}
</style>

<!-- Page Header Bar -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">POS / Create COD Order</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            Fast counter & phone sales dispatch. Deducts inventory immediately with full Cash-on-Delivery audit tracking.
        </p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/orders/" class="btn btn-outline">
            📦 View All Orders
        </a>
    </div>
</div>

<!-- POS Split Layout -->
<div class="pos-container">
    <!-- LEFT COLUMN: Product Catalog -->
    <div class="pos-catalog-column">
        <!-- Search & Filter Controls -->
        <div class="pos-search-bar">
            <span style="font-size: 16px; color: var(--text-muted);">🔍</span>
            <input 
                type="text" 
                id="catalogSearchInput" 
                class="form-input" 
                placeholder="Search products by title or SKU..." 
                style="border: none; padding: 4px 0; font-size: 14px; box-shadow: none;"
            >
            <button type="button" id="clearSearchBtn" class="btn btn-outline btn-sm" style="display: none; padding: 4px 8px;">
                ✕
            </button>
        </div>

        <!-- Category Filter Pills -->
        <div class="pos-category-pills" id="categoryPills">
            <button type="button" class="category-pill active" data-category-id="all">
                All Products (<?= count($products) ?>)
            </button>
            <?php foreach ($categories as $cat): ?>
                <button type="button" class="category-pill" data-category-id="<?= (int)$cat['id'] ?>">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Product Grid -->
        <div class="pos-product-grid" id="productGrid">
            <!-- Rendered dynamically by JavaScript -->
        </div>

        <!-- Empty Filter Results -->
        <div id="noProductsFound" style="display: none; text-align: center; padding: 48px 20px; background: var(--bg-surface); border-radius: var(--radius-lg); border: 1px dashed var(--border-color);">
            <div style="font-size: 32px; margin-bottom: 8px;">🔍</div>
            <div style="font-size: 15px; font-weight: 600; color: var(--text-main);">No matching products found</div>
            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">Try modifying your search or select a different category.</div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Cart & Checkout Form -->
    <div class="pos-checkout-column">
        <div class="pos-checkout-card">
            <!-- Cart Title -->
            <div class="pos-cart-header">
                <div style="font-size: 15px; font-weight: 700; color: var(--text-main);">
                    🛒 Current Order (<span id="cartCount">0</span> items)
                </div>
                <button type="button" class="btn btn-outline btn-sm" id="clearCartBtn" style="padding: 4px 8px; font-size: 11.5px;">
                    Clear Cart
                </button>
            </div>

            <!-- Cart Line Items -->
            <div class="pos-cart-items-container" id="cartItemsList">
                <div class="empty-cart-box" id="emptyCartNotice">
                    <div style="font-size: 28px; margin-bottom: 6px;">🛍️</div>
                    <div style="font-weight: 600; color: var(--text-main);">Cart is empty</div>
                    <div style="font-size: 12px; margin-top: 2px;">Click available products on the left to add items.</div>
                </div>
            </div>

            <!-- Financial Calculations -->
            <div class="pos-calculations">
                <div class="calc-row">
                    <span style="color: var(--text-muted);">Subtotal</span>
                    <span id="subtotalVal" class="tabular-nums" style="font-weight: 600;"><?= APP_CURRENCY ?>0.00</span>
                </div>
                <div class="calc-row">
                    <span style="color: var(--text-muted);">Discount (<?= APP_CURRENCY ?>)</span>
                    <div class="calc-input-wrap">
                        <input type="number" id="discountInput" class="form-input" min="0" step="0.01" value="0.00">
                    </div>
                </div>
                <div class="calc-row">
                    <span style="color: var(--text-muted);">Delivery Fee (<?= APP_CURRENCY ?>)</span>
                    <div class="calc-input-wrap">
                        <input type="number" id="deliveryFeeInput" class="form-input" min="0" step="0.01" value="0.00">
                    </div>
                </div>
            </div>

            <!-- Large COD Total Due Highlight Box -->
            <div class="cod-total-box">
                <div>
                    <div class="cod-total-label">COD Total Due</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Strict zero-tax calculation</div>
                </div>
                <div class="cod-total-value" id="totalDueVal">
                    <?= APP_CURRENCY ?>0.00
                </div>
            </div>

            <!-- Recipient & Delivery Information -->
            <form id="orderForm" onsubmit="return false;">
                <div class="recipient-section">
                    <div class="section-title">Delivery & Customer Details</div>
                    <div class="recipient-grid">
                        <div>
                            <input 
                                type="text" 
                                id="custName" 
                                class="form-input" 
                                placeholder="Customer Name *" 
                                required 
                                maxlength="100"
                            >
                        </div>
                        <div>
                            <input 
                                type="tel" 
                                id="custPhone" 
                                class="form-input" 
                                placeholder="Phone Number *" 
                                required 
                                maxlength="30"
                            >
                        </div>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <textarea 
                            id="custAddress" 
                            class="form-textarea" 
                            rows="2" 
                            placeholder="Complete Delivery Address (Street, Brgy, City) *" 
                            required
                        ></textarea>
                    </div>
                    <div>
                        <input 
                            type="text" 
                            id="custNotes" 
                            class="form-input" 
                            placeholder="Delivery Notes (Optional, e.g. landmark, gate code)" 
                            maxlength="255"
                        >
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" id="submitOrderBtn" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 15px; font-weight: 600;">
                    Confirm & Dispatch COD Order
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Initial Product Catalog Data from PHP
    const products = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const currency = '<?= APP_CURRENCY ?>';

    // 2. State Management
    let currentCategory = 'all';
    let searchQuery = '';
    const cart = new Map(); // Key: product_id, Value: { id, name, sku, price, current_stock, qty }

    // 3. DOM Elements
    const productGrid = document.getElementById('productGrid');
    const noProductsFound = document.getElementById('noProductsFound');
    const catalogSearchInput = document.getElementById('catalogSearchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const categoryPills = document.querySelectorAll('.category-pill');

    const cartItemsList = document.getElementById('cartItemsList');
    const emptyCartNotice = document.getElementById('emptyCartNotice');
    const cartCountEl = document.getElementById('cartCount');
    const subtotalValEl = document.getElementById('subtotalVal');
    const discountInput = document.getElementById('discountInput');
    const deliveryFeeInput = document.getElementById('deliveryFeeInput');
    const totalDueValEl = document.getElementById('totalDueVal');
    const clearCartBtn = document.getElementById('clearCartBtn');

    const custName = document.getElementById('custName');
    const custPhone = document.getElementById('custPhone');
    const custAddress = document.getElementById('custAddress');
    const custNotes = document.getElementById('custNotes');
    const orderForm = document.getElementById('orderForm');
    const submitOrderBtn = document.getElementById('submitOrderBtn');

    // Utility: XSS Escaping
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Currency Formatter
    function formatCurrency(amount) {
        return currency + (Number(amount) || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // 4. Render Product Grid
    function renderProductGrid() {
        const filtered = products.filter(p => {
            const matchesCat = currentCategory === 'all' || String(p.category_id) === String(currentCategory);
            const term = searchQuery.toLowerCase().trim();
            const matchesSearch = !term || 
                p.name.toLowerCase().includes(term) || 
                (p.sku && p.sku.toLowerCase().includes(term));
            return matchesCat && matchesSearch;
        });

        productGrid.innerHTML = '';

        if (filtered.length === 0) {
            noProductsFound.style.display = 'block';
            return;
        }

        noProductsFound.style.display = 'none';

        filtered.forEach(p => {
            const stock = parseInt(p.current_stock, 10);
            const threshold = parseInt(p.low_stock_threshold, 10);
            const isOutOfStock = stock <= 0;
            const isLowStock = stock > 0 && stock <= threshold;

            let tagClass = 'in-stock';
            let tagText = `In Stock: ${stock}`;

            if (isOutOfStock) {
                tagClass = 'out-of-stock-tag';
                tagText = 'Out of Stock: 0';
            } else if (isLowStock) {
                tagClass = 'low-stock';
                tagText = `Low Stock: ${stock}`;
            }

            const card = document.createElement('div');
            card.className = `pos-product-card ${isOutOfStock ? 'out-of-stock' : ''}`;
            card.innerHTML = `
                <div>
                    <div class="pos-product-sku">${escapeHtml(p.sku || 'SKU-N/A')}</div>
                    <div class="pos-product-name" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</div>
                </div>
                <div>
                    <div class="pos-product-price">${formatCurrency(p.selling_price)}</div>
                    <div class="pos-product-stock-tag ${tagClass}">${tagText}</div>
                </div>
            `;

            if (!isOutOfStock) {
                card.addEventListener('click', () => addToCart(p));
            } else {
                card.addEventListener('click', () => {
                    API.toast(`"${p.name}" is currently out of stock.`, 'warning');
                });
            }

            productGrid.appendChild(card);
        });
    }

    // 5. Cart Logic
    function addToCart(product) {
        const pid = parseInt(product.id, 10);
        const stock = parseInt(product.current_stock, 10);

        if (stock <= 0) {
            API.toast(`"${product.name}" is out of stock.`, 'error');
            return;
        }

        if (cart.has(pid)) {
            const item = cart.get(pid);
            if (item.qty >= stock) {
                API.toast(`Cannot add more. Maximum available stock (${stock}) reached for "${product.name}".`, 'warning');
                return;
            }
            item.qty += 1;
        } else {
            cart.set(pid, {
                id: pid,
                name: product.name,
                sku: product.sku || '',
                price: parseFloat(product.selling_price) || 0,
                current_stock: stock,
                qty: 1
            });
        }

        renderCart();
    }

    function updateItemQty(pid, delta) {
        if (!cart.has(pid)) return;
        const item = cart.get(pid);
        const newQty = item.qty + delta;

        if (newQty <= 0) {
            cart.delete(pid);
        } else if (newQty > item.current_stock) {
            API.toast(`Only ${item.current_stock} available in stock.`, 'warning');
            return;
        } else {
            item.qty = newQty;
        }

        renderCart();
    }

    function removeItem(pid) {
        cart.delete(pid);
        renderCart();
    }

    function clearCart() {
        if (cart.size > 0 && !confirm('Are you sure you want to clear the entire cart?')) {
            return;
        }
        cart.clear();
        discountInput.value = '0.00';
        deliveryFeeInput.value = '0.00';
        renderCart();
    }

    // 6. Render Cart & Calculate Totals
    function renderCart() {
        cartItemsList.innerHTML = '';

        if (cart.size === 0) {
            cartItemsList.appendChild(emptyCartNotice);
            emptyCartNotice.style.display = 'block';
            cartCountEl.textContent = '0';
            subtotalValEl.textContent = formatCurrency(0);
            totalDueValEl.textContent = formatCurrency(0);
            return;
        }

        emptyCartNotice.style.display = 'none';

        let subtotal = 0;
        let totalItemsCount = 0;

        cart.forEach(item => {
            const lineTotal = item.price * item.qty;
            subtotal += lineTotal;
            totalItemsCount += item.qty;

            const row = document.createElement('div');
            row.className = 'pos-cart-item';
            row.innerHTML = `
                <div class="pos-cart-item-info">
                    <div class="pos-cart-item-title" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
                    <div class="pos-cart-item-unit">${formatCurrency(item.price)} each</div>
                </div>
                <div class="pos-cart-qty-ctrl">
                    <button type="button" class="qty-btn" data-action="dec" data-id="${item.id}">−</button>
                    <span class="qty-num">${item.qty}</span>
                    <button type="button" class="qty-btn" data-action="inc" data-id="${item.id}" ${item.qty >= item.current_stock ? 'disabled' : ''}>+</button>
                </div>
                <div class="pos-cart-item-total">${formatCurrency(lineTotal)}</div>
                <button type="button" class="pos-cart-item-remove" data-action="remove" data-id="${item.id}" title="Remove item">✕</button>
            `;

            // Event Listeners for controls
            row.querySelector('[data-action="dec"]').addEventListener('click', () => updateItemQty(item.id, -1));
            row.querySelector('[data-action="inc"]').addEventListener('click', () => updateItemQty(item.id, 1));
            row.querySelector('[data-action="remove"]').addEventListener('click', () => removeItem(item.id));

            cartItemsList.appendChild(row);
        });

        cartCountEl.textContent = totalItemsCount;

        // Calculate Totals: Strictly Subtotal - Discount + Delivery Fee (Zero Tax)
        const discount = Math.max(0, parseFloat(discountInput.value) || 0);
        const deliveryFee = Math.max(0, parseFloat(deliveryFeeInput.value) || 0);
        const codTotalDue = Math.max(0, subtotal - discount + deliveryFee);

        subtotalValEl.textContent = formatCurrency(subtotal);
        totalDueValEl.textContent = formatCurrency(codTotalDue);
    }

    // 7. Event Listeners for Filters & Inputs
    catalogSearchInput.addEventListener('input', (e) => {
        searchQuery = e.target.value;
        clearSearchBtn.style.display = searchQuery ? 'inline-flex' : 'none';
        renderProductGrid();
    });

    clearSearchBtn.addEventListener('click', () => {
        catalogSearchInput.value = '';
        searchQuery = '';
        clearSearchBtn.style.display = 'none';
        catalogSearchInput.focus();
        renderProductGrid();
    });

    categoryPills.forEach(pill => {
        pill.addEventListener('click', () => {
            categoryPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            currentCategory = pill.getAttribute('data-category-id');
            renderProductGrid();
        });
    });

    discountInput.addEventListener('input', renderCart);
    deliveryFeeInput.addEventListener('input', renderCart);
    clearCartBtn.addEventListener('click', clearCart);

    // 8. Order Submission
    orderForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (cart.size === 0) {
            API.toast('Please add at least one product to the cart before submitting.', 'warning');
            return;
        }

        const name = custName.value.trim();
        const phone = custPhone.value.trim();
        const address = custAddress.value.trim();
        const notes = custNotes.value.trim();
        const discount = Math.max(0, parseFloat(discountInput.value) || 0);
        const deliveryFee = Math.max(0, parseFloat(deliveryFeeInput.value) || 0);

        if (!name) {
            API.toast('Customer name is required.', 'error');
            custName.focus();
            return;
        }

        if (!phone) {
            API.toast('Customer phone number is required.', 'error');
            custPhone.focus();
            return;
        }

        if (!address) {
            API.toast('Complete delivery address is required.', 'error');
            custAddress.focus();
            return;
        }

        const itemsPayload = [];
        cart.forEach(item => {
            itemsPayload.push({
                product_id: item.id,
                quantity: item.qty,
                unit_price: item.price
            });
        });

        submitOrderBtn.disabled = true;
        submitOrderBtn.textContent = 'Processing & Reserving Stock...';

        const payload = {
            action: 'create_order',
            customer_name: name,
            customer_phone: phone,
            delivery_address: address,
            delivery_notes: notes,
            discount_amount: discount,
            delivery_fee: deliveryFee,
            items: itemsPayload
        };

        try {
            const res = await API.post('handler.php', payload);

            if (res && res.success) {
                API.toast(`Order #${res.data?.order_number || ''} confirmed successfully! Redirecting...`, 'success');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 800);
            } else {
                submitOrderBtn.disabled = false;
                submitOrderBtn.textContent = 'Confirm & Dispatch COD Order';
                const msg = res?.message || 'Failed to place order. Please review inputs.';
                API.toast(msg, 'error');
            }
        } catch (err) {
            submitOrderBtn.disabled = false;
            submitOrderBtn.textContent = 'Confirm & Dispatch COD Order';
            API.toast('A network error occurred while submitting order.', 'error');
        }
    });

    // Initial Catalog Render
    renderProductGrid();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
