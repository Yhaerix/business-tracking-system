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
    <div class="page-header" style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px;">
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
            <table class="table table-hover" id="productsTable">
                <thead>
                    <tr>
                        <th style="width: 54px; text-align: center;">Photo</th>
                        <th>Product</th>
                        <th style="width: 140px;">Category</th>
                        <th class="text-right" style="width: 140px; white-space: nowrap;">Price</th>
                        <th style="width: 140px;">Stock</th>
                        <th style="width: 120px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="productTableBody">
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
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
    <div class="modal-card" style="max-width: 640px; width: 95%; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="productModalTitle">Add New Product</h3>
                <p id="productModalSubtitle" style="font-size: 12.5px; color: var(--text-muted); margin: 2px 0 0 0;">Prestige Apple Store Device Catalog</p>
            </div>
            <button type="button" class="modal-close" id="closeAddProductModalBtn">&times;</button>
        </div>
        <form id="productForm" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="id" id="prodId" value="0">
            <div class="modal-body" style="padding-top: 14px;">
                <div id="prodModalAlert" style="display: none;" class="alert alert-danger"></div>

                <!-- 1. Integrated Device Photo Upload Section -->
                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 16px;">
                    <div id="prodPhotoPreviewBox" style="width: 82px; height: 82px; min-width: 82px; border-radius: 10px; border: 2px dashed #cbd5e1; background: #ffffff; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; cursor: pointer;" title="Click to choose photo">
                        <img id="prodPhotoPreviewImg" src="" alt="Preview" style="display: none; width: 100%; height: 100%; object-fit: contain;">
                        <div id="prodPhotoPlaceholder" style="text-align: center; color: var(--text-muted); padding: 6px;">
                            <div style="font-size: 26px; line-height: 1;">📷</div>
                            <div style="font-size: 10.5px; margin-top: 3px; font-weight: 500;">Add Photo</div>
                        </div>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 600; font-size: 13.5px; color: var(--text-main); margin-bottom: 2px;">Product Photo</div>
                        <p style="font-size: 11.5px; color: var(--text-muted); margin: 0 0 8px 0;">Upload device image (JPG, PNG, or WebP up to 5MB). Displayed as primary photo across POS and storefront.</p>
                        <input type="file" id="prodPhotoInput" name="photo" accept="image/jpeg,image/png,image/webp" style="display: none;">
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="btn btn-outline btn-sm" id="chooseProdPhotoBtn">
                                📷 Choose Photo
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" id="removeProdPhotoBtn" style="display: none; color: var(--color-danger); border-color: #fca5a5;">
                                Remove
                            </button>
                            <span id="prodPhotoNameText" style="font-size: 11.5px; color: var(--color-primary); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px;"></span>
                        </div>
                    </div>
                </div>

                <!-- 2. Product Name -->
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="prodName">Product Name <span style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="prodName" name="name" required maxlength="150" placeholder="e.g. iPhone 16 Pro Max 256GB">
                </div>

                <!-- 3. SKU & Category (Matching clean 2-column layout) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="prodSku">SKU (Stock Keeping Unit)</label>
                        <input type="text" class="form-input" id="prodSku" name="sku" placeholder="Auto-generated if blank" maxlength="64" style="font-family: monospace;">
                        <small style="color: var(--text-muted); font-size: 11px;">Leave blank for sequential SKU</small>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="prodCategory">Category <span style="color: var(--color-danger);">*</span></label>
                        <select class="form-select" id="prodCategory" name="category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- 4. Cost Price & Selling Price -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 8px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="prodCostPrice">Cost Price (₱) <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input" id="prodCostPrice" name="cost_price" required min="0" step="0.01" value="0.00">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="prodSellingPrice">Selling Price (₱) <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input" id="prodSellingPrice" name="selling_price" required min="0" step="0.01" value="0.00">
                    </div>
                </div>

                <!-- 5. Live Margin Estimation Preview -->
                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 9px 14px; margin-bottom: 14px; font-size: 12.5px; display: flex; justify-content: space-between; align-items: center;">
                    <span>Unit Margin: <strong id="marginAmount" style="color: var(--color-success); font-size: 13.5px;">₱ 0.00</strong></span>
                    <span>Markup: <strong id="marginPercent" style="color: var(--color-primary); font-size: 13.5px;">0.0%</strong></span>
                </div>

                <!-- 6. Initial Stock & Low Stock Threshold -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div class="form-group" id="initialStockGroup" style="margin-bottom: 0;">
                        <label class="form-label" for="prodStock">Initial Stock Qty</label>
                        <input type="number" class="form-input" id="prodStock" name="current_stock" min="0" step="1" value="0">
                        <small style="color: var(--text-muted); font-size: 11px;" id="stockHelpText">Starting inventory on shelf</small>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="prodThreshold">Low Stock Threshold <span style="color: var(--color-danger);">*</span></label>
                        <input type="number" class="form-input" id="prodThreshold" name="low_stock_threshold" required min="0" step="1" value="5">
                        <small style="color: var(--text-muted); font-size: 11px;">Triggers replenishment alert</small>
                    </div>
                </div>

                <!-- 7. Catalog Status -->
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="prodStatus">Catalog Status</label>
                    <select class="form-select" id="prodStatus" name="status">
                        <option value="active">Active (Available for POS &amp; Sales)</option>
                        <option value="inactive">Inactive / Archived</option>
                    </select>
                </div>

                <!-- 8. Apple Device Specifications & Warranty -->
                <div style="padding-top: 14px; border-top: 1px dashed var(--border-color); margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-primary);">
                            🍎 DEVICE SPECIFICATIONS &amp; WARRANTY
                        </span>
                        <small style="color: var(--text-muted); font-size: 11px;">Prestige Storefront Attributes</small>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="prodStorage">Storage / Capacity</label>
                            <input type="text" class="form-input" id="prodStorage" name="storage" list="appleStorageList" placeholder="e.g. 256GB, 512GB, 16GB/256GB">
                            <datalist id="appleStorageList">
                                <option value="64GB">
                                <option value="128GB">
                                <option value="256GB">
                                <option value="512GB">
                                <option value="1TB">
                                <option value="16GB / 256GB SSD">
                                <option value="16GB / 512GB SSD">
                                <option value="18GB / 512GB SSD">
                                <option value="N/A">
                            </datalist>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="prodColor">Color / Finish</label>
                            <input type="text" class="form-input" id="prodColor" name="color" list="appleColorList" placeholder="e.g. Desert Titanium, Space Black">
                            <datalist id="appleColorList">
                                <option value="Desert Titanium">
                                <option value="Natural Titanium">
                                <option value="White Titanium">
                                <option value="Black Titanium">
                                <option value="Space Black">
                                <option value="Midnight">
                                <option value="Starlight">
                                <option value="Silver">
                                <option value="Teal">
                                <option value="Ultramarine">
                                <option value="Jet Black">
                                <option value="White">
                            </datalist>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="prodCondition">Device Condition</label>
                            <select class="form-select" id="prodCondition" name="condition_type">
                                <option value="Brand New Sealed">Brand New Sealed</option>
                                <option value="Certified Pre-Owned">Certified Pre-Owned</option>
                                <option value="Open Box">Open Box</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="prodWarranty">Warranty Coverage</label>
                            <input type="text" class="form-input" id="prodWarranty" name="warranty" value="1 Year Official Apple Warranty">
                        </div>
                    </div>
                </div>

                <!-- 9. Optional Hardware Highlights & Storefront Description (Compact) -->
                <details style="margin-top: 6px; padding: 8px 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                    <summary style="font-size: 12px; font-weight: 600; color: var(--text-muted); cursor: pointer; user-select: none;">
                        Additional Specs &amp; Description (Optional)
                    </summary>
                    <div style="padding-top: 10px; display: flex; flex-direction: column; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="prodSpecs" style="font-size: 11.5px;">Key Specifications (Hardware Highlights)</label>
                            <textarea class="form-textarea" id="prodSpecs" name="specs" rows="2" placeholder="e.g. Chip: A18 Pro | Display: 6.9-inch Super Retina XDR OLED ProMotion 120Hz | Camera: 48MP Fusion"></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="prodDescription" style="font-size: 11.5px;">Storefront Product Description</label>
                            <textarea class="form-textarea" id="prodDescription" name="description" rows="2" placeholder="Overview of features, battery life, design finish, and customer inclusions..."></textarea>
                        </div>
                    </div>
                </details>
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

<!-- ============================================================== -->
<!-- 4. MODAL: Product Media Gallery (#productGalleryModal)         -->
<!-- ============================================================== -->
<div class="modal-overlay" id="productGalleryModal" role="dialog" aria-modal="true" aria-labelledby="galleryModalTitle" style="display: none;">
    <div class="modal-card" style="max-width: 680px; width: 95%;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="galleryModalTitle" style="display: flex; align-items: center; gap: 8px;">
                    <span>📸</span> Product Media Gallery
                </h3>
                <p id="galleryModalSubtitle" style="font-size: 12.5px; color: var(--text-muted); margin: 3px 0 0 0;">
                    Manage high-resolution Apple storefront photos
                </p>
            </div>
            <button type="button" class="modal-close" id="closeGalleryModalBtn">&times;</button>
        </div>
        <div class="modal-body" style="padding-top: 16px;">
            <div id="galleryModalAlert" style="display: none;" class="alert alert-danger"></div>
            <div id="galleryModalSuccess" style="display: none;" class="alert alert-success"></div>

            <?php if ($canManage): ?>
            <!-- Multi-Image Upload Box -->
            <div style="background: #f8fafc; border: 2px dashed var(--border-color); border-radius: var(--radius-md); padding: 18px; text-align: center; margin-bottom: 20px;">
                <input type="file" id="galleryFileInput" multiple accept="image/jpeg,image/png,image/webp" style="display: none;">
                <div style="font-size: 28px; margin-bottom: 6px;">🖼️</div>
                <div style="font-weight: 600; font-size: 13.5px; color: var(--text-main); margin-bottom: 4px;">Upload High-Resolution Device Photos</div>
                <p style="font-size: 12px; color: var(--text-muted); margin: 0 0 12px 0;">Supports JPG, PNG, and WebP (up to 5MB each). First image is automatically set as Primary.</p>
                <div style="display: flex; gap: 10px; justify-content: center; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-outline btn-sm" id="chooseImagesBtn">
                        📁 Select Photos
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="startUploadBtn" style="display: none;">
                        ⬆️ Upload Selected (<span id="selectedCountText">0</span>)
                    </button>
                </div>
                <div id="selectedFilesSummary" style="font-size: 12px; color: var(--color-primary); font-weight: 500; margin-top: 10px; display: none;"></div>
            </div>
            <?php endif; ?>

            <!-- Gallery Images Grid Header -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-weight: 600; font-size: 13.5px; color: var(--text-main);">
                    Gallery Images (<span id="galleryImageCount">0</span>)
                </span>
                <small style="color: var(--text-muted); font-size: 11.5px;">⭐ Star marks the primary catalog thumbnail</small>
            </div>

            <!-- Image Grid Container -->
            <div id="galleryGridContainer" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 12px; max-height: 360px; overflow-y: auto; padding: 4px;">
                <div style="grid-column: 1 / -1; text-align: center; padding: 32px 16px; color: var(--text-muted);">
                    Loading images...
                </div>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 11.5px; color: var(--text-muted);">Prestige Media Engine v2.0</span>
            <button type="button" class="btn btn-outline" id="doneGalleryBtn">Done</button>
        </div>
    </div>
</div>

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

    // Apple Device Attributes
    const prodStorage = document.getElementById('prodStorage');
    const prodColor = document.getElementById('prodColor');
    const prodCondition = document.getElementById('prodCondition');
    const prodWarranty = document.getElementById('prodWarranty');
    const prodSpecs = document.getElementById('prodSpecs');
    const prodDescription = document.getElementById('prodDescription');

    // Add/Edit Modal Integrated Photo Elements
    const prodPhotoInput = document.getElementById('prodPhotoInput');
    const chooseProdPhotoBtn = document.getElementById('chooseProdPhotoBtn');
    const removeProdPhotoBtn = document.getElementById('removeProdPhotoBtn');
    const prodPhotoPreviewImg = document.getElementById('prodPhotoPreviewImg');
    const prodPhotoPlaceholder = document.getElementById('prodPhotoPlaceholder');
    const prodPhotoNameText = document.getElementById('prodPhotoNameText');
    let selectedProdPhotoFile = null;
    let currentExistingPhotoUrl = null;

    // Gallery Modal Elements
    const productGalleryModal = document.getElementById('productGalleryModal');
    const closeGalleryModalBtn = document.getElementById('closeGalleryModalBtn');
    const doneGalleryBtn = document.getElementById('doneGalleryBtn');
    const galleryModalTitle = document.getElementById('galleryModalTitle');
    const galleryModalSubtitle = document.getElementById('galleryModalSubtitle');
    const galleryModalAlert = document.getElementById('galleryModalAlert');
    const galleryModalSuccess = document.getElementById('galleryModalSuccess');
    const galleryFileInput = document.getElementById('galleryFileInput');
    const chooseImagesBtn = document.getElementById('chooseImagesBtn');
    const startUploadBtn = document.getElementById('startUploadBtn');
    const selectedCountText = document.getElementById('selectedCountText');
    const selectedFilesSummary = document.getElementById('selectedFilesSummary');
    const galleryGridContainer = document.getElementById('galleryGridContainer');
    const galleryImageCount = document.getElementById('galleryImageCount');

    let activeGalleryProductId = 0;
    let selectedFilesToUpload = [];

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
        return '₱\u00A0' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

    // Integrated Product Photo Upload Handlers
    const prodPhotoPreviewBox = document.getElementById('prodPhotoPreviewBox');
    if (prodPhotoPreviewBox && prodPhotoInput) {
        prodPhotoPreviewBox.addEventListener('click', () => prodPhotoInput.click());
    }
    if (chooseProdPhotoBtn && prodPhotoInput) {
        chooseProdPhotoBtn.addEventListener('click', () => prodPhotoInput.click());
        prodPhotoInput.addEventListener('change', () => {
            if (prodPhotoInput.files && prodPhotoInput.files[0]) {
                const file = prodPhotoInput.files[0];
                if (file.size > 5 * 1024 * 1024) {
                    API.toast('Selected image exceeds the 5MB limit. Please choose a smaller photo.', 'error');
                    prodPhotoInput.value = '';
                    return;
                }
                selectedProdPhotoFile = file;
                if (prodPhotoNameText) prodPhotoNameText.textContent = file.name;
                if (removeProdPhotoBtn) removeProdPhotoBtn.style.display = 'inline-flex';

                const reader = new FileReader();
                reader.onload = (e) => {
                    if (prodPhotoPreviewImg) {
                        prodPhotoPreviewImg.src = e.target.result;
                        prodPhotoPreviewImg.style.display = 'block';
                    }
                    if (prodPhotoPlaceholder) prodPhotoPlaceholder.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (removeProdPhotoBtn) {
        removeProdPhotoBtn.addEventListener('click', () => {
            selectedProdPhotoFile = null;
            if (prodPhotoInput) prodPhotoInput.value = '';
            if (prodPhotoNameText) prodPhotoNameText.textContent = '';
            removeProdPhotoBtn.style.display = 'none';

            if (currentExistingPhotoUrl) {
                if (prodPhotoPreviewImg) {
                    prodPhotoPreviewImg.src = currentExistingPhotoUrl;
                    prodPhotoPreviewImg.style.display = 'block';
                }
                if (prodPhotoPlaceholder) prodPhotoPlaceholder.style.display = 'none';
            } else {
                if (prodPhotoPreviewImg) {
                    prodPhotoPreviewImg.src = '';
                    prodPhotoPreviewImg.style.display = 'none';
                }
                if (prodPhotoPlaceholder) prodPhotoPlaceholder.style.display = 'block';
            }
        });
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
                <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
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
                    <td colspan="6" style="text-align: center; padding: 40px; color: var(--color-danger);">
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
                    <td colspan="6" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
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
                const sell = parseFloat(p.selling_price);

                // Stock status tag styling matching design system
                let stockBadge = '';
                if (stock <= 0) {
                    stockBadge = '<span class="pos-product-stock-tag out-of-stock-tag">0 in stock (Out)</span>';
                } else if (stock <= threshold) {
                    stockBadge = `<span class="pos-product-stock-tag low-stock">⚠️ ${stock} in stock (Low)</span>`;
                } else {
                    stockBadge = `<span class="pos-product-stock-tag in-stock">${stock} in stock</span>`;
                }

                // Device Photo Thumbnail
                let photoHtml = '';
                if (p.primary_image) {
                    photoHtml = `<img src="../../${escapeHtml(p.primary_image)}" alt="${escapeHtml(p.name)}" style="width: 42px; height: 42px; object-fit: contain; border-radius: 6px; border: 1px solid var(--border-color); background: #f8fafc; padding: 2px;" onerror="this.outerHTML='<div style=\'width:42px;height:42px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:18px;\'>📱</div>'">`;
                } else {
                    photoHtml = `<div style="width: 42px; height: 42px; border-radius: 6px; border: 1px dashed var(--border-color); background: #f8fafc; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--text-muted);" title="No image uploaded">📱</div>`;
                }

                // Status tag if inactive
                const inactiveTag = p.status !== 'active' 
                    ? '<span class="badge badge-inactive" style="font-size: 10.5px; padding: 1px 6px;">Inactive</span>' 
                    : '';

                html += `
                    <tr class="product-row" data-id="${p.id}" style="cursor: pointer;" title="Click row to view / edit in drawer">
                        <td style="text-align: center; vertical-align: middle; padding: 8px;">
                            ${photoHtml}
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main); font-size: 14px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span>${escapeHtml(p.name)}</span>
                                ${inactiveTag}
                            </div>
                            <div style="font-family: monospace; font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                                SKU: <span style="color: var(--color-primary); font-weight: 500;">${escapeHtml(p.sku)}</span>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-outline">${escapeHtml(p.category_name || 'General')}</span>
                        </td>
                        <td class="text-right tabular-nums" style="font-weight: 600; font-size: 14px; white-space: nowrap;">
                            ${formatMoney(sell)}
                        </td>
                        <td>
                            ${stockBadge}
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                ${canManage ? `
                                    <button type="button" class="btn btn-outline btn-sm edit-product-btn" data-id="${p.id}" title="Edit / View in Drawer">
                                        <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><path d="M4 20h4L19 9l-4-4L4 16v4z"/></svg>Edit
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm delete-product-btn" 
                                            data-id="${p.id}" data-name="${escapeHtml(p.name)}" title="Delete Product">
                                        <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>Delete
                                    </button>
                                ` : `
                                    <button type="button" class="btn btn-outline btn-sm view-product-btn" data-id="${p.id}" title="View in Drawer">
                                        <svg viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; margin-right: 4px; vertical-align: -1px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View
                                    </button>
                                `}
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

            // Reset photo preview to clean state
            selectedProdPhotoFile = null;
            currentExistingPhotoUrl = null;
            if (prodPhotoInput) prodPhotoInput.value = '';
            if (prodPhotoNameText) prodPhotoNameText.textContent = '';
            if (removeProdPhotoBtn) removeProdPhotoBtn.style.display = 'none';
            if (prodPhotoPreviewImg) {
                prodPhotoPreviewImg.src = '';
                prodPhotoPreviewImg.style.display = 'none';
            }
            if (prodPhotoPlaceholder) prodPhotoPlaceholder.style.display = 'block';

            // Reset Apple attributes
            if (prodStorage) prodStorage.value = '';
            if (prodColor) prodColor.value = '';
            if (prodCondition) prodCondition.value = 'Brand New Sealed';
            if (prodWarranty) prodWarranty.value = '1 Year Official Apple Warranty';
            if (prodSpecs) prodSpecs.value = '';
            if (prodDescription) prodDescription.value = '';

            updateMarginPreview();
            openModal(addProductModal);
            setTimeout(() => prodName.focus(), 50);
        });
    }

    if (closeAddProductModalBtn) closeAddProductModalBtn.addEventListener('click', () => closeModal(addProductModal));
    if (cancelProductBtn) cancelProductBtn.addEventListener('click', () => closeModal(addProductModal));
    if (closeDeleteProductModalBtn) closeDeleteProductModalBtn.addEventListener('click', () => closeModal(deleteProductModal));
    if (cancelDeleteProdBtn) cancelDeleteProdBtn.addEventListener('click', () => closeModal(deleteProductModal));
    if (closeGalleryModalBtn) closeGalleryModalBtn.addEventListener('click', () => closeModal(productGalleryModal));
    if (doneGalleryBtn) doneGalleryBtn.addEventListener('click', () => closeModal(productGalleryModal));

    // Close when clicking overlay backdrop (only if mousedown also started on backdrop)
    let prodMouseDownTarget = null;
    document.addEventListener('mousedown', (e) => {
        prodMouseDownTarget = e.target;
    });
    [addProductModal, stockAdjustModal, deleteProductModal, productGalleryModal].forEach(modal => {
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
            closeModal(productGalleryModal);
        }
    });

    // Open Slide-Out Drawer Panel with Full Product Details
    async function openProductDrawer(id) {
        if (!canManage) return;
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

            // Photo preview setup
            selectedProdPhotoFile = null;
            if (prodPhotoInput) prodPhotoInput.value = '';
            if (prodPhotoNameText) prodPhotoNameText.textContent = '';
            if (removeProdPhotoBtn) removeProdPhotoBtn.style.display = 'none';

            if (p.primary_image) {
                currentExistingPhotoUrl = '../../' + p.primary_image;
                if (prodPhotoPreviewImg) {
                    prodPhotoPreviewImg.src = currentExistingPhotoUrl;
                    prodPhotoPreviewImg.style.display = 'block';
                }
                if (prodPhotoPlaceholder) prodPhotoPlaceholder.style.display = 'none';
            } else {
                currentExistingPhotoUrl = null;
                if (prodPhotoPreviewImg) {
                    prodPhotoPreviewImg.src = '';
                    prodPhotoPreviewImg.style.display = 'none';
                }
                if (prodPhotoPlaceholder) prodPhotoPlaceholder.style.display = 'block';
            }

            // Populate Apple device specs
            if (prodStorage) prodStorage.value = p.storage || '';
            if (prodColor) prodColor.value = p.color || '';
            if (prodCondition) prodCondition.value = p.condition_type || 'Brand New Sealed';
            if (prodWarranty) prodWarranty.value = p.warranty || '1 Year Official Apple Warranty';
            if (prodSpecs) prodSpecs.value = p.specs || '';
            if (prodDescription) prodDescription.value = p.description || '';

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
    }

    // Delegated Gallery, Edit, Row Click & Delete Clicks
    productsTable.addEventListener('click', async (e) => {
        // 1. Gallery Button Click
        const galleryBtn = e.target.closest('.gallery-product-btn');
        if (galleryBtn) {
            const id = parseInt(galleryBtn.dataset.id, 10);
            const sku = galleryBtn.dataset.sku;
            const name = galleryBtn.dataset.name;
            openGalleryModal(id, sku, name);
            return;
        }

        // 2. Delete Button Click
        const deleteBtn = e.target.closest('.delete-product-btn');
        if (deleteBtn) {
            pendingDeleteProductId = parseInt(deleteBtn.dataset.id, 10);
            deleteProdTargetName.textContent = `"${deleteBtn.dataset.name}"`;
            deleteProdAlert.style.display = 'none';
            openModal(deleteProductModal);
            return;
        }

        // 3. Edit / View Button Click or Row Click
        const editBtn = e.target.closest('.edit-product-btn') || e.target.closest('.view-product-btn');
        const row = e.target.closest('.product-row');
        const targetId = editBtn ? editBtn.dataset.id : (row && !e.target.closest('button') && !e.target.closest('a') ? row.dataset.id : null);

        if (targetId) {
            await openProductDrawer(targetId);
            return;
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

            const formData = new FormData();
            formData.append('action', action);
            formData.append('name', prodName.value.trim());
            formData.append('sku', prodSku.value.trim());
            formData.append('category_id', parseInt(prodCategory.value, 10) || 0);
            formData.append('cost_price', parseFloat(prodCostPrice.value) || 0);
            formData.append('selling_price', parseFloat(prodSellingPrice.value) || 0);
            formData.append('low_stock_threshold', parseInt(prodThreshold.value, 10) || 5);
            formData.append('status', prodStatus.value);
            formData.append('storage', prodStorage ? prodStorage.value.trim() : '');
            formData.append('color', prodColor ? prodColor.value.trim() : '');
            formData.append('condition_type', prodCondition ? prodCondition.value : 'Brand New Sealed');
            formData.append('warranty', prodWarranty ? prodWarranty.value.trim() : '1 Year Official Apple Warranty');
            formData.append('specs', prodSpecs ? prodSpecs.value.trim() : '');
            formData.append('description', prodDescription ? prodDescription.value.trim() : '');

            if (id > 0) {
                formData.append('id', id);
            } else {
                formData.append('current_stock', parseInt(prodStock.value, 10) || 0);
            }

            if (selectedProdPhotoFile) {
                formData.append('photo', selectedProdPhotoFile);
            }

            try {
                const res = await API.post('handler.php', formData);
                if (res && res.success) {
                    API.toast(res.message || 'Product saved successfully.', 'success');
                    closeModal(addProductModal);
                    loadProducts(currentPage);
                } else {
                    const msg = (res && res.message) ? res.message : 'Validation error occurred.';
                    prodModalAlert.textContent = msg;
                    prodModalAlert.style.display = 'block';
                }
            } catch (err) {
                console.error('Save product error:', err);
                prodModalAlert.textContent = 'Network or server error while saving product.';
                prodModalAlert.style.display = 'block';
            } finally {
                saveProductBtn.disabled = false;
                saveProductBtn.textContent = 'Save Product';
            }
        });
    }

    // -------------------------------------------------------------
    // Product Gallery Engine (Multi-Image Upload, Primary, Delete)
    // -------------------------------------------------------------
    async function openGalleryModal(productId, sku, name) {
        activeGalleryProductId = productId;
        galleryModalTitle.innerHTML = `<span>📸</span> Product Media: <span style="color: var(--color-primary);">${escapeHtml(name)}</span>`;
        galleryModalSubtitle.textContent = `[${sku}] — Manage high-resolution Apple storefront images`;
        galleryModalAlert.style.display = 'none';
        if (galleryModalSuccess) galleryModalSuccess.style.display = 'none';
        resetGalleryUploadForm();
        openModal(productGalleryModal);
        await loadGalleryImages(productId);
    }

    function resetGalleryUploadForm() {
        selectedFilesToUpload = [];
        if (galleryFileInput) galleryFileInput.value = '';
        if (selectedFilesSummary) {
            selectedFilesSummary.textContent = '';
            selectedFilesSummary.style.display = 'none';
        }
        if (startUploadBtn) {
            startUploadBtn.style.display = 'none';
            startUploadBtn.disabled = false;
        }
    }

    async function loadGalleryImages(productId) {
        galleryGridContainer.innerHTML = `
            <div style="grid-column: 1 / -1; text-align: center; padding: 28px; color: var(--text-muted);">
                <div style="display: inline-block; width: 22px; height: 22px; border: 2px solid var(--border-color); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px;"></div>
                Loading images...
            </div>`;

        try {
            const res = await API.get(`handler.php?action=list_product_images&product_id=${productId}`);
            if (res && res.success && Array.isArray(res.data)) {
                renderGalleryGrid(res.data);
            } else {
                galleryGridContainer.innerHTML = `
                    <div style="grid-column: 1 / -1; text-align: center; padding: 24px; color: var(--color-danger);">
                        Failed to retrieve gallery photos.
                    </div>`;
            }
        } catch (err) {
            console.error('Gallery load error:', err);
            galleryGridContainer.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 24px; color: var(--color-danger);">
                    Network error while fetching gallery photos.
                </div>`;
        }
    }

    function renderGalleryGrid(images) {
        galleryImageCount.textContent = images.length;
        if (images.length === 0) {
            galleryGridContainer.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 36px 16px; color: var(--text-muted); background: #fff; border: 1px dashed var(--border-color); border-radius: var(--radius-md);">
                    <div style="font-size: 34px; margin-bottom: 6px;">📷</div>
                    <div style="font-weight: 600; font-size: 14px; color: var(--text-main);">No Images Uploaded</div>
                    <p style="font-size: 12px; margin: 4px 0 0 0;">Select photos above to showcase this device in the storefront.</p>
                </div>`;
            return;
        }

        let html = '';
        images.forEach(img => {
            const isPrimary = parseInt(img.is_primary, 10) === 1;
            html += `
                <div class="gallery-card" style="border: 2px solid ${isPrimary ? 'var(--color-primary)' : 'var(--border-color)'}; border-radius: var(--radius-md); overflow: hidden; background: #fff; display: flex; flex-direction: column; position: relative; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="position: relative; width: 100%; height: 115px; background: #f8fafc; overflow: hidden; display: flex; align-items: center; justify-content: center; padding: 4px;">
                        <img src="../../${escapeHtml(img.image_path)}" alt="Product Image" style="width: 100%; height: 100%; object-fit: contain;">
                        ${isPrimary ? `
                            <span style="position: absolute; top: 6px; left: 6px; background: var(--color-primary); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.3);">
                                ⭐ PRIMARY
                            </span>
                        ` : ''}
                    </div>
                    <div style="padding: 8px; display: flex; flex-direction: column; gap: 6px; flex: 1; justify-content: space-between; background: #fff; border-top: 1px solid var(--border-color);">
                        ${canManage ? `
                            <div style="display: flex; gap: 4px;">
                                ${!isPrimary ? `
                                    <button type="button" class="btn btn-outline btn-sm set-primary-img-btn" data-id="${img.id}" style="flex: 1; padding: 3px 6px; font-size: 10.5px;" title="Set as primary catalog photo">
                                        ⭐ Primary
                                    </button>
                                ` : `
                                    <span style="flex: 1; text-align: center; font-size: 10.5px; color: var(--color-primary); font-weight: 600; padding: 3px 0;">
                                        Catalog Main
                                    </span>
                                `}
                                <button type="button" class="btn btn-danger btn-sm delete-img-btn" data-id="${img.id}" style="padding: 3px 6px; font-size: 10.5px;" title="Delete image">
                                    🗑️
                                </button>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });
        galleryGridContainer.innerHTML = html;
    }

    // Image Upload Controls
    if (chooseImagesBtn && galleryFileInput) {
        chooseImagesBtn.addEventListener('click', () => galleryFileInput.click());

        galleryFileInput.addEventListener('change', () => {
            const files = Array.from(galleryFileInput.files);
            selectedFilesToUpload = files;
            if (files.length > 0) {
                selectedCountText.textContent = files.length;
                selectedFilesSummary.textContent = `Selected ${files.length} photo(s): ` + files.map(f => f.name).join(', ');
                selectedFilesSummary.style.display = 'block';
                startUploadBtn.style.display = 'inline-flex';
            } else {
                selectedFilesSummary.style.display = 'none';
                startUploadBtn.style.display = 'none';
            }
        });
    }

    if (startUploadBtn) {
        startUploadBtn.addEventListener('click', async () => {
            if (!activeGalleryProductId || selectedFilesToUpload.length === 0) return;

            galleryModalAlert.style.display = 'none';

            startUploadBtn.disabled = true;
            startUploadBtn.textContent = 'Uploading...';

            const formData = new FormData();
            formData.append('action', 'upload_product_images');
            formData.append('product_id', activeGalleryProductId);

            selectedFilesToUpload.forEach(file => {
                formData.append('images[]', file);
            });

            try {
                const res = await API.post('handler.php', formData);
                if (res && res.success) {
                    API.toast(res.message || 'Photos uploaded successfully!', 'success');
                    resetGalleryUploadForm();
                    await loadGalleryImages(activeGalleryProductId);
                    loadProducts(currentPage); // update thumbnail and count in table
                } else {
                    galleryModalAlert.textContent = (res && res.message) ? res.message : 'Photo upload failed.';
                    galleryModalAlert.style.display = 'block';
                }
            } catch (err) {
                console.error('Upload error:', err);
                galleryModalAlert.textContent = 'Network or server error during image upload.';
                galleryModalAlert.style.display = 'block';
            } finally {
                startUploadBtn.disabled = false;
                startUploadBtn.textContent = `⬆️ Upload Selected (${selectedFilesToUpload.length})`;
            }
        });
    }

    // Grid Actions: Set Primary & Delete Image
    if (galleryGridContainer) {
        galleryGridContainer.addEventListener('click', async (e) => {
            const primaryBtn = e.target.closest('.set-primary-img-btn');
            if (primaryBtn) {
                const imgId = parseInt(primaryBtn.dataset.id, 10);
                primaryBtn.disabled = true;
                primaryBtn.textContent = '...';

                const res = await API.post('handler.php', {
                    action: 'set_primary_image',
                    product_id: activeGalleryProductId,
                    image_id: imgId
                });

                if (res && res.success) {
                    API.toast('Primary thumbnail updated.', 'success');
                    await loadGalleryImages(activeGalleryProductId);
                    loadProducts(currentPage);
                } else {
                    API.toast(res && res.message ? res.message : 'Failed to set primary image.', 'error');
                    primaryBtn.disabled = false;
                    primaryBtn.textContent = '⭐ Primary';
                }
                return;
            }

            const deleteImgBtn = e.target.closest('.delete-img-btn');
            if (deleteImgBtn) {
                const imgId = parseInt(deleteImgBtn.dataset.id, 10);
                if (!confirm('Are you sure you want to permanently delete this photo?')) return;

                deleteImgBtn.disabled = true;
                const res = await API.post('handler.php', {
                    action: 'delete_product_image',
                    product_id: activeGalleryProductId,
                    image_id: imgId
                });

                if (res && res.success) {
                    API.toast('Photo removed successfully.', 'success');
                    await loadGalleryImages(activeGalleryProductId);
                    loadProducts(currentPage);
                } else {
                    API.toast(res && res.message ? res.message : 'Failed to delete photo.', 'error');
                    deleteImgBtn.disabled = false;
                }
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
