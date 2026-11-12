<?php
/**
 * Inventory Stock Ledger & Adjustments View
 * Small Business Sales and Expense Tracking System
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
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">Stock Movement Ledger</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            Immutable audit trail of all inventory additions, sales deductions, damages, and returns.
        </p>
    </div>

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

<!-- Low Stock Warning Banner -->
<?php if (!empty($lowStockItems)): ?>
    <div class="alert alert-danger" style="margin-bottom: 24px; border-left: 4px solid var(--color-danger); display: flex; align-items: flex-start; gap: 14px;">
        <span style="font-size: 22px;">⚠️</span>
        <div style="flex: 1;">
            <strong style="font-size: 14px; display: block; margin-bottom: 4px;">
                Low Stock Alert: <?= count($lowStockItems) ?> product(s) require replenishment
            </strong>
            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px;">
                <?php foreach (array_slice($lowStockItems, 0, 5) as $item): ?>
                    <span class="badge badge-danger" style="font-size: 11.5px;">
                        <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>: 
                        <strong><?= (int)$item['current_stock'] ?></strong> left (min: <?= (int)$item['low_stock_threshold'] ?>)
                    </span>
                <?php endforeach; ?>
                <?php if (count($lowStockItems) > 5): ?>
                    <span class="badge badge-outline" style="font-size: 11px;">+<?= count($lowStockItems) - 5 ?> more</span>
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

<!-- Stock Ledger Table Container -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" id="movementsTable">
            <thead>
                <tr>
                    <th style="width: 80px;">Log ID</th>
                    <th style="width: 160px;">Timestamp</th>
                    <th>Product & SKU</th>
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
                        Loading stock ledger...
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

<?php if ($canAdjust): ?>
<!-- Stock Adjustment Modal Dialog -->
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
                                    <?= $preselectedProductId === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?> [<?= htmlspecialchars($p['sku'], ENT_QUOTES, 'UTF-8') ?>] — Current: <?= (int)$p['current_stock'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label" for="adjustType">Adjustment Type <span style="color: var(--color-danger);">*</span></label>
                        <select class="form-select" id="adjustType" name="movement_type" required>
                            <option value="RESTOCK">Restock / Add (+)</option>
                            <option value="DAMAGE">Damaged / Write-off (-)</option>
                            <option value="RETURN">Customer Return (+)</option>
                            <option value="ADJUSTMENT">Inventory Count Sync (±)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="adjustQty">Adjustment Quantity <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input" id="adjustQty" name="quantity" required min="1" step="1" value="1">
                    </div>
                </div>

                <!-- Adjustment Direction for Type = ADJUSTMENT -->
                <div class="form-group" id="adjustDirectionGroup" style="display: none;">
                    <label class="form-label">Count Direction</label>
                    <div style="display: flex; gap: 16px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="radio" name="direction" value="ADD" checked> Add to Stock (+)
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="radio" name="direction" value="SUBTRACT"> Deduct from Stock (-)
                        </label>
                    </div>
                </div>

                <!-- Live Before / After Stock Preview Card -->
                <div class="stock-preview-box">
                    <div class="stock-preview-grid">
                        <div class="stock-preview-item">
                            <span style="color: var(--text-muted); font-size: 11.5px;">Current Stock</span>
                            <div class="val" id="prevBeforeStock">0</div>
                        </div>
                        <div class="stock-preview-item">
                            <span style="color: var(--text-muted); font-size: 11.5px;">Change</span>
                            <div class="val" id="prevChangeQty" style="color: var(--color-success);">+1</div>
                        </div>
                        <div class="stock-preview-item">
                            <span style="color: var(--text-muted); font-size: 11.5px;">Resulting Stock</span>
                            <div class="val" id="prevAfterStock">1</div>
                        </div>
                    </div>
                    <div id="negativeStockWarning" style="display: none; color: var(--color-danger); font-size: 12px; text-align: center; margin-top: 8px; font-weight: 600;">
                        ⚠️ Warning: Operation results in negative stock and will be rejected.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="adjustNotes">Reason / Adjustment Notes <span style="color: var(--color-danger);">*</span></label>
                    <textarea class="form-textarea" id="adjustNotes" name="notes" rows="2" required placeholder="e.g. Received shipment from supplier / Damaged box during handling"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelAdjustBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveAdjustBtn">Confirm Adjustment</button>
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
    const adjustQty = document.getElementById('adjustQty');
    const adjustNotes = document.getElementById('adjustNotes');
    const adjustDirectionGroup = document.getElementById('adjustDirectionGroup');
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
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Live Stock Calculation Preview
    function updateStockPreview() {
        if (!adjustProduct || !adjustQty || !adjustType) return;

        const selectedOption = adjustProduct.options[adjustProduct.selectedIndex];
        const currentStock = selectedOption ? parseInt(selectedOption.dataset.stock || '0', 10) : 0;
        const qty = Math.max(1, parseInt(adjustQty.value || '1', 10));
        const type = adjustType.value;

        let delta = qty;
        if (type === 'RESTOCK' || type === 'RETURN') {
            delta = qty;
        } else if (type === 'DAMAGE') {
            delta = -qty;
        } else if (type === 'ADJUSTMENT') {
            const dir = document.querySelector('input[name="direction"]:checked')?.value || 'ADD';
            delta = (dir === 'SUBTRACT') ? -qty : qty;
        }

        const resultingStock = currentStock + delta;

        prevBeforeStock.textContent = currentStock;
        prevChangeQty.textContent = (delta >= 0 ? '+' : '') + delta;
        prevChangeQty.style.color = delta >= 0 ? 'var(--color-success)' : 'var(--color-danger)';
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

    if (adjustProduct && adjustQty && adjustType) {
        adjustProduct.addEventListener('change', updateStockPreview);
        adjustQty.addEventListener('input', updateStockPreview);
        adjustType.addEventListener('change', () => {
            if (adjustDirectionGroup) {
                adjustDirectionGroup.style.display = (adjustType.value === 'ADJUSTMENT') ? 'block' : 'none';
            }
            updateStockPreview();
        });
        document.querySelectorAll('input[name="direction"]').forEach(r => {
            r.addEventListener('change', updateStockPreview);
        });
    }

    // Load Movement Ledger
    async function loadMovements(page = 1) {
        currentPage = page;
        movementsTableBody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
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
                    <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        No movement records found matching the criteria.
                    </td>
                </tr>`;
        } else {
            let html = '';
            movements.forEach(m => {
                const qty = parseInt(m.quantity, 10);
                const before = parseInt(m.stock_before, 10);
                const after = parseInt(m.stock_after, 10);
                const isIncrease = after >= before;

                // Movement Type Badges
                let typeBadge = '';
                switch (m.movement_type) {
                    case 'RESTOCK':
                        typeBadge = '<span class="badge badge-success">Restock</span>';
                        break;
                    case 'SALE':
                        typeBadge = '<span class="badge badge-confirmed">Sale (Out)</span>';
                        break;
                    case 'RETURN':
                        typeBadge = '<span class="badge badge-info">Return (In)</span>';
                        break;
                    case 'DAMAGE':
                        typeBadge = '<span class="badge badge-danger">Damage (Out)</span>';
                        break;
                    case 'ADJUSTMENT':
                        typeBadge = '<span class="badge badge-warning">Count Sync</span>';
                        break;
                    case 'INITIAL':
                        typeBadge = '<span class="badge badge-inactive">Initial Setup</span>';
                        break;
                    default:
                        typeBadge = `<span class="badge badge-inactive">${escapeHtml(m.movement_type)}</span>`;
                }

                const dateFormatted = m.created_at 
                    ? new Date(m.created_at).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
                    : '—';

                html += `
                    <tr>
                        <td class="tabular-nums" style="color: var(--text-muted); font-size: 12px;">#${m.id}</td>
                        <td style="font-size: 12.5px; color: var(--text-muted); white-space: nowrap;">${dateFormatted}</td>
                        <td>
                            <strong style="color: var(--text-main); font-size: 13.5px;">${escapeHtml(m.product_name)}</strong>
                            <div class="tabular-nums font-mono" style="font-size: 11.5px; color: var(--text-muted);">${escapeHtml(m.sku)}</div>
                        </td>
                        <td>${typeBadge}</td>
                        <td class="text-right tabular-nums" style="font-weight: 700; color: ${isIncrease ? 'var(--color-success)' : 'var(--color-danger)'};">
                            ${isIncrease ? '+' : '-'}${qty}
                        </td>
                        <td class="text-right tabular-nums" style="color: var(--text-muted);">${before}</td>
                        <td class="text-right tabular-nums" style="font-weight: 600;">${after}</td>
                        <td style="font-size: 13px; color: var(--text-muted);">
                            ${m.notes ? escapeHtml(m.notes) : '<em style="color: #94a3b8;">None</em>'}
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
            updateStockPreview();
            openModal(adjustModal);
            setTimeout(() => adjustProduct.focus(), 50);
        });
    }

    if (closeAdjustModal) closeAdjustModal.addEventListener('click', () => closeModal(adjustModal));
    if (cancelAdjustBtn) cancelAdjustBtn.addEventListener('click', () => closeModal(adjustModal));

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeModal(adjustModal);
    });

    // Adjustment Form Submission
    if (adjustForm) {
        adjustForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            adjustModalAlert.style.display = 'none';

            const selectedOption = adjustProduct.options[adjustProduct.selectedIndex];
            const currentStock = selectedOption ? parseInt(selectedOption.dataset.stock || '0', 10) : 0;
            const qty = parseInt(adjustQty.value, 10) || 1;
            const type = adjustType.value;
            let delta = qty;
            if (type === 'RESTOCK' || type === 'RETURN') {
                delta = qty;
            } else if (type === 'DAMAGE') {
                delta = -qty;
            } else if (type === 'ADJUSTMENT') {
                const dir = document.querySelector('input[name="direction"]:checked')?.value || 'ADD';
                delta = (dir === 'SUBTRACT') ? -qty : qty;
            }

            if (currentStock + delta < 0) {
                adjustModalAlert.textContent = `Deduction quantity (${qty}) exceeds available stock (${currentStock}). Negative stock is not allowed.`;
                adjustModalAlert.style.display = 'block';
                return;
            }

            saveAdjustBtn.disabled = true;
            saveAdjustBtn.textContent = 'Saving...';

            const payload = {
                action: 'adjust_stock',
                product_id: parseInt(adjustProduct.value, 10),
                movement_type: adjustType.value,
                quantity: parseInt(adjustQty.value, 10) || 1,
                direction: document.querySelector('input[name="direction"]:checked')?.value || 'ADD',
                notes: adjustNotes.value.trim()
            };

            const res = await API.post('handler.php', payload);
            saveAdjustBtn.disabled = false;
            saveAdjustBtn.textContent = 'Confirm Adjustment';

            if (res && res.success) {
                API.toast(res.message || 'Stock adjusted successfully.', 'success');
                closeModal(adjustModal);

                // Update product option's dataset stock for subsequent adjustments
                const opt = adjustProduct.querySelector(`option[value="${payload.product_id}"]`);
                if (opt && res.data && typeof res.data.stock_after !== 'undefined') {
                    opt.dataset.stock = res.data.stock_after;
                    const cleanName = opt.textContent.split('— Current:')[0].trim();
                    opt.textContent = `${cleanName} — Current: ${res.data.stock_after}`;
                }

                loadMovements(1);
            } else {
                const msg = (res && res.message) ? res.message : 'Adjustment failed.';
                adjustModalAlert.textContent = msg;
                adjustModalAlert.style.display = 'block';
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

