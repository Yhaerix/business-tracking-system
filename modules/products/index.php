<?php
/**
 * Product Catalog & Stock Status View
 * Small Business Sales and Expense Tracking System
 * Reference: View 2 (view-products), #addProductModal, #stockAdjustModal in new-design.html
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

// Pre-load active products for the manual stock adjustment dropdown
$stmtProductsList = $db->query("
    SELECT id, sku, name, current_stock, low_stock_threshold 
    FROM products 
    WHERE deleted_at IS NULL AND status = 'active'
    ORDER BY name ASC
");
$allProductsList = $stmtProductsList->fetchAll();

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
            <?php if ($canAdjust): ?>
                <button type="button" class="btn btn-outline" id="openHeaderAdjustBtn">
                    <span>±</span> Manual Stock Adjustment
                </button>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <button type="button" class="btn btn-primary" id="openAddProductBtn">
                    <span>+</span> Add Product
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stat KPI Cards Grid (Matching View 2 in new-design.html) -->
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

    <!-- Product Table Container (.card with padding:0 matching design standard) -->
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
                        <th class="text-right" style="width: 130px;">Profit Margin</th>
                        <th style="width: 140px;">Current Stock</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 160px; text-align: right;">Actions</th>
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

<!-- ============================================================== -->
<!-- 1. MODAL / DRAWER: Add & Edit Product (#addProductModal)        -->
<!-- ============================================================== -->
<?php if ($canManage): ?>
<div class="modal-overlay" id="addProductModal" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
    <div class="modal-card" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="modal-title" id="productModalTitle">Add Product</h3>
            <button type="button" class="modal-close" id="closeAddProductModalBtn">&times;</button>
        </div>
        <form id="productForm" autocomplete="off">
            <input type="hidden" name="id" id="prodId" value="0">
            <div class="modal-body">
                <div id="prodModalAlert" style="display: none;" class="alert alert-danger"></div>

                <div class="form-group">
                    <label class="form-label" for="prodName">Product Name <span style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="prodName" name="name" required maxlength="150" placeholder="e.g. San Miguel Pale Pilsen 330ml">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label" for="prodSku">SKU (Stock Keeping Unit)</label>
                        <input type="text" class="form-input" id="prodSku" name="sku" placeholder="Auto-generated if blank" maxlength="64" style="font-family: monospace;">
                        <small style="color: var(--text-muted); font-size: 11px;">Leave blank for sequential SKU</small>
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

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
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
                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 10px 14px; margin-bottom: 16px; font-size: 12.5px; display: flex; justify-content: space-between; align-items: center;">
                    <span>Unit Margin: <strong id="marginAmount" style="color: var(--color-success); font-size: 13.5px;">₱ 0.00</strong></span>
                    <span>Markup: <strong id="marginPercent" style="color: var(--color-primary); font-size: 13.5px;">0.0%</strong></span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
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
                        <option value="active">Active (Available for POS &amp; Sales)</option>
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
<?php endif; ?>

<!-- ============================================================== -->
<!-- 2. MODAL / DRAWER: Manual Stock Adjustment (#stockAdjustModal)   -->
<!-- ============================================================== -->
<?php if ($canAdjust): ?>
<div class="modal-overlay" id="stockAdjustModal" role="dialog" aria-modal="true" aria-labelledby="adjustModalTitle">
    <div class="modal-card" style="max-width: 540px;">
        <div class="modal-header">
            <h3 class="modal-title" id="adjustModalTitle">Manual Stock Adjustment</h3>
            <button type="button" class="modal-close" id="closeAdjustModalBtn">&times;</button>
        </div>
        <form id="adjustForm" autocomplete="off">
            <div class="modal-body">
                <div id="adjustModalAlert" style="display: none;" class="alert alert-danger"></div>

                <div class="form-group">
                    <label class="form-label" for="adjustProductSelect">Select Product <span style="color: var(--color-danger);">*</span></label>
                    <select id="adjustProductSelect" class="form-select" required>
                        <option value="">-- Choose a Product --</option>
                        <?php foreach ($allProductsList as $prod): ?>
                            <option value="<?= (int)$prod['id'] ?>" data-stock="<?= (int)$prod['current_stock'] ?>" data-sku="<?= htmlspecialchars($prod['sku'], ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8') ?> (Current: <?= (int)$prod['current_stock'] ?>) [<?= htmlspecialchars($prod['sku'], ENT_QUOTES, 'UTF-8') ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Adjustment Type Select (STRICTLY LIMITED TO 3 OPTIONS: Stock In, Stock Out, Fix Count) -->
                <div class="form-group">
                    <label class="form-label" for="adjustTypeSelect">Adjustment Type <span style="color: var(--color-danger);">*</span></label>
                    <select id="adjustTypeSelect" class="form-select" required>
                        <option value="STOCK_IN">Stock In (+)</option>
                        <option value="STOCK_OUT">Stock Out (-)</option>
                        <option value="FIX_COUNT">Fix Count (Physical Audit)</option>
                    </select>
                </div>

                <!-- Quantity Inputs (Dynamic based on selected adjustment type) -->
                <div id="standardQtyGroup" class="form-group">
                    <label class="form-label" id="qtyInputLabel" for="adjustQtyInput">Adjustment Quantity *</label>
                    <input type="number" id="adjustQtyInput" class="form-input" min="1" step="1" value="1" required>
                    <small style="color: var(--text-muted); font-size: 11px;" id="qtyHelpText">Units to add to current inventory</small>
                </div>

                <div id="fixCountQtyGroup" class="form-group" style="display: none;">
                    <label class="form-label" for="adjustFixCountInput">New Actual Count (Audit Result) *</label>
                    <input type="number" id="adjustFixCountInput" class="form-input" min="0" step="1" value="0">
                    <small style="color: var(--text-muted); font-size: 11px;">Direct physical inventory count on shelf</small>
                </div>

                <!-- Live Stock Preview Card (.stock-preview-box from design system) -->
                <div class="stock-preview-box" style="background: #f8fafc; border: 1px dashed var(--border-color); border-radius: var(--radius-md); padding: 12px 16px; margin: 16px 0; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 12.5px; color: var(--text-muted);">Simulated Stock Change:</span>
                    <strong id="stockPreviewText" style="font-size: 14px; color: var(--color-primary); font-family: monospace;">0 &rarr; 0 (0)</strong>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="adjustNotesInput">Reason / Adjustment Notes <span style="color: var(--color-danger);">*</span></label>
                    <textarea id="adjustNotesInput" class="form-textarea" rows="2" placeholder="e.g. Supplier delivery invoice #401, physical count variance, or damaged packaging..." required minlength="3"></textarea>
                    <small style="color: var(--text-muted); font-size: 11px;">Required for the immutable audit trail</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelAdjustBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveAdjustBtn">Post Stock Movement</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- 3. MODAL: Soft Delete Product Confirmation (#deleteProductModal) -->
<!-- ============================================================== -->
<?php if ($canManage): ?>
<div class="modal-overlay" id="deleteProductModal" role="dialog" aria-modal="true" aria-labelledby="deleteProdTitle">
    <div class="modal-card" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title" id="deleteProdTitle" style="color: var(--color-danger);">Delete Product</h3>
            <button type="button" class="modal-close" id="closeDeleteProductModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <div id="deleteProdAlert" style="display: none;" class="alert alert-danger"></div>
            <p style="font-size: 14px; color: var(--text-main); margin-bottom: 12px;">
                Are you sure you want to delete <strong id="deleteProdTargetName"></strong>?
            </p>
            <p style="font-size: 12.5px; color: var(--text-muted); margin: 0;">
                The product will be archived and hidden from sales ordering. Historical order logs, sales records, and inventory movements are permanently preserved.
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

    // Add/Edit Product Modal Elements
    const addProductModal = document.getElementById('addProductModal');
    const openAddProductBtn = document.getElementById('openAddProductBtn');
    const closeAddProductModalBtn = document.getElementById('closeAddProductModalBtn');
    const cancelProductBtn = document.getElementById('cancelProductBtn');
    const productForm = document.getElementById('productForm');
    const saveProductBtn = document.getElementById('saveProductBtn');
    const productModalTitle = document.getElementById('productModalTitle');
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

    // Manual Stock Adjustment Modal Elements
    const stockAdjustModal = document.getElementById('stockAdjustModal');
    const openHeaderAdjustBtn = document.getElementById('openHeaderAdjustBtn');
    const closeAdjustModalBtn = document.getElementById('closeAdjustModalBtn');
    const cancelAdjustBtn = document.getElementById('cancelAdjustBtn');
    const adjustForm = document.getElementById('adjustForm');
    const saveAdjustBtn = document.getElementById('saveAdjustBtn');
    const adjustModalAlert = document.getElementById('adjustModalAlert');

    const adjustProductSelect = document.getElementById('adjustProductSelect');
    const adjustTypeSelect = document.getElementById('adjustTypeSelect');
    const standardQtyGroup = document.getElementById('standardQtyGroup');
    const fixCountQtyGroup = document.getElementById('fixCountQtyGroup');
    const qtyInputLabel = document.getElementById('qtyInputLabel');
    const qtyHelpText = document.getElementById('qtyHelpText');
    const adjustQtyInput = document.getElementById('adjustQtyInput');
    const adjustFixCountInput = document.getElementById('adjustFixCountInput');
    const stockPreviewText = document.getElementById('stockPreviewText');
    const adjustNotesInput = document.getElementById('adjustNotesInput');

    // Delete Product Modal Elements
    const deleteProductModal = document.getElementById('deleteProductModal');
    const closeDeleteProductModalBtn = document.getElementById('closeDeleteProductModalBtn');
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
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Margin Calculation in Add/Edit Modal
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

    // -------------------------------------------------------------
    // Stock Adjustment Logic & Dynamic Form Switching
    // -------------------------------------------------------------
    function updateAdjustmentFormFields() {
        if (!adjustTypeSelect) return;
        const type = adjustTypeSelect.value;

        if (type === 'STOCK_IN') {
            standardQtyGroup.style.display = 'block';
            fixCountQtyGroup.style.display = 'none';
            qtyInputLabel.textContent = 'Quantity to Add (Stock In) *';
            qtyHelpText.textContent = 'Units received from supplier or warehouse';
            adjustQtyInput.required = true;
            adjustFixCountInput.required = false;
        } else if (type === 'STOCK_OUT') {
            standardQtyGroup.style.display = 'block';
            fixCountQtyGroup.style.display = 'none';
            qtyInputLabel.textContent = 'Quantity to Deduct (Stock Out) *';
            qtyHelpText.textContent = 'Damaged, expired, or written-off units';
            adjustQtyInput.required = true;
            adjustFixCountInput.required = false;
        } else if (type === 'FIX_COUNT') {
            standardQtyGroup.style.display = 'none';
            fixCountQtyGroup.style.display = 'block';
            adjustQtyInput.required = false;
            adjustFixCountInput.required = true;
        }

        updateAdjustmentLivePreview();
    }

    function updateAdjustmentLivePreview() {
        if (!adjustProductSelect || !stockPreviewText) return;
        const selectedOpt = adjustProductSelect.options[adjustProductSelect.selectedIndex];
        const currentStock = selectedOpt && selectedOpt.value ? parseInt(selectedOpt.dataset.stock || '0', 10) : 0;
        const type = adjustTypeSelect.value;

        let newStock = currentStock;
        let diff = 0;

        if (type === 'STOCK_IN') {
            const qty = Math.max(0, parseInt(adjustQtyInput.value, 10) || 0);
            diff = qty;
            newStock = currentStock + diff;
            stockPreviewText.textContent = `${currentStock} → ${newStock} (+${diff})`;
            stockPreviewText.style.color = 'var(--color-success)';
        } else if (type === 'STOCK_OUT') {
            const qty = Math.max(0, parseInt(adjustQtyInput.value, 10) || 0);
            diff = qty;
            newStock = currentStock - diff;
            stockPreviewText.textContent = `${currentStock} → ${newStock} (-${diff})`;
            stockPreviewText.style.color = newStock < 0 ? 'var(--color-danger)' : 'var(--color-warning)';
        } else if (type === 'FIX_COUNT') {
            const counted = Math.max(0, parseInt(adjustFixCountInput.value, 10) || 0);
            newStock = counted;
            diff = newStock - currentStock;
            const sign = diff >= 0 ? `+${diff}` : `${diff}`;
            stockPreviewText.textContent = `${currentStock} → ${newStock} (${sign} discrepancy)`;
            stockPreviewText.style.color = diff >= 0 ? 'var(--color-success)' : 'var(--color-danger)';
        }
    }

    if (adjustTypeSelect) {
        adjustTypeSelect.addEventListener('change', updateAdjustmentFormFields);
    }
    if (adjustProductSelect) {
        adjustProductSelect.addEventListener('change', () => {
            const selectedOpt = adjustProductSelect.options[adjustProductSelect.selectedIndex];
            if (selectedOpt && selectedOpt.value) {
                const stock = parseInt(selectedOpt.dataset.stock || '0', 10);
                if (adjustFixCountInput) adjustFixCountInput.value = stock;
            }
            updateAdjustmentLivePreview();
        });
    }
    if (adjustQtyInput) {
        adjustQtyInput.addEventListener('input', updateAdjustmentLivePreview);
    }
    if (adjustFixCountInput) {
        adjustFixCountInput.addEventListener('input', updateAdjustmentLivePreview);
    }

    window.openAdjustModalForProduct = function(productId, sku, name, currentStock) {
        if (!stockAdjustModal) return;
        adjustForm.reset();
        adjustModalAlert.style.display = 'none';

        if (adjustProductSelect) {
            adjustProductSelect.value = String(productId);
            if (adjustFixCountInput) adjustFixCountInput.value = currentStock;
        }

        adjustTypeSelect.value = 'STOCK_IN';
        adjustQtyInput.value = '5';
        adjustNotesInput.value = '';

        updateAdjustmentFormFields();
        openModal(stockAdjustModal);
        setTimeout(() => adjustQtyInput.focus(), 50);
    };

    if (openHeaderAdjustBtn) {
        openHeaderAdjustBtn.addEventListener('click', () => {
            adjustForm.reset();
            adjustModalAlert.style.display = 'none';
            adjustTypeSelect.value = 'STOCK_IN';
            adjustQtyInput.value = '5';
            adjustNotesInput.value = '';
            updateAdjustmentFormFields();
            openModal(stockAdjustModal);
            setTimeout(() => adjustProductSelect.focus(), 50);
        });
    }

    if (closeAdjustModalBtn) closeAdjustModalBtn.addEventListener('click', () => closeModal(stockAdjustModal));
    if (cancelAdjustBtn) cancelAdjustBtn.addEventListener('click', () => closeModal(stockAdjustModal));

    // Submit Stock Adjustment Form
    if (adjustForm) {
        adjustForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            adjustModalAlert.style.display = 'none';

            const productId = parseInt(adjustProductSelect.value, 10);
            if (!productId) {
                adjustModalAlert.textContent = 'Please choose a product to adjust.';
                adjustModalAlert.style.display = 'block';
                return;
            }

            const type = adjustTypeSelect.value;
            const selectedOpt = adjustProductSelect.options[adjustProductSelect.selectedIndex];
            const currentStock = selectedOpt ? parseInt(selectedOpt.dataset.stock || '0', 10) : 0;

            const payload = {
                action: 'adjust_stock',
                product_id: productId,
                movement_type: type,
                notes: adjustNotesInput.value.trim()
            };

            if (type === 'FIX_COUNT') {
                const newCount = parseInt(adjustFixCountInput.value, 10);
                if (isNaN(newCount) || newCount < 0) {
                    adjustModalAlert.textContent = 'Actual count cannot be negative.';
                    adjustModalAlert.style.display = 'block';
                    return;
                }
                payload.new_count = newCount;
                payload.quantity = Math.abs(newCount - currentStock);
            } else {
                const qty = parseInt(adjustQtyInput.value, 10);
                if (!qty || qty <= 0) {
                    adjustModalAlert.textContent = 'Adjustment quantity must be greater than zero.';
                    adjustModalAlert.style.display = 'block';
                    return;
                }
                if (type === 'STOCK_OUT' && qty > currentStock) {
                    adjustModalAlert.textContent = `Deduction quantity (${qty}) exceeds current stock (${currentStock}). Negative stock is not allowed.`;
                    adjustModalAlert.style.display = 'block';
                    return;
                }
                payload.quantity = qty;
                payload.direction = (type === 'STOCK_OUT') ? 'SUBTRACT' : 'ADD';
            }

            saveAdjustBtn.disabled = true;
            saveAdjustBtn.textContent = 'Posting...';

            try {
                // Post to inventory handler
                const res = await API.post('../inventory/handler.php', payload);
                if (res && res.success) {
                    API.toast(res.message || 'Stock adjusted successfully.', 'success');
                    closeModal(stockAdjustModal);

                    // Update local dataset stock in dropdown
                    if (selectedOpt && res.data) {
                        selectedOpt.dataset.stock = res.data.stock_after;
                        const pName = selectedOpt.dataset.name || '';
                        const pSku = selectedOpt.dataset.sku || '';
                        selectedOpt.textContent = `${pName} (Current: ${res.data.stock_after}) [${pSku}]`;
                    }

                    loadProducts(currentPage);
                } else {
                    adjustModalAlert.textContent = (res && res.message) ? res.message : 'Failed to post stock adjustment.';
                    adjustModalAlert.style.display = 'block';
                }
            } catch (err) {
                console.error('Stock adjustment error:', err);
                adjustModalAlert.textContent = 'Server communication error while posting adjustment.';
                adjustModalAlert.style.display = 'block';
            } finally {
                saveAdjustBtn.disabled = false;
                saveAdjustBtn.textContent = 'Post Stock Movement';
            }
        });
    }

    // -------------------------------------------------------------
    // Load Products Table from API
    // -------------------------------------------------------------
    async function loadProducts(page = 1) {
        currentPage = page;
        productTableBody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
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

        const { products, pagination, stats: serverStats } = res.data;
        totalPages = pagination.total_pages || 1;

        // Update KPI metrics if present
        if (serverStats) {
            const kpiTotal = document.getElementById('kpiTotal');
            const kpiInStock = document.getElementById('kpiInStock');
            const kpiLowStock = document.getElementById('kpiLowStock');
            const kpiOutOfStock = document.getElementById('kpiOutOfStock');

            if (kpiTotal) kpiTotal.textContent = serverStats.total_items;
            if (kpiInStock) kpiInStock.textContent = serverStats.in_stock_items;
            if (kpiLowStock) {
                kpiLowStock.textContent = serverStats.low_stock_items;
                kpiLowStock.style.color = serverStats.low_stock_items > 0 ? 'var(--color-danger)' : '';
            }
            if (kpiOutOfStock) {
                kpiOutOfStock.textContent = serverStats.out_of_stock_items;
                kpiOutOfStock.style.color = serverStats.out_of_stock_items > 0 ? 'var(--color-danger)' : '';
            }
        }

        // Render Table Rows
        if (!products || products.length === 0) {
            productTableBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
                        <div style="font-size: 36px; margin-bottom: 8px;">📦</div>
                        <div style="font-weight: 600; font-size: 15px; color: var(--text-main);">No Products Found</div>
                        <p style="font-size: 13px; margin: 4px 0 0 0;">No catalog entries match your selected search or filter criteria.</p>
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
                const marginPct = cost > 0 ? ((margin / cost) * 100).toFixed(1) : '0.0';

                // Stock status tag styling matching design system
                let stockBadge = '';
                if (stock <= 0) {
                    stockBadge = '<span class="pos-product-stock-tag out-of-stock-tag">0 in stock (Out)</span>';
                } else if (stock <= threshold) {
                    stockBadge = `<span class="pos-product-stock-tag low-stock">⚠️ ${stock} in stock (Low)</span>`;
                } else {
                    stockBadge = `<span class="pos-product-stock-tag in-stock">${stock} in stock</span>`;
                }

                const statusBadge = p.status === 'active' 
                    ? '<span class="badge badge-active">Active</span>' 
                    : '<span class="badge badge-inactive">Inactive</span>';

                html += `
                    <tr>
                        <td class="tabular-nums" style="font-family: monospace; font-weight: 600; color: var(--color-primary); font-size: 13px;">
                            ${escapeHtml(p.sku)}
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main); font-size: 14px;">${escapeHtml(p.name)}</div>
                            <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">Threshold: ${threshold} units</div>
                        </td>
                        <td>
                            <span class="badge badge-outline">${escapeHtml(p.category_name || 'General')}</span>
                        </td>
                        <td class="text-right tabular-nums">${formatMoney(cost)}</td>
                        <td class="text-right tabular-nums" style="font-weight: 600;">${formatMoney(sell)}</td>
                        <td class="text-right tabular-nums" style="font-weight: 600; color: ${margin >= 0 ? 'var(--color-success)' : 'var(--color-danger)'};">
                            ${formatMoney(margin)} <small style="font-size: 11px; color: var(--text-muted);">(${marginPct}%)</small>
                        </td>
                        <td>${stockBadge}</td>
                        <td>${statusBadge}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                ${canAdjust ? `
                                    <button type="button" class="btn btn-outline btn-sm adjust-product-btn" 
                                            onclick="openAdjustModalForProduct(${p.id}, '${escapeHtml(p.sku)}', '${escapeHtml(p.name).replace(/'/g, "\\'")}', ${stock})" 
                                            title="Quick Stock Adjustment">
                                        ±
                                    </button>
                                ` : ''}
                                ${canManage ? `
                                    <button type="button" class="btn btn-outline btn-sm edit-product-btn" data-id="${p.id}" title="Edit Product">
                                        <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><path d="M4 20h4L19 9l-4-4L4 16v4z"/></svg>Edit
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm delete-product-btn" 
                                            data-id="${p.id}" data-name="${escapeHtml(p.name)}" title="Delete Product">
                                        <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>Delete
                                    </button>
                                ` : ''}
                            </div>
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
            productModalTitle.textContent = 'Add New Product';
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
            openModal(addProductModal);
            setTimeout(() => prodName.focus(), 50);
        });
    }

    if (closeAddProductModalBtn) closeAddProductModalBtn.addEventListener('click', () => closeModal(addProductModal));
    if (cancelProductBtn) cancelProductBtn.addEventListener('click', () => closeModal(addProductModal));
    if (closeDeleteProductModalBtn) closeDeleteProductModalBtn.addEventListener('click', () => closeModal(deleteProductModal));
    if (cancelDeleteProdBtn) cancelDeleteProdBtn.addEventListener('click', () => closeModal(deleteProductModal));

    // Close when clicking overlay backdrop (only if mousedown also started on backdrop)
    let prodMouseDownTarget = null;
    document.addEventListener('mousedown', (e) => {
        prodMouseDownTarget = e.target;
    });
    [addProductModal, stockAdjustModal, deleteProductModal].forEach(modal => {
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal && prodMouseDownTarget === modal) {
                    closeModal(modal);
                }
            });
        }
    });

    // Global ESC Key Handler
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal(addProductModal);
            closeModal(stockAdjustModal);
            closeModal(deleteProductModal);
        }
    });

    // Delegated Edit & Delete Button Clicks
    productsTable.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('.edit-product-btn');
        if (editBtn) {
            const id = editBtn.dataset.id;
            productModalTitle.textContent = 'Edit Product';
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
                stockHelpText.textContent = 'Use Manual Stock Adjustment to modify quantities';

                updateMarginPreview();
                openModal(addProductModal);
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
                closeModal(addProductModal);
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

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
