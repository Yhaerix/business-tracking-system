<?php
/**
 * Inventory Stock Ledger & Adjustments View
 * Small Business Sales and Expense Tracking System
 * Reference: View 4 (view-inventory) & #stockAdjustModal in new-design.html
 */

declare(strict_types=1);

$pageTitle = 'Stock Movement Ledger';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('inventory_view');

$canAdjust = has_permission('inventory_adjust');
$db = get_db();

// Pre-load active products for adjustment modal and filter
$stmtProds = $db->query("
    SELECT id, sku, name, current_stock, low_stock_threshold 
    FROM products 
    WHERE deleted_at IS NULL AND status = 'active'
    ORDER BY name ASC
");
$products = $stmtProds->fetchAll();

// Pre-load low-stock items for alert banner
$stmtLow = $db->query("
    SELECT id, sku, name, current_stock, low_stock_threshold
    FROM products
    WHERE deleted_at IS NULL AND status = 'active' AND current_stock <= low_stock_threshold
    ORDER BY current_stock ASC
");
$lowStockItems = $stmtLow->fetchAll();

$preselectedProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$autoOpenAdjust = isset($_GET['action']) && $_GET['action'] === 'adjust';

require_once __DIR__ . '/../../includes/header.php';
?>

    <!-- Page Header -->
    <div class="page-header" style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/modules/products/" class="btn btn-outline">
                🏷️ View Products
            </a>
            <?php if ($canAdjust): ?>
                <button type="button" class="btn btn-primary" id="openAdjustModalBtn">
                    <span>±</span> Adjust Stock
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Low Stock Warning Banner (Moved into topbar notification bell) -->
    <?php if (!empty($lowStockItems)): ?>
        <div class="alert alert-danger moved-alert" id="lowStockAlertBanner" style="margin-bottom: 24px; border-left: 4px solid var(--color-danger); display: flex; align-items: flex-start; gap: 14px; background: #fff5f5; border-radius: var(--radius-md); padding: 14px 18px;">
            <span style="font-size: 22px; line-height: 1;">⚠️</span>
            <div style="flex: 1;">
                <strong style="font-size: 14px; display: block; margin-bottom: 4px; color: #991b1b;">
                    Low Stock Alert: <?= count($lowStockItems) ?> product(s) require replenishment
                </strong>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px;">
                    <?php foreach (array_slice($lowStockItems, 0, 5) as $item): ?>
                        <span class="badge badge-danger" style="font-size: 11.5px; padding: 4px 8px;">
                            <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>: 
                            <strong><?= (int)$item['current_stock'] ?></strong> left (min: <?= (int)$item['low_stock_threshold'] ?>)
                        </span>
                    <?php endforeach; ?>
                    <?php if (count($lowStockItems) > 5): ?>
                        <span class="badge badge-outline" style="font-size: 11px; padding: 4px 8px;">+<?= count($lowStockItems) - 5 ?> more</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter Toolbar -->
    <div class="filter-bar">
        <div class="filter-group" style="flex: 2; min-width: 220px;">
            <input type="text" class="form-input" id="searchFilter" placeholder="🔍 Search product name, SKU, or note...">
        </div>

        <div class="filter-group">
            <select class="form-select" id="productFilter">
                <option value="">All Products</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $preselectedProductId === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($p['sku'], ENT_QUOTES, 'UTF-8') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <select class="form-select" id="typeFilter">
                <option value="">All Movement Types</option>
                <option value="RESTOCK">Restock (In)</option>
                <option value="SALE">Sale Deduction (Out)</option>
                <option value="RETURN">Customer Return (In)</option>
                <option value="DAMAGE">Damage / Loss (Out)</option>
                <option value="ADJUSTMENT">Physical Count Adjustment</option>
                <option value="INITIAL">Initial Setup</option>
            </select>
        </div>

        <div class="filter-group" style="max-width: 160px;">
            <input type="date" class="form-input" id="dateFromFilter" title="From Date">
        </div>

        <div class="filter-group" style="max-width: 160px;">
            <input type="date" class="form-input" id="dateToFilter" title="To Date">
        </div>

        <div class="filter-group-fixed">
            <button type="button" class="btn btn-outline" id="resetFiltersBtn" title="Reset all filters">
                Reset
            </button>
        </div>
    </div>

    <!-- Stock Ledger Table Container (.card with padding:0 matching design standard) -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table" id="movementsTable">
                <thead>
                    <tr>
                        <th style="width: 80px;">Log ID</th>
                        <th style="width: 160px;">Timestamp</th>
                        <th>Product &amp; SKU</th>
                        <th style="width: 130px;">Movement Type</th>
                        <th class="text-right" style="width: 100px;">Change Qty</th>
                        <th class="text-right" style="width: 110px;">Stock Before</th>
                        <th class="text-right" style="width: 110px;">Stock After</th>
                        <th>Reason / Reference Notes</th>
                        <th style="width: 140px;">Recorded By</th>
                    </tr>
                </thead>
                <tbody id="movementsTableBody">
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            Loading stock movement ledger...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination Footer -->
        <div class="pagination-container" id="paginationBar">
            <div id="paginationInfo">Showing 0 of 0 movement logs</div>
            <div class="pagination-controls">
                <button type="button" class="btn btn-outline btn-sm" id="prevPageBtn" disabled>&larr; Previous</button>
                <span id="pageIndicator" style="font-weight: 600; padding: 0 8px;">Page 1</span>
                <button type="button" class="btn btn-outline btn-sm" id="nextPageBtn" disabled>Next &rarr;</button>
            </div>
        </div>
    </div>

<!-- ============================================================== -->
<!-- MODAL / DRAWER: Manual Stock Adjustment (#adjustModal)         -->
<!-- ============================================================== -->
<?php if ($canAdjust): ?>
<div class="modal-overlay" id="adjustModal" role="dialog" aria-modal="true" aria-labelledby="adjustModalTitle">
    <div class="modal-card" style="max-width: 540px;">
        <div class="modal-header">
            <h3 class="modal-title" id="adjustModalTitle">Manual Stock Adjustment</h3>
            <button type="button" class="modal-close" id="closeAdjustModal">&times;</button>
        </div>
        <form id="adjustForm" autocomplete="off">
            <div class="modal-body">
                <div id="adjustModalAlert" style="display: none;" class="alert alert-danger"></div>

                <div class="form-group">
                    <label class="form-label" for="adjustProduct">Select Product <span style="color: var(--color-danger);">*</span></label>
                    <select class="form-select" id="adjustProduct" name="product_id" required>
                        <option value="">-- Choose Catalog Product --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" 
                                    data-stock="<?= (int)$p['current_stock'] ?>"
                                    data-threshold="<?= (int)$p['low_stock_threshold'] ?>"
                                    data-sku="<?= htmlspecialchars($p['sku'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $preselectedProductId === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?> [<?= htmlspecialchars($p['sku'], ENT_QUOTES, 'UTF-8') ?>] (Current: <?= (int)$p['current_stock'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Adjustment Type Select (STRICTLY LIMITED TO 3 OPTIONS: Stock In, Stock Out, Fix Count) -->
                <div class="form-group">
                    <label class="form-label" for="adjustType">Adjustment Type <span style="color: var(--color-danger);">*</span></label>
                    <select class="form-select" id="adjustType" name="movement_type" required>
                        <option value="STOCK_IN">Stock In (+)</option>
                        <option value="STOCK_OUT">Stock Out (-)</option>
                        <option value="FIX_COUNT">Fix Count (Physical Audit)</option>
                    </select>
                </div>

                <!-- Quantity Inputs (Dynamic based on selected adjustment type) -->
                <div id="standardQtyGroup" class="form-group">
                    <label class="form-label" id="qtyInputLabel" for="adjustQty">Adjustment Quantity <span style="color: var(--color-danger);">*</span></label>
                    <input type="number" class="form-input" id="adjustQty" name="quantity" required min="1" step="1" value="1">
                    <small style="color: var(--text-muted); font-size: 11px;" id="qtyHelpText">Units received from supplier or warehouse</small>
                </div>

                <div id="fixCountQtyGroup" class="form-group" style="display: none;">
                    <label class="form-label" for="adjustFixCount">New Actual Count (Audit Result) <span style="color: var(--color-danger);">*</span></label>
                    <input type="number" class="form-input" id="adjustFixCount" min="0" step="1" value="0">
                    <small style="color: var(--text-muted); font-size: 11px;">Direct physical inventory count on shelf</small>
                </div>

                <!-- Live Before / After Stock Preview Card (.stock-preview-box from design system) -->
                <div class="stock-preview-box" style="background: #f8fafc; border: 1px dashed var(--border-color); border-radius: var(--radius-md); padding: 12px 16px; margin: 16px 0;">
                    <div class="stock-preview-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; text-align: center;">
                        <div class="stock-preview-item">
                            <span style="color: var(--text-muted); font-size: 11.5px; display: block;">Current Stock</span>
                            <div class="val" id="prevBeforeStock" style="font-size: 16px; font-weight: 700; color: var(--text-main);">0</div>
                        </div>
                        <div class="stock-preview-item">
                            <span style="color: var(--text-muted); font-size: 11.5px; display: block;">Change</span>
                            <div class="val" id="prevChangeQty" style="font-size: 16px; font-weight: 700; color: var(--color-success);">+1</div>
                        </div>
                        <div class="stock-preview-item">
                            <span style="color: var(--text-muted); font-size: 11.5px; display: block;">Resulting Stock</span>
                            <div class="val" id="prevAfterStock" style="font-size: 16px; font-weight: 700; color: var(--color-primary);">1</div>
                        </div>
                    </div>
                    <div id="negativeStockWarning" style="display: none; color: var(--color-danger); font-size: 12px; text-align: center; margin-top: 8px; font-weight: 600;">
                        ⚠️ Warning: Operation results in negative stock and will be rejected.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="adjustNotes">Reason / Adjustment Notes <span style="color: var(--color-danger);">*</span></label>
                    <textarea class="form-textarea" id="adjustNotes" name="notes" rows="2" required placeholder="e.g. Supplier delivery invoice #401, physical count audit variance, or damaged goods write-off..." minlength="3"></textarea>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canAdjust = <?= $canAdjust ? 'true' : 'false' ?>;
    const autoOpen = <?= $autoOpenAdjust ? 'true' : 'false' ?>;

    let currentPage = 1;
    let totalPages = 1;
    let searchDebounceTimer = null;

    // Filters
    const searchFilter = document.getElementById('searchFilter');
    const productFilter = document.getElementById('productFilter');
    const typeFilter = document.getElementById('typeFilter');
    const dateFromFilter = document.getElementById('dateFromFilter');
    const dateToFilter = document.getElementById('dateToFilter');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');

    // Table & Pagination
    const movementsTableBody = document.getElementById('movementsTableBody');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const pageIndicator = document.getElementById('pageIndicator');
    const paginationInfo = document.getElementById('paginationInfo');

    // Adjustment Modal Elements
    const adjustModal = document.getElementById('adjustModal');
    const openAdjustModalBtn = document.getElementById('openAdjustModalBtn');
    const closeAdjustModal = document.getElementById('closeAdjustModal');
    const cancelAdjustBtn = document.getElementById('cancelAdjustBtn');
    const adjustForm = document.getElementById('adjustForm');
    const saveAdjustBtn = document.getElementById('saveAdjustBtn');
    const adjustModalAlert = document.getElementById('adjustModalAlert');

    const adjustProduct = document.getElementById('adjustProduct');
    const adjustType = document.getElementById('adjustType');
    const standardQtyGroup = document.getElementById('standardQtyGroup');
    const fixCountQtyGroup = document.getElementById('fixCountQtyGroup');
    const qtyInputLabel = document.getElementById('qtyInputLabel');
    const qtyHelpText = document.getElementById('qtyHelpText');
    const adjustQty = document.getElementById('adjustQty');
    const adjustFixCount = document.getElementById('adjustFixCount');
    const adjustNotes = document.getElementById('adjustNotes');

    const prevBeforeStock = document.getElementById('prevBeforeStock');
    const prevChangeQty = document.getElementById('prevChangeQty');
    const prevAfterStock = document.getElementById('prevAfterStock');
    const negativeStockWarning = document.getElementById('negativeStockWarning');

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

    // Modal Controls
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

    // -------------------------------------------------------------
    // Dynamic Stock Adjustment Form Fields & Live Calculation
    // -------------------------------------------------------------
    function updateAdjustmentFormFields() {
        if (!adjustType) return;
        const type = adjustType.value;

        if (type === 'STOCK_IN') {
            standardQtyGroup.style.display = 'block';
            fixCountQtyGroup.style.display = 'none';
            qtyInputLabel.textContent = 'Quantity to Add (Stock In) *';
            qtyHelpText.textContent = 'Units received from supplier or warehouse';
            adjustQty.required = true;
            if (adjustFixCount) adjustFixCount.required = false;
        } else if (type === 'STOCK_OUT') {
            standardQtyGroup.style.display = 'block';
            fixCountQtyGroup.style.display = 'none';
            qtyInputLabel.textContent = 'Quantity to Deduct (Stock Out) *';
            qtyHelpText.textContent = 'Damaged, expired, or written-off units';
            adjustQty.required = true;
            if (adjustFixCount) adjustFixCount.required = false;
        } else if (type === 'FIX_COUNT') {
            standardQtyGroup.style.display = 'none';
            fixCountQtyGroup.style.display = 'block';
            adjustQty.required = false;
            if (adjustFixCount) adjustFixCount.required = true;
        }

        updateStockPreview();
    }

    function updateStockPreview() {
        if (!adjustProduct || !adjustType || !prevBeforeStock) return;

        const selectedOption = adjustProduct.options[adjustProduct.selectedIndex];
        const currentStock = selectedOption && selectedOption.value ? parseInt(selectedOption.dataset.stock || '0', 10) : 0;
        const type = adjustType.value;

        let delta = 0;
        let resultingStock = currentStock;

        if (type === 'STOCK_IN') {
            const qty = Math.max(0, parseInt(adjustQty.value, 10) || 0);
            delta = qty;
            resultingStock = currentStock + delta;
            prevChangeQty.textContent = (delta >= 0 ? '+' : '') + delta;
            prevChangeQty.style.color = 'var(--color-success)';
        } else if (type === 'STOCK_OUT') {
            const qty = Math.max(0, parseInt(adjustQty.value, 10) || 0);
            delta = -qty;
            resultingStock = currentStock + delta;
            prevChangeQty.textContent = (delta >= 0 ? '+' : '') + delta;
            prevChangeQty.style.color = 'var(--color-danger)';
        } else if (type === 'FIX_COUNT') {
            const counted = Math.max(0, parseInt(adjustFixCount.value, 10) || 0);
            resultingStock = counted;
            delta = resultingStock - currentStock;
            prevChangeQty.textContent = (delta >= 0 ? '+' : '') + delta;
            prevChangeQty.style.color = delta >= 0 ? 'var(--color-success)' : 'var(--color-danger)';
        }

        prevBeforeStock.textContent = currentStock;
        prevAfterStock.textContent = resultingStock;

        if (resultingStock < 0) {
            prevAfterStock.style.color = 'var(--color-danger)';
            negativeStockWarning.textContent = '⚠️ Warning: Operation results in negative stock and will be rejected.';
            negativeStockWarning.style.display = 'block';
        } else {
            prevAfterStock.style.color = 'var(--text-main)';
            negativeStockWarning.style.display = 'none';
        }
    }

    if (adjustType) {
        adjustType.addEventListener('change', updateAdjustmentFormFields);
    }
    if (adjustProduct) {
        adjustProduct.addEventListener('change', () => {
            const selectedOption = adjustProduct.options[adjustProduct.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const stock = parseInt(selectedOption.dataset.stock || '0', 10);
                if (adjustFixCount) adjustFixCount.value = stock;
            }
            updateStockPreview();
        });
    }
    if (adjustQty) {
        adjustQty.addEventListener('input', updateStockPreview);
    }
    if (adjustFixCount) {
        adjustFixCount.addEventListener('input', updateStockPreview);
    }

    // -------------------------------------------------------------
    // Load Movement Ledger
    // -------------------------------------------------------------
    async function loadMovements(page = 1) {
        currentPage = page;
        movementsTableBody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                    Loading movement logs...
                </td>
            </tr>`;

        const params = new URLSearchParams({
            action: 'list_movements',
            page: currentPage,
            limit: 15,
            search: searchFilter.value.trim(),
            product_id: productFilter.value,
            movement_type: typeFilter.value,
            date_from: dateFromFilter.value,
            date_to: dateToFilter.value
        });

        const res = await API.get(`handler.php?${params.toString()}`);
        if (!res || !res.success || !res.data) {
            movementsTableBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: var(--color-danger);">
                        Failed to load movements. Please check your network connection and retry.
                    </td>
                </tr>`;
            return;
        }

        const { movements, pagination } = res.data;
        totalPages = pagination.total_pages || 1;

        if (!movements || movements.length === 0) {
            movementsTableBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
                        <div style="font-size: 36px; margin-bottom: 8px;">📋</div>
                        <div style="font-weight: 600; font-size: 15px; color: var(--text-main);">No Stock Movements Found</div>
                        <p style="font-size: 13px; margin: 4px 0 0 0;">No movement records match your selected filter criteria.</p>
                    </td>
                </tr>`;
        } else {
            let html = '';
            movements.forEach(m => {
                const rawQty = parseInt(m.quantity, 10) || 0;
                const before = parseInt(m.stock_before, 10);
                const after = parseInt(m.stock_after, 10);
                const hasValidSnapshots = !isNaN(before) && !isNaN(after);
                const diff = hasValidSnapshots ? (after - before) : rawQty;
                const changeAmount = hasValidSnapshots && diff !== 0 ? Math.abs(diff) : Math.abs(rawQty);
                const isDeduction = diff < 0 || (!hasValidSnapshots && rawQty < 0);
                const isIncrease = diff > 0 || (!hasValidSnapshots && rawQty > 0);
                const signPrefix = isIncrease ? '+' : (isDeduction ? '-' : '');

                // Movement Type Badges
                let typeBadge = '';
                switch (m.movement_type) {
                    case 'RESTOCK':
                        typeBadge = '<span class="badge badge-success">RESTOCK</span>';
                        break;
                    case 'SALE':
                        typeBadge = '<span class="badge badge-warning">SALE</span>';
                        break;
                    case 'RETURN':
                        typeBadge = '<span class="badge badge-info">RETURN</span>';
                        break;
                    case 'DAMAGE':
                        typeBadge = '<span class="badge badge-danger">DAMAGE</span>';
                        break;
                    case 'ADJUSTMENT':
                        typeBadge = '<span class="badge badge-primary">ADJUSTMENT</span>';
                        break;
                    case 'INITIAL':
                        typeBadge = '<span class="badge badge-outline">INITIAL</span>';
                        break;
                    default:
                        typeBadge = `<span class="badge badge-outline">${escapeHtml(m.movement_type)}</span>`;
                }

                const dateFormatted = m.created_at 
                    ? new Date(m.created_at).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
                    : '—';

                // Enrich note with Order Reference link if available
                let noteHtml = escapeHtml(m.notes || '—');
                const orderMatch = (m.notes || '').match(/ORD-\d{4}-\d+/);
                if (orderMatch) {
                    const ordNum = orderMatch[0];
                    noteHtml = noteHtml.replace(
                        ordNum, 
                        `<a href="<?= BASE_URL ?>/modules/orders/?search=${encodeURIComponent(ordNum)}" style="font-family: monospace; color: var(--color-primary); font-weight: 600; text-decoration: underline;">${escapeHtml(ordNum)}</a>`
                    );
                }

                html += `
                    <tr>
                        <td class="tabular-nums" style="color: var(--text-muted); font-size: 12px; font-family: monospace;">#${m.id}</td>
                        <td style="font-size: 12.5px; color: var(--text-muted); white-space: nowrap;">${dateFormatted}</td>
                        <td>
                            <strong style="color: var(--text-main); font-size: 13.5px;">${escapeHtml(m.product_name)}</strong>
                            <div class="tabular-nums font-mono" style="font-size: 11.5px; color: var(--color-primary); font-family: monospace; font-weight: 600;">${escapeHtml(m.sku)}</div>
                        </td>
                        <td>${typeBadge}</td>
                        <td class="text-right tabular-nums" style="font-weight: 700; color: ${isDeduction ? 'var(--color-danger)' : (isIncrease ? 'var(--color-success)' : 'var(--text-muted)')};">
                            ${signPrefix}${changeAmount}
                        </td>
                        <td class="text-right tabular-nums" style="color: var(--text-muted);">${before}</td>
                        <td class="text-right tabular-nums" style="font-weight: 600;">${after}</td>
                        <td style="font-size: 12.5px; color: var(--text-main);">
                            ${noteHtml}
                        </td>
                        <td style="font-size: 12.5px;">
                            ${escapeHtml(m.user_name || m.username || 'System')}
                        </td>
                    </tr>
                `;
            });
            movementsTableBody.innerHTML = html;
        }

        // Pagination Update
        paginationInfo.textContent = `Showing ${(pagination.total > 0 ? (pagination.page - 1) * pagination.limit + 1 : 0)} to ${Math.min(pagination.page * pagination.limit, pagination.total)} of ${pagination.total} records`;
        pageIndicator.textContent = `Page ${pagination.page} of ${Math.max(1, pagination.total_pages)}`;
        prevPageBtn.disabled = pagination.page <= 1;
        nextPageBtn.disabled = pagination.page >= pagination.total_pages;
    }

    // Filter Listeners
    searchFilter.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => loadMovements(1), 300);
    });

    productFilter.addEventListener('change', () => loadMovements(1));
    typeFilter.addEventListener('change', () => loadMovements(1));
    dateFromFilter.addEventListener('change', () => loadMovements(1));
    dateToFilter.addEventListener('change', () => loadMovements(1));

    resetFiltersBtn.addEventListener('click', () => {
        searchFilter.value = '';
        productFilter.value = '';
        typeFilter.value = '';
        dateFromFilter.value = '';
        dateToFilter.value = '';
        loadMovements(1);
    });

    // Pagination Listeners
    prevPageBtn.addEventListener('click', () => {
        if (currentPage > 1) loadMovements(currentPage - 1);
    });
    nextPageBtn.addEventListener('click', () => {
        if (currentPage < totalPages) loadMovements(currentPage + 1);
    });

    // Adjustment Modal Handlers
    if (openAdjustModalBtn) {
        openAdjustModalBtn.addEventListener('click', () => {
            adjustForm.reset();
            adjustModalAlert.style.display = 'none';
            adjustType.value = 'STOCK_IN';
            adjustQty.value = '1';
            adjustNotes.value = '';
            updateAdjustmentFormFields();
            openModal(adjustModal);
            setTimeout(() => adjustProduct.focus(), 50);
        });
    }

    if (closeAdjustModal) closeAdjustModal.addEventListener('click', () => closeModal(adjustModal));
    if (cancelAdjustBtn) cancelAdjustBtn.addEventListener('click', () => closeModal(adjustModal));

    let adjustMouseDownTarget = null;
    if (adjustModal) {
        adjustModal.addEventListener('mousedown', (e) => {
            adjustMouseDownTarget = e.target;
        });
        adjustModal.addEventListener('click', (e) => {
            if (e.target === adjustModal && adjustMouseDownTarget === adjustModal) {
                closeModal(adjustModal);
            }
            adjustMouseDownTarget = null;
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeModal(adjustModal);
    });

    // Adjustment Form Submission
    if (adjustForm) {
        adjustForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            adjustModalAlert.style.display = 'none';

            const productId = parseInt(adjustProduct.value, 10);
            if (!productId) {
                adjustModalAlert.textContent = 'Please choose a product to adjust.';
                adjustModalAlert.style.display = 'block';
                return;
            }

            const type = adjustType.value;
            const selectedOption = adjustProduct.options[adjustProduct.selectedIndex];
            const currentStock = selectedOption ? parseInt(selectedOption.dataset.stock || '0', 10) : 0;

            const payload = {
                action: 'adjust_stock',
                product_id: productId,
                movement_type: type,
                notes: adjustNotes.value.trim()
            };

            if (type === 'FIX_COUNT') {
                const newCount = parseInt(adjustFixCount.value, 10);
                if (isNaN(newCount) || newCount < 0) {
                    adjustModalAlert.textContent = 'Actual count cannot be negative.';
                    adjustModalAlert.style.display = 'block';
                    return;
                }
                payload.new_count = newCount;
                payload.quantity = Math.abs(newCount - currentStock);
            } else {
                const qty = parseInt(adjustQty.value, 10);
                if (!qty || qty <= 0) {
                    adjustModalAlert.textContent = 'Adjustment quantity must be greater than zero.';
                    adjustModalAlert.style.display = 'block';
                    return;
                }
                if (type === 'STOCK_OUT' && qty > currentStock) {
                    adjustModalAlert.textContent = `Deduction quantity (${qty}) exceeds available stock (${currentStock}). Negative stock is not allowed.`;
                    adjustModalAlert.style.display = 'block';
                    return;
                }
                payload.quantity = qty;
                payload.direction = (type === 'STOCK_OUT') ? 'SUBTRACT' : 'ADD';
            }

            saveAdjustBtn.disabled = true;
            saveAdjustBtn.textContent = 'Posting...';

            try {
                const res = await API.post('handler.php', payload);
                if (res && res.success) {
                    API.toast(res.message || 'Stock adjusted successfully.', 'success');
                    closeModal(adjustModal);

                    // Update product option's dataset stock for subsequent adjustments
                    if (selectedOption && res.data && typeof res.data.stock_after !== 'undefined') {
                        selectedOption.dataset.stock = res.data.stock_after;
                        const pName = selectedOption.dataset.name || '';
                        const pSku = selectedOption.dataset.sku || '';
                        selectedOption.textContent = `${pName} [${pSku}] (Current: ${res.data.stock_after})`;
                    }

                    loadMovements(1);
                } else {
                    const msg = (res && res.message) ? res.message : 'Adjustment failed.';
                    adjustModalAlert.textContent = msg;
                    adjustModalAlert.style.display = 'block';
                }
            } catch (err) {
                console.error('Stock adjustment submission error:', err);
                adjustModalAlert.textContent = 'Network or server communication error.';
                adjustModalAlert.style.display = 'block';
            } finally {
                saveAdjustBtn.disabled = false;
                saveAdjustBtn.textContent = 'Post Stock Movement';
            }
        });
    }

    // Auto open adjustment modal if requested in URL
    if (autoOpen && openAdjustModalBtn) {
        openAdjustModalBtn.click();
    }

    // Initial Load
    loadMovements(1);
});
</script>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
