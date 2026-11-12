<?php
/**
 * Product Catalog & Stock Status View
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'Product Catalog';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('products_view');

$canManage = has_permission('products_manage');
$canAdjust = has_permission('inventory_adjust');
$db = get_db();

// Pre-load categories for filters and modals
$stmtCat = $db->query("SELECT id, name FROM categories ORDER BY name ASC");
$categories = $stmtCat->fetchAll();

// Pre-load summary metric statistics
$stmtStats = $db->query("
    SELECT 
        COUNT(*) AS total_items,
        SUM(CASE WHEN current_stock <= low_stock_threshold AND current_stock > 0 THEN 1 ELSE 0 END) AS low_stock_items,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock_items,
        SUM(CASE WHEN current_stock > low_stock_threshold THEN 1 ELSE 0 END) AS in_stock_items
    FROM products
    WHERE deleted_at IS NULL AND status = 'active'
");
$stats = $stmtStats->fetch() ?: ['total_items' => 0, 'low_stock_items' => 0, 'out_of_stock_items' => 0, 'in_stock_items' => 0];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Action Header -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">Product Catalog</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            Track inventory availability, profit margins, cost structures, and replenishment thresholds.
        </p>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="<?= BASE_URL ?>/modules/products/categories.php" class="btn btn-outline">
            📁 Categories
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/" class="btn btn-outline">
            📋 Stock Ledger
        </a>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-primary" id="openAddProductBtn">
                <span>+</span> Add Product
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Stat KPI Cards Grid -->
<div class="stats-grid">
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Active Products</span>
            <div class="stat-icon icon-primary">🏷️</div>
        </div>
        <div class="stat-value" id="kpiTotal"><?= number_format((int)$stats['total_items']) ?></div>
        <div class="stat-subtext">Active catalog entries</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Well Stocked</span>
            <div class="stat-icon icon-success">✓</div>
        </div>
        <div class="stat-value" id="kpiInStock"><?= number_format((int)$stats['in_stock_items']) ?></div>
        <div class="stat-subtext">Stock &gt; threshold</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Low Stock Alerts</span>
            <div class="stat-icon icon-warning">⚠️</div>
        </div>
        <div class="stat-value" id="kpiLowStock" style="<?= (int)$stats['low_stock_items'] > 0 ? 'color: var(--color-danger);' : '' ?>">
            <?= number_format((int)$stats['low_stock_items']) ?>
        </div>
        <div class="stat-subtext">Require replenishment</div>
    </div>

    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Out of Stock</span>
            <div class="stat-icon icon-danger">✕</div>
        </div>
        <div class="stat-value" id="kpiOutOfStock" style="<?= (int)$stats['out_of_stock_items'] > 0 ? 'color: var(--color-danger);' : '' ?>">
            <?= number_format((int)$stats['out_of_stock_items']) ?>
        </div>
        <div class="stat-subtext">0 units remaining</div>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="filter-bar">
    <div class="filter-group" style="flex: 2; min-width: 220px;">
        <input type="text" class="form-input" id="searchFilter" placeholder="🔍 Search product name or SKU...">
    </div>

    <div class="filter-group">
        <select class="form-select" id="categoryFilter">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group">
        <select class="form-select" id="stockStatusFilter">
            <option value="all">All Stock Levels</option>
            <option value="in_stock">In Stock</option>
            <option value="low_stock">Low Stock (≤ Threshold)</option>
            <option value="out_of_stock">Out of Stock (0)</option>
        </select>
    </div>

    <div class="filter-group">
        <select class="form-select" id="statusFilter">
            <option value="active">Active Only</option>
            <option value="inactive">Inactive / Archived</option>
            <option value="all">All Statuses</option>
        </select>
    </div>

    <div class="filter-group-fixed">
        <button type="button" class="btn btn-outline" id="resetFiltersBtn" title="Reset all filters">
            Reset
        </button>
    </div>
</div>

<!-- Product Table Container -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" id="productsTable">
            <thead>
                <tr>
                    <th style="width: 120px;">SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th class="text-right" style="width: 110px;">Cost Price</th>
                    <th class="text-right" style="width: 110px;">Selling Price</th>
                    <th class="text-right" style="width: 120px;">Profit Margin</th>
                    <th style="width: 140px;">Current Stock</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 150px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="productTableBody">
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        Loading product catalog...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <div class="pagination-container" id="paginationBar">
        <div id="paginationInfo">Showing 0 of 0 products</div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>&larr; Previous</button>
            <span id="pageIndicator" style="font-weight: 600; padding: 0 8px;">Page 1</span>
            <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>Next &rarr;</button>
        </div>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Add / Edit Product Modal Dialog -->
<div class="modal-overlay" id="productModal" role="dialog" aria-modal="true" aria-labelledby="prodModalTitle">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="prodModalTitle">Add New Product</h3>
            <button type="button" class="modal-close" id="closeProductModal">&times;</button>
        </div>
        <form id="productForm" autocomplete="off">
            <input type="hidden" name="id" id="prodId" value="0">
            <div class="modal-body">
                <div id="prodModalAlert" style="display: none;" class="alert alert-danger"></div>

                <div class="form-group">
                    <label class="form-label" for="prodName">Product Name <span style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="prodName" name="name" required maxlength="150" placeholder="e.g. Arabica Coffee Beans 500g">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label" for="prodSku">SKU (Stock Keeping Unit)</label>
                        <input type="text" class="form-input" id="prodSku" name="sku" placeholder="Auto-generated if blank" maxlength="64">
                        <small style="color: var(--text-muted); font-size: 11px;">Leave blank for auto-generated SKU</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="prodCategory">Category <span style="color: var(--color-danger);">*</span></label>
                        <select class="form-select" id="prodCategory" name="category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label" for="prodCostPrice">Cost Price (₱) <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input" id="prodCostPrice" name="cost_price" required min="0" step="0.01" value="0.00">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="prodSellingPrice">Selling Price (₱) <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input" id="prodSellingPrice" name="selling_price" required min="0" step="0.01" value="0.00">
                    </div>
                </div>

                <!-- Live Margin Estimation Preview -->
                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 10px 14px; margin-bottom: 16px; font-size: 12.5px; display: flex; justify-content: space-between;">
                    <span>Unit Margin: <strong id="marginAmount" style="color: var(--color-success);">₱ 0.00</strong></span>
                    <span>Markup: <strong id="marginPercent" style="color: var(--color-primary);">0.0%</strong></span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" id="initialStockGroup">
                        <label class="form-label" for="prodStock">Initial Stock Qty</label>
                        <input type="number" class="form-input" id="prodStock" name="current_stock" min="0" step="1" value="0">
                        <small style="color: var(--text-muted); font-size: 11px;" id="stockHelpText">Starting inventory on shelf</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="prodThreshold">Low Stock Threshold <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input" id="prodThreshold" name="low_stock_threshold" required min="0" step="1" value="5">
                        <small style="color: var(--text-muted); font-size: 11px;">Triggers replenishment alert</small>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="prodStatus">Catalog Status</label>
                    <select class="form-select" id="prodStatus" name="status">
                        <option value="active">Active (Available for POS & Sales)</option>
                        <option value="inactive">Inactive (Hidden from POS)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelProductBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveProductBtn">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Soft Delete Product Confirmation Modal -->
<div class="modal-overlay" id="deleteProductModal" role="dialog" aria-modal="true" aria-labelledby="deleteProdTitle">
    <div class="modal-card" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title" id="deleteProdTitle" style="color: var(--color-danger);">Delete Product</h3>
            <button type="button" class="modal-close" id="closeDeleteProductModal">&times;</button>
        </div>
        <div class="modal-body">
            <div id="deleteProdAlert" style="display: none;" class="alert alert-danger"></div>
            <p style="font-size: 14px; color: var(--text-main); margin-bottom: 12px;">
                Are you sure you want to delete <strong id="deleteProdTargetName"></strong>?
            </p>
            <p style="font-size: 12.5px; color: var(--text-muted); margin: 0;">
                The product will be archived and hidden from sales ordering. Historical order logs and inventory movements are permanently preserved.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelDeleteProdBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteProdBtn">Confirm Delete</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canManage = <?= $canManage ? 'true' : 'false' ?>;
    const canAdjust = <?= $canAdjust ? 'true' : 'false' ?>;

    let currentPage = 1;
    let totalPages = 1;
    let searchDebounceTimer = null;
    let pendingDeleteProductId = 0;

    // Filters
    const searchFilter = document.getElementById('searchFilter');
    const categoryFilter = document.getElementById('categoryFilter');
    const stockStatusFilter = document.getElementById('stockStatusFilter');
    const statusFilter = document.getElementById('statusFilter');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');

    // Table & Pagination
    const productTableBody = document.getElementById('productTableBody');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const pageIndicator = document.getElementById('pageIndicator');
    const paginationInfo = document.getElementById('paginationInfo');

    // Modals & Forms
    const productModal = document.getElementById('productModal');
    const deleteProductModal = document.getElementById('deleteProductModal');
    const openAddProductBtn = document.getElementById('openAddProductBtn');
    const closeProductModal = document.getElementById('closeProductModal');
    const cancelProductBtn = document.getElementById('cancelProductBtn');
    const productForm = document.getElementById('productForm');
    const saveProductBtn = document.getElementById('saveProductBtn');
    const prodModalTitle = document.getElementById('prodModalTitle');
    const prodModalAlert = document.getElementById('prodModalAlert');

    const prodId = document.getElementById('prodId');
    const prodName = document.getElementById('prodName');
    const prodSku = document.getElementById('prodSku');
    const prodCategory = document.getElementById('prodCategory');
    const prodCostPrice = document.getElementById('prodCostPrice');
    const prodSellingPrice = document.getElementById('prodSellingPrice');
    const prodStock = document.getElementById('prodStock');
    const prodThreshold = document.getElementById('prodThreshold');
    const prodStatus = document.getElementById('prodStatus');
    const initialStockGroup = document.getElementById('initialStockGroup');
    const stockHelpText = document.getElementById('stockHelpText');
    const marginAmount = document.getElementById('marginAmount');
    const marginPercent = document.getElementById('marginPercent');

    const closeDeleteProductModal = document.getElementById('closeDeleteProductModal');
    const cancelDeleteProdBtn = document.getElementById('cancelDeleteProdBtn');
    const confirmDeleteProdBtn = document.getElementById('confirmDeleteProdBtn');
    const deleteProdTargetName = document.getElementById('deleteProdTargetName');
    const deleteProdAlert = document.getElementById('deleteProdAlert');

    // Safe HTML Escaping Helper
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMoney(amount) {
        const n = parseFloat(amount) || 0;
        return '₱ ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Modal Control Functions
    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Margin Calculation in Modal
    function updateMarginPreview() {
        const cost = parseFloat(prodCostPrice.value) || 0;
        const sell = parseFloat(prodSellingPrice.value) || 0;
        const margin = sell - cost;
        const markup = cost > 0 ? (margin / cost) * 100 : 0;

        marginAmount.textContent = formatMoney(margin);
        marginAmount.style.color = margin >= 0 ? 'var(--color-success)' : 'var(--color-danger)';
        marginPercent.textContent = markup.toFixed(1) + '%';
        marginPercent.style.color = markup >= 0 ? 'var(--color-primary)' : 'var(--color-danger)';
    }

    if (prodCostPrice && prodSellingPrice) {
        prodCostPrice.addEventListener('input', updateMarginPreview);
        prodSellingPrice.addEventListener('input', updateMarginPreview);
    }

    // Load Products from API
    async function loadProducts(page = 1) {
        currentPage = page;
        productTableBody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                    Loading product catalog...
                </td>
            </tr>`;

        const params = new URLSearchParams({
            action: 'list_products',
            page: currentPage,
            limit: 10,
            search: searchFilter.value.trim(),
            category_id: categoryFilter.value,
            stock_status: stockStatusFilter.value,
            status: statusFilter.value
        });

        const res = await API.get(`handler.php?${params.toString()}`);
        if (!res || !res.success || !res.data) {
            productTableBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: var(--color-danger);">
                        Failed to load products. Please check your network connection and retry.
                    </td>
                </tr>`;
            return;
        }

        const { products, pagination } = res.data;
        totalPages = pagination.total_pages || 1;

        // Render Table Rows
        if (!products || products.length === 0) {
            productTableBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        No products match the selected criteria.
                    </td>
                </tr>`;
        } else {
            let html = '';
            products.forEach(p => {
                const stock = parseInt(p.current_stock, 10);
                const threshold = parseInt(p.low_stock_threshold, 10);
                const cost = parseFloat(p.cost_price);
                const sell = parseFloat(p.selling_price);
                const margin = sell - cost;
                const marginPct = cost > 0 ? ((margin / cost) * 100).toFixed(0) : '0';

                // Low-stock visual badge logic
                let stockBadge = '';
                if (stock <= 0) {
                    stockBadge = '<span class="badge badge-danger">Out of Stock (0)</span>';
                } else if (stock <= threshold) {
                    stockBadge = `<span class="badge badge-danger">⚠️ Low: ${stock} / ${threshold}</span>`;
                } else {
                    stockBadge = `<span class="badge badge-success">✓ In Stock (${stock})</span>`;
                }

                const statusBadge = p.status === 'active' 
                    ? '<span class="badge badge-active">Active</span>' 
                    : '<span class="badge badge-inactive">Inactive</span>';

                html += `
                    <tr>
                        <td class="tabular-nums font-mono" style="font-size: 12.5px; font-weight: 600; color: var(--text-muted);">
                            ${escapeHtml(p.sku)}
                        </td>
                        <td>
                            <strong style="color: var(--text-main); font-size: 14px;">${escapeHtml(p.name)}</strong>
                        </td>
                        <td>
                            <span class="badge badge-confirmed">${escapeHtml(p.category_name)}</span>
                        </td>
                        <td class="text-right tabular-nums">${formatMoney(cost)}</td>
                        <td class="text-right tabular-nums"><strong>${formatMoney(sell)}</strong></td>
                        <td class="text-right tabular-nums" style="color: ${margin >= 0 ? 'var(--color-success)' : 'var(--color-danger)'}; font-size: 13px;">
                            ${formatMoney(margin)} <small style="color: var(--text-muted);">(${marginPct}%)</small>
                        </td>
                        <td>${stockBadge}</td>
                        <td>${statusBadge}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            ${canManage ? `
                                <button type="button" class="btn btn-outline btn-sm edit-product-btn" data-id="${p.id}">
                                    Edit
                                </button>
                            ` : ''}
                            ${canAdjust ? `
                                <a href="../inventory/?product_id=${p.id}&action=adjust" class="btn btn-outline btn-sm" title="Adjust Stock">
                                    ±
                                </a>
                            ` : ''}
                            ${canManage ? `
                                <button type="button" class="btn btn-outline btn-sm delete-product-btn" 
                                        style="color: var(--color-danger); border-color: var(--color-danger-border);" 
                                        data-id="${p.id}" data-name="${escapeHtml(p.name)}">
                                    ✕
                                </button>
                            ` : ''}
                        </td>
                    </tr>
                `;
            });
            productTableBody.innerHTML = html;
        }

        // Update Pagination Controls
        paginationInfo.textContent = `Showing ${(pagination.total > 0 ? (pagination.page - 1) * pagination.limit + 1 : 0)} to ${Math.min(pagination.page * pagination.limit, pagination.total)} of ${pagination.total} products`;
        pageIndicator.textContent = `Page ${pagination.page} of ${Math.max(1, pagination.total_pages)}`;
        prevPageBtn.disabled = pagination.page <= 1;
        nextPageBtn.disabled = pagination.page >= pagination.total_pages;
    }

    // Filter Listeners
    searchFilter.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => loadProducts(1), 300);
    });

    categoryFilter.addEventListener('change', () => loadProducts(1));
    stockStatusFilter.addEventListener('change', () => loadProducts(1));
    statusFilter.addEventListener('change', () => loadProducts(1));

    resetFiltersBtn.addEventListener('click', () => {
        searchFilter.value = '';
        categoryFilter.value = '';
        stockStatusFilter.value = 'all';
        statusFilter.value = 'active';
        loadProducts(1);
    });

    // Pagination Click Listeners
    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) loadProducts(currentPage - 1);
    });

    nextPageBtn.addEventListener('click', () => {
        if (currentPage < totalPages) loadProducts(currentPage + 1);
    });

    // Add Product Modal Trigger
    if (openAddProductBtn) {
        openAddProductBtn.addEventListener('click', () => {
            prodModalTitle.textContent = 'Add New Product';
            prodId.value = '0';
            productForm.reset();
            prodCostPrice.value = '0.00';
            prodSellingPrice.value = '0.00';
            prodStock.value = '0';
            prodThreshold.value = '5';
            prodStock.disabled = false;
            initialStockGroup.style.display = 'block';
            stockHelpText.textContent = 'Starting inventory on shelf';
            prodModalAlert.style.display = 'none';
            updateMarginPreview();
            openModal(productModal);
            setTimeout(() => prodName.focus(), 50);
        });
    }

    if (closeProductModal) closeProductModal.addEventListener('click', () => closeModal(productModal));
    if (cancelProductBtn) cancelProductBtn.addEventListener('click', () => closeModal(productModal));
    if (closeDeleteProductModal) closeDeleteProductModal.addEventListener('click', () => closeModal(deleteProductModal));
    if (cancelDeleteProdBtn) cancelDeleteProdBtn.addEventListener('click', () => closeModal(deleteProductModal));

    // Global ESC Key Handler
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal(productModal);
            closeModal(deleteProductModal);
        }
    });

    // Delegated Edit & Delete Button Clicks
    productsTable.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-product-btn');
        if (editBtn) {
            const id = editBtn.dataset.id;
            prodModalTitle.textContent = 'Edit Product';
            prodModalAlert.style.display = 'none';

            const res = await API.get(`handler.php?action=get_product&id=${id}`);
            if (res && res.success && res.data) {
                const p = res.data;
                prodId.value = p.id;
                prodName.value = p.name;
                prodSku.value = p.sku;
                prodCategory.value = p.category_id;
                prodCostPrice.value = p.cost_price;
                prodSellingPrice.value = p.selling_price;
                prodThreshold.value = p.low_stock_threshold;
                prodStatus.value = p.status;

                // Disable direct stock edit during product update (must use inventory adjustment)
                prodStock.value = p.current_stock;
                prodStock.disabled = true;
                stockHelpText.textContent = 'Use Stock Ledger to adjust quantities';

                updateMarginPreview();
                openModal(productModal);
                setTimeout(() => prodName.focus(), 50);
            } else {
                API.toast('Failed to load product details.', 'error');
            }
            return;
        }

        const deleteBtn = e.target.closest('.delete-product-btn');
        if (deleteBtn) {
            pendingDeleteProductId = parseInt(deleteBtn.dataset.id, 10);
            deleteProdTargetName.textContent = `"${deleteBtn.dataset.name}"`;
            deleteProdAlert.style.display = 'none';
            openModal(deleteProductModal);
        }
    });

    // Product Form Submission
    if (productForm) {
        productForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            prodModalAlert.style.display = 'none';

            saveProductBtn.disabled = true;
            saveProductBtn.textContent = 'Saving...';

            const id = parseInt(prodId.value, 10);
            const action = id > 0 ? 'update_product' : 'create_product';

            const payload = {
                action,
                name: prodName.value.trim(),
                sku: prodSku.value.trim(),
                category_id: parseInt(prodCategory.value, 10),
                cost_price: parseFloat(prodCostPrice.value) || 0,
                selling_price: parseFloat(prodSellingPrice.value) || 0,
                low_stock_threshold: parseInt(prodThreshold.value, 10) || 5,
                status: prodStatus.value
            };

            if (id > 0) {
                payload.id = id;
            } else {
                payload.current_stock = parseInt(prodStock.value, 10) || 0;
            }

            const res = await API.post('handler.php', payload);
            saveProductBtn.disabled = false;
            saveProductBtn.textContent = 'Save Product';

            if (res && res.success) {
                API.toast(res.message || 'Product saved successfully.', 'success');
                closeModal(productModal);
                loadProducts(currentPage);
            } else {
                const msg = (res && res.message) ? res.message : 'Validation error occurred.';
                prodModalAlert.textContent = msg;
                prodModalAlert.style.display = 'block';
            }
        });
    }

    // Confirm Product Deletion
    if (confirmDeleteProdBtn) {
        confirmDeleteProdBtn.addEventListener('click', async () => {
            if (!pendingDeleteProductId) return;

            confirmDeleteProdBtn.disabled = true;
            confirmDeleteProdBtn.textContent = 'Deleting...';

            const res = await API.post('handler.php', {
                action: 'delete_product',
                id: pendingDeleteProductId
            });

            confirmDeleteProdBtn.disabled = false;
            confirmDeleteProdBtn.textContent = 'Confirm Delete';

            if (res && res.success) {
                API.toast('Product deleted successfully.', 'success');
                closeModal(deleteProductModal);
                pendingDeleteProductId = 0;
                loadProducts(currentPage);
            } else {
                const msg = (res && res.message) ? res.message : 'Failed to delete product.';
                deleteProdAlert.textContent = msg;
                deleteProdAlert.style.display = 'block';
            }
        });
    }

    // Initial Load
    loadProducts(1);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

