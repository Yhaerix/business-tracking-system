<?php
/**
 * Product Categories Management View
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'Product Categories';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('products_view');

$canManage = has_permission('products_manage');
$db = get_db();

// Pre-fetch categories for initial fast rendering
$stmt = $db->query("
    SELECT c.id, c.name, c.description, c.created_at,
           COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id AND p.deleted_at IS NULL
    GROUP BY c.id
    ORDER BY c.name ASC
");
$categories = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
            <a href="<?= BASE_URL ?>/modules/products/" style="color: var(--color-primary); text-decoration: none; font-size: 13px; font-weight: 500;">
                &larr; Back to Products
            </a>
        </div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">Product Categories</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            Classify and organize catalog items into structured inventory groups.
        </p>
    </div>

    <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" id="openAddCategoryBtn">
            <span>+</span> New Category
        </button>
    <?php endif; ?>
</div>

<!-- Category List Table Container -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" id="categoriesTable">
            <thead>
                <tr>
                    <th style="width: 70px;">ID</th>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th style="width: 150px;">Assigned Items</th>
                    <th style="width: 160px;">Created Date</th>
                    <?php if ($canManage): ?>
                        <th style="width: 140px; text-align: right;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="categoryTableBody">
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="<?= $canManage ? '6' : '5' ?>" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                            No categories found. Click "New Category" to create your first catalog group.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr id="cat-row-<?= (int)$cat['id'] ?>">
                            <td class="tabular-nums" style="color: var(--text-muted);">#<?= (int)$cat['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ((int)$cat['id'] === 1): ?>
                                    <span class="badge badge-confirmed" style="margin-left: 6px; font-size: 10px;">Default</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-muted);">
                                <?= !empty($cat['description']) ? htmlspecialchars($cat['description'], ENT_QUOTES, 'UTF-8') : '<em style="color:#94a3b8;">None provided</em>' ?>
                            </td>
                            <td>
                                <span class="badge <?= (int)$cat['product_count'] > 0 ? 'badge-confirmed' : 'badge-inactive' ?>">
                                    <?= (int)$cat['product_count'] ?> item<?= (int)$cat['product_count'] === 1 ? '' : 's' ?>
                                </span>
                            </td>
                            <td style="font-size: 13px; color: var(--text-muted);">
                                <?= date('M d, Y', strtotime($cat['created_at'])) ?>
                            </td>
                            <?php if ($canManage): ?>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn btn-outline btn-sm edit-cat-btn" 
                                            data-id="<?= (int)$cat['id'] ?>"
                                            data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-description="<?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        Edit
                                    </button>
                                    
                                    <?php if ((int)$cat['id'] !== 1): ?>
                                        <button type="button" class="btn btn-outline btn-sm delete-cat-btn"
                                                style="color: var(--color-danger); border-color: var(--color-danger-border);"
                                                data-id="<?= (int)$cat['id'] ?>"
                                                data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-count="<?= (int)$cat['product_count'] ?>">
                                            Delete
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Add / Edit Category Modal Dialog -->
<div class="modal-overlay" id="categoryModal" role="dialog" aria-modal="true" aria-labelledby="catModalTitle">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title" id="catModalTitle">New Category</h3>
            <button type="button" class="modal-close" id="closeCategoryModal">&times;</button>
        </div>
        <form id="categoryForm" autocomplete="off">
            <input type="hidden" name="id" id="catId" value="0">
            <div class="modal-body">
                <div id="catModalAlert" style="display: none;" class="alert alert-danger"></div>

                <div class="form-group">
                    <label class="form-label" for="catName">Category Name <span style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="catName" name="name" required maxlength="100" placeholder="e.g. Beverages, Dry Goods, Hardware">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="catDescription">Description (Optional)</label>
                    <textarea class="form-textarea" id="catDescription" name="description" rows="3" placeholder="Brief note regarding items included in this category..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelCatBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveCatBtn">Save Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal Dialog -->
<div class="modal-overlay" id="deleteCategoryModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-card" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title" id="deleteModalTitle" style="color: var(--color-danger);">Delete Category</h3>
            <button type="button" class="modal-close" id="closeDeleteCatModal">&times;</button>
        </div>
        <div class="modal-body">
            <div id="deleteModalAlert" style="display: none;" class="alert alert-danger"></div>
            <p style="font-size: 14px; color: var(--text-main); margin-bottom: 12px;">
                Are you sure you want to delete category <strong id="deleteCatTargetName"></strong>?
            </p>
            <p style="font-size: 12.5px; color: var(--text-muted); margin: 0;" id="deleteCatNotice">
                This action is irreversible. Categories with active products assigned cannot be deleted.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="cancelDeleteCatBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteCatBtn">Confirm Delete</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canManage = <?= $canManage ? 'true' : 'false' ?>;
    if (!canManage) return;

    // Elements
    const categoryModal = document.getElementById('categoryModal');
    const deleteCategoryModal = document.getElementById('deleteCategoryModal');
    const openAddCategoryBtn = document.getElementById('openAddCategoryBtn');
    const closeCategoryModal = document.getElementById('closeCategoryModal');
    const cancelCatBtn = document.getElementById('cancelCatBtn');
    const categoryForm = document.getElementById('categoryForm');
    const saveCatBtn = document.getElementById('saveCatBtn');
    const catModalTitle = document.getElementById('catModalTitle');
    const catIdInput = document.getElementById('catId');
    const catNameInput = document.getElementById('catName');
    const catDescInput = document.getElementById('catDescription');
    const catModalAlert = document.getElementById('catModalAlert');

    const closeDeleteCatModal = document.getElementById('closeDeleteCatModal');
    const cancelDeleteCatBtn = document.getElementById('cancelDeleteCatBtn');
    const confirmDeleteCatBtn = document.getElementById('confirmDeleteCatBtn');
    const deleteCatTargetName = document.getElementById('deleteCatTargetName');
    const deleteModalAlert = document.getElementById('deleteModalAlert');
    let pendingDeleteId = 0;

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

    // Modal Control Functions
    function openModal(modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Add Category Trigger
    if (openAddCategoryBtn) {
        openAddCategoryBtn.addEventListener('click', () => {
            catModalTitle.textContent = 'New Category';
            catIdInput.value = '0';
            categoryForm.reset();
            catModalAlert.style.display = 'none';
            openModal(categoryModal);
            setTimeout(() => catNameInput.focus(), 50);
        });
    }

    // Close Add/Edit Modal
    closeCategoryModal.addEventListener('click', () => closeModal(categoryModal));
    cancelCatBtn.addEventListener('click', () => closeModal(categoryModal));

    // Close Delete Modal
    closeDeleteCatModal.addEventListener('click', () => closeModal(deleteCategoryModal));
    cancelDeleteCatBtn.addEventListener('click', () => closeModal(deleteCategoryModal));

    // Global ESC Key Handler
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal(categoryModal);
            closeModal(deleteCategoryModal);
        }
    });

    // Delegated Edit Button Click
    document.getElementById('categoriesTable').addEventListener('click', (e) => {
        const editBtn = e.target.closest('.edit-cat-btn');
        if (editBtn) {
            const id = editBtn.dataset.id;
            const name = editBtn.dataset.name;
            const desc = editBtn.dataset.description;

            catModalTitle.textContent = 'Edit Category';
            catIdInput.value = id;
            catNameInput.value = name;
            catDescInput.value = desc;
            catModalAlert.style.display = 'none';
            openModal(categoryModal);
            setTimeout(() => catNameInput.focus(), 50);
            return;
        }

        const deleteBtn = e.target.closest('.delete-cat-btn');
        if (deleteBtn) {
            const id = parseInt(deleteBtn.dataset.id, 10);
            const name = deleteBtn.dataset.name;
            const count = parseInt(deleteBtn.dataset.count, 10);

            if (count > 0) {
                API.toast(`Cannot delete "${name}": ${count} active product(s) are assigned. Please reassign them first.`, 'error');
                return;
            }

            pendingDeleteId = id;
            deleteCatTargetName.textContent = `"${name}"`;
            deleteModalAlert.style.display = 'none';
            openModal(deleteCategoryModal);
        }
    });

    // Category Form Submission (Create / Update)
    categoryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        catModalAlert.style.display = 'none';

        const id = parseInt(catIdInput.value, 10);
        const name = catNameInput.value.trim();
        const description = catDescInput.value.trim();

        if (!name) {
            catModalAlert.textContent = 'Please enter a category name.';
            catModalAlert.style.display = 'block';
            return;
        }

        saveCatBtn.disabled = true;
        saveCatBtn.textContent = 'Saving...';

        const action = id > 0 ? 'update_category' : 'create_category';
        const payload = { action, name, description };
        if (id > 0) payload.id = id;

        const res = await API.post('handler.php', payload);
        saveCatBtn.disabled = false;
        saveCatBtn.textContent = 'Save Category';

        if (res && res.success) {
            API.toast(res.message || 'Category saved successfully.', 'success');
            closeModal(categoryModal);
            reloadCategories();
        } else {
            const msg = (res && res.message) ? res.message : 'Unable to save category. Please try again.';
            catModalAlert.textContent = msg;
            catModalAlert.style.display = 'block';
        }
    });

    // Confirm Deletion Execution
    confirmDeleteCatBtn.addEventListener('click', async () => {
        if (!pendingDeleteId) return;

        confirmDeleteCatBtn.disabled = true;
        confirmDeleteCatBtn.textContent = 'Deleting...';

        const res = await API.post('handler.php', {
            action: 'delete_category',
            id: pendingDeleteId
        });

        confirmDeleteCatBtn.disabled = false;
        confirmDeleteCatBtn.textContent = 'Confirm Delete';

        if (res && res.success) {
            API.toast('Category deleted successfully.', 'success');
            closeModal(deleteCategoryModal);
            pendingDeleteId = 0;
            reloadCategories();
        } else {
            const msg = (res && res.message) ? res.message : 'Failed to delete category.';
            deleteModalAlert.textContent = msg;
            deleteModalAlert.style.display = 'block';
        }
    });

    // Dynamic Table Reload Function
    async function reloadCategories() {
        const res = await API.get('handler.php?action=list_categories');
        if (!res || !res.success || !Array.isArray(res.data)) return;

        const tbody = document.getElementById('categoryTableBody');
        if (res.data.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        No categories found. Click "New Category" to create your first catalog group.
                    </td>
                </tr>`;
            return;
        }

        let html = '';
        res.data.forEach(cat => {
            const id = parseInt(cat.id, 10);
            const count = parseInt(cat.product_count, 10);
            const isDefault = id === 1;
            const dateStr = cat.created_at ? new Date(cat.created_at).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) : '—';

            html += `
                <tr id="cat-row-${id}">
                    <td class="tabular-nums" style="color: var(--text-muted);">#${id}</td>
                    <td>
                        <strong>${escapeHtml(cat.name)}</strong>
                        ${isDefault ? '<span class="badge badge-confirmed" style="margin-left: 6px; font-size: 10px;">Default</span>' : ''}
                    </td>
                    <td style="color: var(--text-muted);">${cat.description ? escapeHtml(cat.description) : '<em style="color:#94a3b8;">None provided</em>'}</td>
                    <td>
                        <span class="badge ${count > 0 ? 'badge-confirmed' : 'badge-inactive'}">
                            ${count} item${count === 1 ? '' : 's'}
                        </span>
                    </td>
                    <td style="font-size: 13px; color: var(--text-muted);">${dateStr}</td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn btn-outline btn-sm edit-cat-btn"
                                data-id="${id}"
                                data-name="${escapeHtml(cat.name)}"
                                data-description="${escapeHtml(cat.description || '')}">
                            Edit
                        </button>
                        ${!isDefault ? `
                            <button type="button" class="btn btn-outline btn-sm delete-cat-btn"
                                    style="color: var(--color-danger); border-color: var(--color-danger-border);"
                                    data-id="${id}"
                                    data-name="${escapeHtml(cat.name)}"
                                    data-count="${count}">
                                Delete
                            </button>
                        ` : ''}
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

