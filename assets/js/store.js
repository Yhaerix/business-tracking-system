/**
 * Public Customer Storefront & Cart Engine
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 *
 * Capabilities:
 * - Persistent Shopping Bag state across all pages via browser storage + session syncing
 * - Interactive slide-out cart drawer with free shipping progress bar & +/- quantity steppers
 * - Instant "Add to Bag" and "Buy Now" variant resolution
 * - Real-time calculations of subtotal, shipping fee, and grand total
 * - Prestige Apple Coins top-up modal and balance management
 */

const StoreApp = {
    state: {
        categoryId: 'all',
        categoryName: 'All Apple Devices',
        searchQuery: '',
        condition: 'all',
        sort: 'popular',
        page: 1,
        limit: 16,
        categories: [],
        products: [],
        flashDeals: [],
        countdownSeconds: 64800,
        customerCoins: 0.00,
        coinsRate: 1.00,
        coinsName: 'Prestige Coins',
        currencySymbol: '₱',
        freeShippingThreshold: 5000.00
    },

    carousel: {
        currentIndex: 0,
        totalSlides: 3,
        timer: null
    }
};

/**
 * =========================================================================
 * 1. CART STORAGE & ENGINE METHODS
 * =========================================================================
 */
function getCart() {
    try {
        const stored = localStorage.getItem('prestige_apple_cart');
        return stored ? JSON.parse(stored) : [];
    } catch (e) {
        return [];
    }
}

function saveCart(cart) {
    try {
        localStorage.setItem('prestige_apple_cart', JSON.stringify(cart));
    } catch (e) {}
    updateHeaderCartBadge();
}

function updateHeaderCartBadge() {
    const cart = getCart();
    const totalQty = cart.reduce((acc, item) => acc + (parseInt(item.quantity, 10) || 1), 0);
    
    const badge = document.getElementById('headerCartCount');
    const drawerBadge = document.getElementById('drawerCount');
    if (badge) badge.textContent = totalQty;
    if (drawerBadge) drawerBadge.textContent = totalQty;
}

/**
 * Add Item to Bag with Live Stock Guard & Toast Feedback
 */
async function addToBag(productId, name, price, image, color = 'Standard', storage = 'Default', qty = 1, maxStock = null) {
    const cart = getCart();
    const itemKey = `${productId}_${color}_${storage}`;
    const existingIndex = cart.findIndex(i => i.key === itemKey);
    const existingQty = existingIndex > -1 ? (parseInt(cart[existingIndex].quantity, 10) || 0) : 0;
    
    // Resolve effective maximum stock limit
    let effectiveMax = 999;
    if (maxStock !== null && maxStock !== undefined && !isNaN(maxStock)) {
        effectiveMax = parseInt(maxStock, 10);
    } else if (existingIndex > -1 && cart[existingIndex].max_stock) {
        effectiveMax = parseInt(cart[existingIndex].max_stock, 10);
    } else {
        const found = StoreApp.state.products.find(p => p.id === productId);
        if (found && found.current_stock !== undefined) {
            effectiveMax = parseInt(found.current_stock, 10);
        }
    }

    if (effectiveMax > 0 && (existingQty + qty) > effectiveMax) {
        const remaining = Math.max(0, effectiveMax - existingQty);
        if (remaining <= 0) {
            showToast(`Cannot add more. All ${effectiveMax} available unit(s) of "${name}" are already in your bag.`, 'error');
        } else {
            showToast(`Cannot add ${qty} more. Only ${remaining} additional unit(s) available in stock.`, 'error');
        }
        return;
    }

    if (existingIndex > -1) {
        cart[existingIndex].quantity += qty;
        cart[existingIndex].max_stock = effectiveMax;
    } else {
        cart.push({
            key: itemKey,
            id: productId,
            name: name,
            price: parseFloat(price),
            image: image,
            color: color,
            storage: storage,
            quantity: qty,
            max_stock: effectiveMax
        });
    }

    saveCart(cart);
    renderCartDrawer();
    openCartDrawer();
    showToast(`Added ${qty}x "${name}" to your shopping bag!`, 'success');

    // Asynchronously synchronize session bag in backend
    try {
        const formData = new FormData();
        formData.append('action', 'add_to_cart');
        formData.append('item_key', itemKey);
        formData.append('product_id', productId);
        formData.append('quantity', qty);
        formData.append('storage', storage);
        formData.append('color', color);
        const res = await fetch('handler.php', { method: 'POST', body: formData });
        const json = await res.json();
        if (json && !json.success && json.message) {
            showToast(json.message, 'error');
        }
    } catch (e) {}
}

/**
 * Drawer Quantity Stepper +/- with Live Stock Boundary Validation
 */
async function updateCartDrawerQty(key, delta) {
    const cart = getCart();
    const itemIndex = cart.findIndex(i => i.key === key);
    if (itemIndex === -1) return;

    const item = cart[itemIndex];
    const currentQty = parseInt(item.quantity, 10) || 1;

    // Resolve max_stock if missing on legacy cached cart item
    let maxStock = parseInt(item.max_stock, 10);
    if (isNaN(maxStock) || maxStock <= 0) {
        const found = StoreApp.state.products.find(p => p.id === item.id);
        if (found && found.current_stock !== undefined) {
            maxStock = parseInt(found.current_stock, 10);
            item.max_stock = maxStock;
        } else {
            try {
                const res = await fetch(`handler.php?action=get_product&id=${item.id}`);
                const json = await res.json();
                if (json && json.success && json.data && json.data.product) {
                    maxStock = parseInt(json.data.product.current_stock, 10);
                    item.max_stock = maxStock;
                    saveCart(cart);
                }
            } catch (e) {}
        }
    }

    // Live Stock Overflow Prevention Notification
    if (delta > 0 && !isNaN(maxStock) && maxStock > 0 && currentQty >= maxStock) {
        showToast(`Cannot add more. Only ${maxStock} unit(s) available in stock for "${item.name}".`, 'error');
        return;
    }

    const newQty = currentQty + delta;
    if (newQty <= 0) {
        cart.splice(itemIndex, 1);
        showToast('Item removed from shopping bag.', 'info');
    } else {
        item.quantity = newQty;
    }

    saveCart(cart);
    renderCartDrawer();
    if (typeof renderCartPage === 'function') {
        renderCartPage();
    }

    // Synchronize with backend API and handle validation feedback
    try {
        const formData = new FormData();
        formData.append('action', 'update_cart_qty');
        formData.append('item_key', key);
        formData.append('quantity', newQty);
        if (item.id) formData.append('product_id', item.id);
        if (item.storage) formData.append('storage', item.storage);
        if (item.color) formData.append('color', item.color);
        const res = await fetch('handler.php', { method: 'POST', body: formData });
        const json = await res.json();
        if (json && !json.success) {
            // Revert local quantity if backend rejected due to stock limit
            item.quantity = currentQty;
            saveCart(cart);
            renderCartDrawer();
            if (typeof renderCartPage === 'function') {
                renderCartPage();
            }
            showToast(json.message || `Maximum available stock reached (${maxStock} units).`, 'error');
        }
    } catch (e) {}
}

/**
 * Remove Item Directly
 */
function removeCartDrawerItem(key) {
    const cart = getCart();
    const updated = cart.filter(i => i.key !== key);
    saveCart(updated);
    renderCartDrawer();
    if (typeof renderCartPage === 'function') {
        renderCartPage();
    }
    showToast('Item removed from bag.', 'info');
    try {
        const formData = new FormData();
        formData.append('action', 'remove_from_cart');
        formData.append('item_key', key);
        fetch('handler.php', { method: 'POST', body: formData });
    } catch (e) {}
}

/**
 * Render Slide-Out Cart Drawer
 */
function renderCartDrawer() {
    const listEl = document.getElementById('drawerItemsList');
    if (!listEl) return;

    const cart = getCart();
    let subtotal = 0;

    if (!cart.length) {
        listEl.innerHTML = `
            <div class="drawer-empty-state">
                <div class="drawer-empty-icon">🛍️</div>
                <h3 style="font-size:16px; font-weight:800; color:var(--apple-dark);">Your Bag is Empty</h3>
                <p style="font-size:12px; margin-top:4px;">Discover the newest iPhone, Mac, and Apple Watch models.</p>
                <button type="button" class="btn-apple-primary" onclick="closeCartDrawer(); window.location.href='index.php';" style="margin-top:16px; font-size:13px; padding:8px 18px;">
                    Start Shopping
                </button>
            </div>
        `;
        document.getElementById('drawerSubtotal').textContent = '₱0.00';
        document.getElementById('drawerTotal').textContent = '₱0.00';
        updateShippingProgressBar(0);
        return;
    }

    let html = '';
    cart.forEach(item => {
        const itemSubtotal = item.price * item.quantity;
        subtotal += itemSubtotal;
        const imgPath = item.image.startsWith('../') ? item.image : `../${item.image}`;

        html += `
            <div class="cart-item-card">
                <div class="cart-item-thumb">
                    <img src="${imgPath}" alt="${escapeHtml(item.name)}" onerror="this.src='../assets/images/logo.png'">
                </div>
                <div class="cart-item-details">
                    <div class="cart-item-name">${escapeHtml(item.name)}</div>
                    <div class="cart-item-specs">${escapeHtml(item.storage)} &bull; ${escapeHtml(item.color)}</div>
                    <div class="cart-item-price-stepper">
                        <span class="cart-item-price">${StoreApp.state.currencySymbol}${Number(itemSubtotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        <div class="drawer-stepper">
                            <button type="button" class="drawer-step-btn" onclick="updateCartDrawerQty('${item.key}', -1)">&minus;</button>
                            <span class="drawer-step-qty">${item.quantity}</span>
                            <button type="button" class="drawer-step-btn" onclick="updateCartDrawerQty('${item.key}', 1)">&plus;</button>
                        </div>
                    </div>
                </div>
                <button type="button" class="cart-item-remove-btn" onclick="removeCartDrawerItem('${item.key}')" title="Remove">&times;</button>
            </div>
        `;
    });

    listEl.innerHTML = html;

    const formattedSubtotal = StoreApp.state.currencySymbol + Number(subtotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('drawerSubtotal').textContent = formattedSubtotal;
    document.getElementById('drawerTotal').textContent = formattedSubtotal;

    updateShippingProgressBar(subtotal);
}

/**
 * Free Shipping Progress Tracker Calculator
 */
function updateShippingProgressBar(subtotal) {
    const threshold = StoreApp.state.freeShippingThreshold;
    const msgEl = document.getElementById('drawerShippingMsg');
    const fillEl = document.getElementById('drawerShippingFill');

    const pct = Math.min(100, Math.round((subtotal / threshold) * 100));

    if (fillEl) fillEl.style.width = `${pct}%`;

    if (subtotal >= threshold) {
        if (msgEl) msgEl.innerHTML = '<span>🎉</span> <strong>You have unlocked FREE Insured Shipping!</strong>';
    } else {
        const remaining = threshold - subtotal;
        const formattedRem = StoreApp.state.currencySymbol + Number(remaining).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (msgEl) msgEl.innerHTML = `<span>🚚</span> Add <strong>${formattedRem}</strong> more for Free Shipping`;
    }

    // Sync full cart page if open
    const pageMsg = document.getElementById('cartPageShippingMsg');
    const pageFill = document.getElementById('cartPageShippingFill');
    if (pageFill) pageFill.style.width = `${pct}%`;
    if (pageMsg) {
        pageMsg.innerHTML = (subtotal >= threshold) 
            ? '<span>🎉</span> <strong>You have unlocked FREE Insured Express Delivery!</strong>' 
            : `<span>🚚</span> Add <strong>${StoreApp.state.currencySymbol + Number(threshold - subtotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong> to qualify for Free Delivery`;
    }
}

/**
 * Open & Close Slide-Out Drawer
 */
function openCartDrawer() {
    renderCartDrawer();
    const overlay = document.getElementById('cartDrawerOverlay');
    if (overlay) overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeCartDrawer(e) {
    if (e && e.target && e.target.id !== 'cartDrawerOverlay' && !e.target.classList.contains('drawer-close-btn')) {
        return;
    }
    const overlay = document.getElementById('cartDrawerOverlay');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

async function goToCheckout() {
    const cart = getCart();
    if (!cart.length) {
        showToast('Your shopping bag is empty!', 'error');
        return;
    }
    const isAuth = window.STORE_CUSTOMER && window.STORE_CUSTOMER.is_authenticated;
    if (!isAuth) {
        showToast('Please sign in or create an account to proceed with checkout.', 'info');
        setTimeout(() => {
            window.location.href = 'login.php?redirect=checkout.php&reason=auth_required';
        }, 400);
        return;
    }

    // Synchronize local shopping bag with server session before navigation
    try {
        await fetch('handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'sync_cart', items: cart })
        });
    } catch (e) {}

    window.location.href = 'checkout.php';
}

/**
 * =========================================================================
 * 2. FULL CART PAGE RENDER (`store/cart.php`)
 * =========================================================================
 */
function renderCartPage() {
    const listEl = document.getElementById('cartPageItemsList');
    const emptyState = document.getElementById('cartPageEmptyState');
    if (!listEl) return;

    const cart = getCart();
    let subtotal = 0;

    if (!cart.length) {
        listEl.style.display = 'none';
        if (emptyState) emptyState.style.display = 'block';
        document.getElementById('cartPageSubtotal').textContent = '₱0.00';
        document.getElementById('cartPageTotal').textContent = '₱0.00';
        updateShippingProgressBar(0);
        return;
    }

    listEl.style.display = 'block';
    if (emptyState) emptyState.style.display = 'none';

    let html = '';
    cart.forEach(item => {
        const itemTotal = item.price * item.quantity;
        subtotal += itemTotal;
        const imgPath = item.image.startsWith('../') ? item.image : `../${item.image}`;

        html += `
            <div class="cart-item-card" style="padding:16px 0;">
                <div class="cart-item-thumb" style="width:84px; height:84px;">
                    <img src="${imgPath}" alt="${escapeHtml(item.name)}" onerror="this.src='../assets/images/logo.png'">
                </div>
                <div class="cart-item-details">
                    <div class="cart-item-name" style="font-size:15px;">${escapeHtml(item.name)}</div>
                    <div class="cart-item-specs">${escapeHtml(item.storage)} &bull; ${escapeHtml(item.color)} &bull; 1-Year Official Apple Warranty</div>
                    <div class="cart-item-price-stepper" style="margin-top:12px;">
                        <span class="cart-item-price" style="font-size:17px;">${StoreApp.state.currencySymbol}${Number(itemTotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        <div class="drawer-stepper" style="height:32px;">
                            <button type="button" class="drawer-step-btn" style="width:32px;" onclick="updateCartDrawerQty('${item.key}', -1)">&minus;</button>
                            <span class="drawer-step-qty" style="width:32px;">${item.quantity}</span>
                            <button type="button" class="drawer-step-btn" style="width:32px;" onclick="updateCartDrawerQty('${item.key}', 1)">&plus;</button>
                        </div>
                    </div>
                </div>
                <button type="button" class="cart-item-remove-btn" onclick="removeCartDrawerItem('${item.key}')" title="Remove Item">&times;</button>
            </div>
        `;
    });

    listEl.innerHTML = html;

    const formattedSub = StoreApp.state.currencySymbol + Number(subtotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('cartPageSubtotal').textContent = formattedSub;
    document.getElementById('cartPageTotal').textContent = formattedSub;

    updateShippingProgressBar(subtotal);
}

/**
 * =========================================================================
 * 3. HERO CAROUSEL CONTROLLER
 * =========================================================================
 */
function initHeroCarousel() {
    startCarouselAutoPlay();
    const carouselEl = document.getElementById('heroCarousel');
    if (carouselEl) {
        carouselEl.addEventListener('mouseenter', () => clearInterval(StoreApp.carousel.timer));
        carouselEl.addEventListener('mouseleave', () => startCarouselAutoPlay());
    }
}

function startCarouselAutoPlay() {
    clearInterval(StoreApp.carousel.timer);
    StoreApp.carousel.timer = setInterval(() => {
        nextHeroSlide();
    }, 5500);
}

function updateHeroCarousel() {
    const slides = document.getElementById('carouselSlides');
    const dots = document.querySelectorAll('.carousel-dot');
    if (slides) {
        slides.style.transform = `translateX(-${StoreApp.carousel.currentIndex * 100}%)`;
    }
    dots.forEach((dot, idx) => {
        dot.classList.toggle('active', idx === StoreApp.carousel.currentIndex);
    });
}

function nextHeroSlide() {
    StoreApp.carousel.currentIndex = (StoreApp.carousel.currentIndex + 1) % StoreApp.carousel.totalSlides;
    updateHeroCarousel();
}

function prevHeroSlide() {
    StoreApp.carousel.currentIndex = (StoreApp.carousel.currentIndex - 1 + StoreApp.carousel.totalSlides) % StoreApp.carousel.totalSlides;
    updateHeroCarousel();
}

function goToHeroSlide(index) {
    StoreApp.carousel.currentIndex = index;
    updateHeroCarousel();
}

/**
 * =========================================================================
 * 4. STORE BRANDING & PRESTIGE COINS INFO
 * =========================================================================
 */
async function loadStoreInfo() {
    try {
        const res = await fetch('handler.php?action=get_store_info');
        const data = await res.json();
        if (data && data.success && data.data) {
            StoreApp.state.customerCoins = parseFloat(data.data.customer_coins) || 0.00;
            StoreApp.state.coinsRate = parseFloat(data.data.coins_conversion_rate) || 1.00;
            StoreApp.state.coinsName = data.data.coins_name || 'Prestige Coins';
            StoreApp.state.currencySymbol = data.data.currency_symbol || '₱';
            updateHeaderCoins();
        }
    } catch (err) {
        console.warn('[Store] Could not load store settings, using defaults');
    }
}

function updateHeaderCoins() {
    const coins = Number(StoreApp.state.customerCoins || 0);
    const rate = parseFloat(StoreApp.state.coinsRate) || 1.00;
    const formattedCoins = coins.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Coins';
    const headerEl = document.getElementById('headerCoinBalance');
    const modalEl = document.getElementById('modalWalletBalance');
    const accountWalletVal = document.getElementById('accountWalletVal');
    const tabWalletBalance = document.getElementById('tabWalletBalance');

    if (headerEl) headerEl.textContent = formattedCoins;
    if (modalEl) {
        if (rate !== 1.00) {
            modalEl.textContent = `${formattedCoins} (₱${(coins * rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})`;
        } else {
            modalEl.textContent = formattedCoins;
        }
    }
    if (accountWalletVal) accountWalletVal.textContent = formattedCoins;
    if (tabWalletBalance) {
        if (rate !== 1.00) {
            tabWalletBalance.textContent = `${formattedCoins} (₱${(coins * rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})`;
        } else {
            tabWalletBalance.textContent = formattedCoins;
        }
    }
}

/**
 * =========================================================================
 * 5. CATEGORIES FETCH & ICON BAR
 * =========================================================================
 */
async function loadCategories() {
    try {
        const res = await fetch('handler.php?action=list_categories');
        const json = await res.json();
        if (json && json.success && json.data) {
            StoreApp.state.categories = json.data.categories || [];
            renderCategoryBar();
        }
    } catch (err) {
        console.error('[Store] Failed to load categories', err);
    }
}

function renderCategoryBar() {
    const bar = document.getElementById('categoryBar');
    if (!bar) return;

    let totalProducts = 0;
    StoreApp.state.categories.forEach(c => totalProducts += c.product_count);
    const countAllEl = document.getElementById('countAll');
    if (countAllEl) countAllEl.textContent = totalProducts;

    const iconSvgs = {
        iphone: '<svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="3"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>',
        mac: '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
        ipad: '<svg viewBox="0 0 24 24"><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="18" r="1"/></svg>',
        watch: '<svg viewBox="0 0 24 24"><rect x="6" y="5" width="12" height="14" rx="3"/><path d="M9 5V2h6v3M9 19v3h6v-3"/></svg>',
        airpods: '<svg viewBox="0 0 24 24"><path d="M6 16v-5a6 6 0 1 1 12 0v5"/><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/></svg>',
        accessories: '<svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
        device: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>'
    };

    StoreApp.state.categories.forEach(cat => {
        const chip = document.createElement('div');
        chip.className = 'category-chip';
        chip.setAttribute('data-cat-id', cat.id);
        chip.onclick = () => selectCategory(cat.id, cat.name);

        const svg = iconSvgs[cat.icon_key] || iconSvgs.device;
        chip.innerHTML = `
            ${svg}
            <span>${escapeHtml(cat.name)}</span>
            <span class="category-chip-count">${cat.product_count}</span>
        `;
        bar.appendChild(chip);
    });
}

function selectCategory(catId, catName = 'All Apple Devices') {
    StoreApp.state.categoryId = catId;
    StoreApp.state.categoryName = catName;
    StoreApp.state.page = 1;

    document.querySelectorAll('.category-chip').forEach(chip => {
        const id = chip.getAttribute('data-cat-id');
        chip.classList.toggle('active', String(id) === String(catId));
    });

    const titleEl = document.getElementById('catalogTitle');
    if (titleEl) {
        titleEl.textContent = catId === 'all' ? 'Explore Apple Catalog' : `${catName} Collection`;
    }

    loadProducts();
}

/**
 * =========================================================================
 * 6. FLASH DEALS & COUNTDOWN TIMER
 * =========================================================================
 */
async function loadFlashDeals() {
    try {
        const res = await fetch('handler.php?action=get_flash_deals');
        const json = await res.json();
        if (json && json.success && json.data) {
            StoreApp.state.flashDeals = json.data.deals || [];
            StoreApp.state.countdownSeconds = parseInt(json.data.countdown_seconds, 10) || 64800;
            renderFlashDeals();
            startFlashCountdown();
        }
    } catch (err) {
        console.error('[Store] Failed to load flash deals', err);
    }
}

function startFlashCountdown() {
    const hoursEl = document.getElementById('flashHours');
    const minEl = document.getElementById('flashMinutes');
    const secEl = document.getElementById('flashSeconds');

    setInterval(() => {
        if (StoreApp.state.countdownSeconds > 0) {
            StoreApp.state.countdownSeconds--;
        } else {
            StoreApp.state.countdownSeconds = 64800;
        }

        const hrs = Math.floor(StoreApp.state.countdownSeconds / 3600);
        const mins = Math.floor((StoreApp.state.countdownSeconds % 3600) / 60);
        const secs = StoreApp.state.countdownSeconds % 60;

        if (hoursEl) hoursEl.textContent = String(hrs).padStart(2, '0');
        if (minEl) minEl.textContent = String(mins).padStart(2, '0');
        if (secEl) secEl.textContent = String(secs).padStart(2, '0');
    }, 1000);
}

function renderFlashDeals() {
    const grid = document.getElementById('flashDealsGrid');
    if (!grid) return;

    if (!StoreApp.state.flashDeals.length) {
        grid.innerHTML = '<div style="padding:20px; color:var(--apple-gray);">No active flash deals at this moment.</div>';
        return;
    }

    let html = '';
    StoreApp.state.flashDeals.forEach(deal => {
        html += `
            <div class="deal-card" onclick="window.location.href='product.php?id=${deal.id}'">
                <span class="deal-badge">-${deal.discount_percent}%</span>
                <div class="deal-img-wrap">
                    <img src="../${escapeHtml(deal.primary_image)}" alt="${escapeHtml(deal.name)}" onerror="this.src='../assets/images/logo.png'">
                </div>
                <div class="deal-title" title="${escapeHtml(deal.name)}">${escapeHtml(deal.name)}</div>
                <div class="deal-specs">${escapeHtml(deal.storage || 'Official Spec')} ${deal.color ? '&bull; ' + escapeHtml(deal.color) : ''}</div>
                <div class="deal-price-wrap">
                    <span class="deal-price">${deal.formatted_price}</span>
                    <span class="deal-orig">${deal.formatted_orig}</span>
                </div>
                <div class="deal-progress-wrap">
                    <div class="deal-progress-bar">
                        <div class="deal-progress-fill" style="width: ${deal.claimed_percent}%;"></div>
                    </div>
                    <div class="deal-stock-label">
                        <span>🔥 ${deal.claimed_percent}% Claimed</span>
                        <span>${deal.stock_left} Left</span>
                    </div>
                </div>
            </div>
        `;
    });

    grid.innerHTML = html;
}

/**
 * =========================================================================
 * 7. MAIN CATALOG BROWSING & LIVE FILTERING
 * =========================================================================
 */
async function loadProducts() {
    const grid = document.getElementById('productGrid');
    const loading = document.getElementById('catalogLoading');
    if (loading) loading.style.display = 'block';

    const params = new URLSearchParams({
        action: 'list_products',
        page: StoreApp.state.page,
        limit: StoreApp.state.limit,
        sort: StoreApp.state.sort,
        condition: StoreApp.state.condition
    });

    if (StoreApp.state.categoryId !== 'all') {
        params.append('category_id', StoreApp.state.categoryId);
    }
    if (StoreApp.state.searchQuery) {
        params.append('search', StoreApp.state.searchQuery);
    }

    try {
        const res = await fetch('handler.php?' + params.toString());
        const json = await res.json();
        if (json && json.success && json.data) {
            StoreApp.state.products = json.data.products || [];
            renderProductGrid();
        } else {
            if (grid) grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding:32px; color:var(--apple-gray);">No Apple products found matching your search.</div>';
        }
    } catch (err) {
        if (grid) grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding:32px; color:var(--flame-red);">Error loading products. Please refresh.</div>';
    } finally {
        if (loading) loading.style.display = 'none';
    }
}

function renderProductGrid() {
    const grid = document.getElementById('productGrid');
    if (!grid) return;

    if (!StoreApp.state.products.length) {
        grid.innerHTML = `
            <div style="grid-column: 1/-1; text-align:center; padding:48px 0; color:var(--apple-gray);">
                <div style="font-size:36px; margin-bottom:8px;">🔍</div>
                <div style="font-size:16px; font-weight:700; color:var(--apple-dark);">No Products Found</div>
                <p style="margin-top:4px;">Try searching with different keywords or clearing active filters.</p>
                <button type="button" class="btn-apple-primary" onclick="clearAllFilters()" style="margin-top:14px;">
                    View All Products
                </button>
            </div>
        `;
        return;
    }

    let html = '';
    StoreApp.state.products.forEach(p => {
        const cond = p.condition_type || 'Brand New Sealed';
        let badgeCls = 'badge-condition-brand-new';
        if (cond.toLowerCase().includes('refurbished')) badgeCls = 'badge-condition-refurbished';
        if (cond.toLowerCase().includes('open box')) badgeCls = 'badge-condition-open-box';

        html += `
            <div class="product-card" onclick="window.location.href='product.php?id=${p.id}'">
                <div class="product-badge-wrap">
                    <span class="badge-condition ${badgeCls}">${escapeHtml(cond)}</span>
                </div>
                <div class="product-img-box">
                    <img src="../${escapeHtml(p.primary_image)}" alt="${escapeHtml(p.name)}" onerror="this.src='../assets/images/logo.png'">
                </div>
                <div class="product-info">
                    <h3 class="product-name" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</h3>
                    <div class="product-specs-pills">
                        ${p.storage ? `<span class="spec-pill">${escapeHtml(p.storage)}</span>` : ''}
                        ${p.color ? `<span class="spec-pill">${escapeHtml(p.color)}</span>` : ''}
                        <span class="spec-pill">Official Warranty</span>
                    </div>
                    <div class="product-price-row">
                        <div class="product-price">${p.formatted_price}</div>
                        <div class="stock-tag ${p.current_stock <= 3 ? 'low' : ''}">
                            ${p.current_stock <= 3 ? `Only ${p.current_stock} left` : 'In Stock'}
                        </div>
                    </div>
                    <button type="button" class="card-action-btn" onclick="event.stopPropagation(); addToBag(${p.id}, '${escapeHtml(p.name)}', ${p.selling_price}, '${escapeHtml(p.primary_image)}', '${escapeHtml(p.color || 'Standard')}', '${escapeHtml(p.storage || 'Default')}', 1, ${p.current_stock})">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                        Add to Bag
                    </button>
                </div>
            </div>
        `;
    });

    grid.innerHTML = html;
}

/**
 * Filter and Search Handlers
 */
function setConditionFilter(condition, btn) {
    StoreApp.state.condition = condition;
    StoreApp.state.page = 1;
    document.querySelectorAll('.condition-pill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    loadProducts();
}

function setSortOrder(sortVal) {
    StoreApp.state.sort = sortVal;
    StoreApp.state.page = 1;
    loadProducts();
}

function applySearchChip(term) {
    const input = document.getElementById('storeSearchInput');
    if (input) {
        input.value = term;
        toggleSearchClearBtn(true);
    }
    StoreApp.state.searchQuery = term;
    StoreApp.state.page = 1;
    loadProducts();

    const section = document.getElementById('catalogSection');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function clearStoreSearch() {
    const input = document.getElementById('storeSearchInput');
    if (input) {
        input.value = '';
        toggleSearchClearBtn(false);
    }
    StoreApp.state.searchQuery = '';
    StoreApp.state.page = 1;
    loadProducts();
}

function toggleSearchClearBtn(show) {
    const btn = document.getElementById('searchClearBtn');
    if (btn) btn.style.display = show ? 'block' : 'none';
}

function clearAllFilters() {
    clearStoreSearch();
    selectCategory('all');
    setConditionFilter('all', document.querySelector('.condition-pill'));
}

/**
 * =========================================================================
 * 8. PRESTIGE COINS TOP-UP MODAL HANDLING
 * =========================================================================
 */
function openTopUpModal() {
    const modal = document.getElementById('topUpModal');
    if (modal) {
        modal.classList.add('active');
        document.getElementById('customTopUpAmount').value = '';
        updateTopUpPreview();
    }
}

function closeTopUpModal() {
    const modal = document.getElementById('topUpModal');
    if (modal) modal.classList.remove('active');
}

function selectPresetAmount(amount) {
    const input = document.getElementById('customTopUpAmount');
    if (input) {
        input.value = amount;
        updateTopUpPreview();
    }
    document.querySelectorAll('.preset-chip').forEach(chip => {
        chip.classList.toggle('active', chip.textContent.includes(amount.toLocaleString()));
    });
}

function updateTopUpPreview() {
    const input = document.getElementById('customTopUpAmount');
    const previewEl = document.getElementById('newBalancePreview');
    const entered = parseFloat(input ? input.value : 0) || 0;
    const rate = parseFloat(StoreApp.state.coinsRate) || 1.00;
    const coinsToAdd = Math.round((entered / rate) * 100) / 100;
    const currentCoins = parseFloat(StoreApp.state.customerCoins) || 0.00;
    const projectedCoins = Math.round((currentCoins + coinsToAdd) * 100) / 100;

    if (previewEl) {
        if (rate !== 1.00) {
            const projectedPhp = (projectedCoins * rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            previewEl.textContent = `${projectedCoins.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Coins (+${coinsToAdd.toFixed(2)} Coins • ₱${projectedPhp})`;
        } else {
            previewEl.textContent = `${projectedCoins.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Coins (+${coinsToAdd.toFixed(2)} Coins)`;
        }
    }
}

function selectPaymentMethod(method) {
    const tabGcash = document.getElementById('tabGcash');
    const tabBank = document.getElementById('tabBank');
    const detailsGcash = document.getElementById('channelDetailsGcash');
    const detailsBank = document.getElementById('channelDetailsBank');

    if (method === 'GCASH') {
        if (tabGcash) {
            tabGcash.style.boxShadow = '0 0 0 2px var(--apple-blue), 0 4px 14px rgba(0, 113, 227, 0.15)';
            tabGcash.style.background = '#eff6ff';
            tabGcash.classList.add('active');
        }
        if (tabBank) {
            tabBank.style.boxShadow = '0 2px 8px rgba(0,0,0,0.05)';
            tabBank.style.background = '#ffffff';
            tabBank.classList.remove('active');
        }
        if (detailsGcash) detailsGcash.style.display = 'block';
        if (detailsBank) detailsBank.style.display = 'none';
    } else {
        if (tabBank) {
            tabBank.style.boxShadow = '0 0 0 2px var(--apple-blue), 0 4px 14px rgba(0, 113, 227, 0.15)';
            tabBank.style.background = '#eff6ff';
            tabBank.classList.add('active');
        }
        if (tabGcash) {
            tabGcash.style.boxShadow = '0 2px 8px rgba(0,0,0,0.05)';
            tabGcash.style.background = '#ffffff';
            tabGcash.classList.remove('active');
        }
        if (detailsGcash) detailsGcash.style.display = 'none';
        if (detailsBank) detailsBank.style.display = 'block';
    }
}

function copyToClipboard(text, msg) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showToast(msg || 'Copied to clipboard!', 'success');
        }).catch(() => {
            showToast('Copied: ' + text, 'info');
        });
    } else {
        showToast('Copied: ' + text, 'info');
    }
}

async function submitCoinsTopUp(e) {
    e.preventDefault();
    const btn = document.getElementById('topUpSubmitBtn');
    const input = document.getElementById('customTopUpAmount');
    const amount = parseFloat(input.value);

    if (isNaN(amount) || amount <= 0) {
        showToast('Please enter a valid top-up amount greater than 0', 'error');
        return;
    }

    const origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Processing Top-Up...';

    try {
        const res = await fetch('handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'topup_coins',
                amount: amount
            })
        });

        const json = await res.json();
        if (json && json.success && json.data) {
            StoreApp.state.customerCoins = parseFloat(json.data.new_balance);
            if (json.data.conversion_rate) {
                StoreApp.state.coinsRate = parseFloat(json.data.conversion_rate);
            }
            updateHeaderCoins();
            closeTopUpModal();
            showToast(`🎉 Top-up successful! Added ${json.data.formatted_added} to your Prestige Coins wallet.`, 'success');
        } else {
            showToast((json && json.message) ? json.message : 'Top-up failed.', 'error');
        }
    } catch (err) {
        showToast('Network error during top-up. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = origText;
    }
}

/**
 * Lightweight Toast Notification Helper
 */
function showToast(message, type = 'info') {
    let container = document.getElementById('storeToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'storeToastContainer';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `store-toast store-toast-${type}`;
    
    // Add icon prefix for quick visual recognition
    let icon = 'ℹ️';
    if (type === 'success') icon = '✅';
    if (type === 'error') icon = '⚠️';

    toast.innerHTML = `<span style="font-size: 16px; flex-shrink: 0;">${icon}</span><span>${escapeHtml(message)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-12px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Global initialization
document.addEventListener('DOMContentLoaded', () => {
    updateHeaderCartBadge();
    initHeroCarousel();
    loadStoreInfo();
    loadCategories();
    loadFlashDeals();
    loadProducts();

    const searchInput = document.getElementById('storeSearchInput');
    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            const val = e.target.value.trim();
            toggleSearchClearBtn(val.length > 0);
            debounceTimer = setTimeout(() => {
                StoreApp.state.searchQuery = val;
                StoreApp.state.page = 1;
                loadProducts();
            }, 300);
        });
    }
});

/**
 * =========================================================================
 * 9. CHECKOUT GATEWAY & ORDER PLACEMENT ENGINE (PHASE 5)
 * =========================================================================
 */
const CheckoutState = {
    cart: null,
    courierCode: 'STANDARD',
    paymentMethod: 'PRESTIGE_COINS',
    wallet: null,
    couriers: null,
    customer: null,
    subtotal: 0,
    deliveryFee: 0,
    grandTotal: 0
};

/**
 * Initialize Checkout Page on DOM Ready
 */
async function initCheckoutPage() {
    try {
        // Synchronize client local bag to server session if local cart is populated
        const localCart = getCart();
        if (localCart && localCart.length > 0) {
            try {
                await fetch('handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'sync_cart', items: localCart })
                });
            } catch (e) {
                console.warn('[Cart Sync Warning]', e);
            }
        }

        const res = await fetch('handler.php?action=get_checkout_data');
        const json = await res.json();

        if (!json || !json.success || !json.data) {
            const errMsg = json?.message || 'Unable to load checkout data. Redirecting to bag...';
            showToast(errMsg, 'error');
            setTimeout(() => window.location.href = 'cart.php', 1500);
            return;
        }

        const data = json.data;

        // If cart items are empty after sync, notify user and redirect to cart
        if (!data.cart || !data.cart.items || data.cart.items.length === 0) {
            showToast('Your shopping bag is empty. Please select an Apple device.', 'info');
            setTimeout(() => window.location.href = 'cart.php', 1500);
            return;
        }

        CheckoutState.cart = data.cart;
        CheckoutState.couriers = data.couriers;
        CheckoutState.wallet = data.wallet;
        CheckoutState.customer = data.customer;
        CheckoutState.subtotal = data.cart.subtotal;

        // Pre-fill Customer Address Fields
        if (data.customer) {
            setInputValue('customerName', data.customer.name);
            setInputValue('customerPhone', data.customer.phone);
            setInputValue('customerEmail', data.customer.email);
            setInputValue('province', data.customer.province);
            setInputValue('city', data.customer.city);
            setInputValue('barangay', data.customer.barangay);
            setInputValue('streetAddress', data.customer.street_address);
            setInputValue('landmark', data.customer.landmark);
        }

        // Render Checkout Summary Line Items
        renderCheckoutItemsList();

        // Calculate and Render Totals & Sufficiency
        recalculateCheckoutTotals();
    } catch (err) {
        console.error('[Checkout Error]', err);
        showToast('Network error initializing checkout.', 'error');
    }
}

function setInputValue(id, val) {
    const el = document.getElementById(id);
    if (el && val && !el.value) {
        el.value = val;
    }
}

function renderCheckoutItemsList() {
    const listEl = document.getElementById('checkoutItemsList');
    if (!listEl || !CheckoutState.cart) return;

    let html = '';
    CheckoutState.cart.items.forEach(item => {
        const imgPath = item.primary_image.startsWith('../') ? item.primary_image : `../${item.primary_image}`;
        html += `
            <div class="checkout-item-line">
                <div class="checkout-item-thumb">
                    <img src="${imgPath}" alt="${escapeHtml(item.name)}" onerror="this.src='../assets/images/logo.png'">
                </div>
                <div class="checkout-item-info">
                    <div class="checkout-item-title">${escapeHtml(item.name)}</div>
                    <div class="checkout-item-specs">${escapeHtml(item.storage)} &bull; ${escapeHtml(item.color)} &bull; Qty: ${item.quantity}</div>
                </div>
                <div class="checkout-item-cost">${item.formatted_line}</div>
            </div>
        `;
    });

    listEl.innerHTML = html;
}

/**
 * Handle Courier Switch
 */
function handleCourierChange(code) {
    CheckoutState.courierCode = code;

    document.querySelectorAll('.courier-option-card').forEach(card => card.classList.remove('active'));
    const targetCard = document.getElementById(code === 'STANDARD' ? 'courierCardStandard' : 'courierCardExpress');
    if (targetCard) targetCard.classList.add('active');

    recalculateCheckoutTotals();
}

/**
 * Handle Payment Method Switch
 */
function handlePaymentChange(method) {
    CheckoutState.paymentMethod = method;

    document.querySelectorAll('.payment-option-card').forEach(card => card.classList.remove('active'));
    const targetCard = document.getElementById(method === 'PRESTIGE_COINS' ? 'paymentCardCoins' : 'paymentCardCod');
    if (targetCard) targetCard.classList.add('active');

    recalculateCheckoutTotals();
}

/**
 * Recalculate Shipping Fee, Grand Total, and Coins Sufficiency
 */
function recalculateCheckoutTotals() {
    const subtotal = CheckoutState.subtotal;
    const isExpress = CheckoutState.courierCode === 'EXPRESS';
    const deliveryFee = isExpress ? 350.00 : (subtotal >= 5000.00 ? 0.00 : 150.00);
    const grandTotal = Math.round((subtotal + deliveryFee) * 100) / 100;

    CheckoutState.deliveryFee = deliveryFee;
    CheckoutState.grandTotal = grandTotal;

    const currency = StoreApp.state.currencySymbol || '₱';
    const formattedSubtotal = currency + Number(subtotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const formattedDelivery = deliveryFee === 0.00 ? 'FREE' : currency + Number(deliveryFee).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const formattedGrandTotal = currency + Number(grandTotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const subtotalEl = document.getElementById('checkoutSubtotal');
    const shippingEl = document.getElementById('checkoutShippingFee');
    const grandTotalEl = document.getElementById('checkoutGrandTotal');
    const placeBtnText = document.getElementById('placeOrderBtnText');

    if (subtotalEl) subtotalEl.textContent = formattedSubtotal;
    if (shippingEl) {
        shippingEl.textContent = formattedDelivery;
        shippingEl.style.color = deliveryFee === 0.00 ? '#059669' : 'var(--apple-dark)';
    }
    if (grandTotalEl) grandTotalEl.textContent = formattedGrandTotal;
    if (placeBtnText) placeBtnText.textContent = `Place Order (${formattedGrandTotal}) \u2192`;

    // Standard fee badge text update
    const standardFeeBadge = document.getElementById('standardFeeBadge');
    if (standardFeeBadge) {
        standardFeeBadge.textContent = subtotal >= 5000.00 ? 'FREE' : '₱150.00';
    }

    // Evaluate Prestige Coins Sufficiency
    evaluateCoinsSufficiency(grandTotal);
}

function evaluateCoinsSufficiency(grandTotal) {
    const container = document.getElementById('coinsSufficiencyContainer');
    const badgeEl = document.getElementById('checkoutCoinsBadge');
    const placeBtn = document.getElementById('placeOrderBtn');
    if (!container || !CheckoutState.wallet) return;

    const currentCoins = parseFloat(CheckoutState.wallet.coin_balance) || 0;
    const rate = parseFloat(StoreApp.state.coinsRate || CheckoutState.wallet.conversion_rate) || 1.00;
    const currency = StoreApp.state.currencySymbol || '₱';
    const purchasingPower = Math.round(currentCoins * rate * 100) / 100;
    const requiredCoins = Math.round((grandTotal / rate) * 100) / 100;

    const formattedCoins = Number(currentCoins).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Coins';
    const formattedPower = currency + Number(purchasingPower).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const formattedRequiredCoins = Number(requiredCoins).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Coins';
    const formattedGrandTotal = currency + Number(grandTotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (badgeEl) {
        badgeEl.textContent = `Available: ${formattedCoins}` + (rate !== 1.00 ? ` (${formattedPower})` : '');
    }

    if (currentCoins >= requiredCoins) {
        // Sufficient Balance
        container.innerHTML = `
            <div class="coins-sufficient-box">
                <span>✓</span>
                <span>Prestige Coins Sufficient: ${formattedCoins}${rate !== 1.00 ? ` (${formattedPower} value)` : ''} available. Instant 1-click settlement ready.</span>
            </div>
        `;
        if (placeBtn && CheckoutState.paymentMethod === 'PRESTIGE_COINS') {
            placeBtn.disabled = false;
        }
    } else {
        // Shortage
        const shortageCoins = Math.round((requiredCoins - currentCoins) * 100) / 100;
        const shortagePhp = Math.round(shortageCoins * rate * 100) / 100;
        const formattedShortageCoins = Number(shortageCoins).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Coins';
        const formattedShortagePhp = currency + Number(shortagePhp).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        container.innerHTML = `
            <div class="coins-shortage-box">
                <div class="shortage-warning-title">
                    <span>⚠️</span> Insufficient Coins Balance
                </div>
                <div class="shortage-warning-desc">
                    You currently have <strong>${formattedCoins}</strong>${rate !== 1.00 ? ` (${formattedPower})` : ''}, but this order requires <strong>${formattedRequiredCoins}</strong> (${formattedGrandTotal}). You are short by <strong>${formattedShortageCoins}${rate !== 1.00 ? ` (${formattedShortagePhp})` : ''}</strong>.
                </div>
                <button type="button" class="shortage-topup-btn" onclick="openTopUpForShortage(${shortagePhp})">
                    <span>+</span> Top Up Shortage (${formattedShortagePhp}) Now
                </button>
            </div>
        `;

        if (placeBtn && CheckoutState.paymentMethod === 'PRESTIGE_COINS') {
            placeBtn.disabled = true;
        }
    }

    if (CheckoutState.paymentMethod === 'COD' && placeBtn) {
        placeBtn.disabled = false;
    }
}

/**
 * Open Top-Up Modal Pre-filled with Exact Shortage
 */
function openTopUpForShortage(shortageAmount) {
    openTopUpModal();
    const input = document.getElementById('customTopUpAmount');
    if (input) {
        input.value = shortageAmount;
        updateTopUpPreview();
    }
}

/**
 * Handle Atomic Place Order Submission
 */
async function handlePlaceOrder(e) {
    e.preventDefault();

    const btn = document.getElementById('placeOrderBtn');
    const btnText = document.getElementById('placeOrderBtnText');
    const origText = btnText.innerHTML;

    btn.disabled = true;
    btnText.innerHTML = 'Securing &amp; Placing Order...';

    const payload = {
        action: 'place_order',
        customer_name: document.getElementById('customerName').value.trim(),
        customer_phone: document.getElementById('customerPhone').value.trim(),
        customer_email: document.getElementById('customerEmail').value.trim(),
        province: document.getElementById('province').value.trim(),
        city: document.getElementById('city').value.trim(),
        barangay: document.getElementById('barangay').value.trim(),
        street_address: document.getElementById('streetAddress').value.trim(),
        landmark: document.getElementById('landmark').value.trim(),
        order_notes: document.getElementById('orderNotes').value.trim(),
        courier_code: CheckoutState.courierCode,
        payment_method: CheckoutState.paymentMethod
    };

    try {
        const res = await fetch('handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await res.json();

        if (json && json.success && json.data) {
            // Clear local client bag
            saveCart([]);
            showToast('Order confirmed successfully! Redirecting...', 'success');
            setTimeout(() => {
                window.location.href = json.data.redirect_url || `order-success.php?order=${encodeURIComponent(json.data.order_number)}`;
            }, 500);
        } else {
            const errorMsg = (json && json.message) ? json.message : 'Failed to place order. Please review details.';
            showToast(errorMsg, 'error');
            btn.disabled = false;
            btnText.innerHTML = origText;
        }
    } catch (err) {
        console.error('[Place Order Network Error]', err);
        showToast('A network error occurred. Please try again.', 'error');
        btn.disabled = false;
        btnText.innerHTML = origText;
    }
}

// Hook Top-Up Success to dynamically refresh checkout wallet state without clearing form fields
const originalSubmitCoinsTopUp = window.submitCoinsTopUp;
window.submitCoinsTopUp = async function(e) {
    await originalSubmitCoinsTopUp(e);
    if (window.location.pathname.includes('checkout.php') && CheckoutState.wallet) {
        CheckoutState.wallet.coin_balance = StoreApp.state.customerCoins;
        recalculateCheckoutTotals();
    }
};


// =============================================================================
// 11. CUSTOMER ACCOUNT, PROFILE & ORDER HISTORY CONTROLLER (store/account.php)
// =============================================================================

let cachedAccountOrders = [];

window.switchAccountTab = function(tabName, btnEl) {
    // 1. Update navigation items
    document.querySelectorAll('.account-nav-item').forEach(el => el.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');

    // 2. Switch tab panels
    document.querySelectorAll('.account-tab-panel').forEach(panel => panel.classList.remove('active'));
    const targetPanel = document.getElementById('tab-' + tabName);
    if (targetPanel) {
        targetPanel.classList.add('active');
    }

    // 3. Update URL without reloading
    const newUrl = new URL(window.location);
    newUrl.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', newUrl);

    // 4. Auto-load orders if switching to orders tab
    if (tabName === 'orders' && cachedAccountOrders.length === 0) {
        loadAccountOrders('all');
    }
};

window.loadAccountData = async function() {
    try {
        const res = await fetch('handler.php?action=customer_get_account');
        const json = await res.json();
        if (json && json.success && json.data) {
            const c = json.data.customer;
            const a = json.data.default_address;

            // Hero Banner
            const heroName = document.getElementById('heroCustomerName');
            if (heroName) heroName.textContent = c.name;
            const heroEmail = document.getElementById('heroCustomerEmail');
            if (heroEmail) heroEmail.textContent = c.email;
            const heroPhone = document.getElementById('heroCustomerPhone');
            if (heroPhone) heroPhone.textContent = c.phone || 'No phone set';

            const walletVal = document.getElementById('accountWalletVal');
            if (walletVal) walletVal.textContent = c.formatted_coins;
            const tabWallet = document.getElementById('tabWalletBalance');
            if (tabWallet) tabWallet.textContent = c.formatted_coins;

            const orderCount = document.getElementById('navOrderCount');
            if (orderCount) orderCount.textContent = c.total_orders;

            // Profile Form
            const profName = document.getElementById('profName');
            if (profName) profName.value = c.name || '';
            const profPhone = document.getElementById('profPhone');
            if (profPhone) profPhone.value = c.phone || '';
            const profEmail = document.getElementById('profEmail');
            if (profEmail) profEmail.value = c.email || '';

            // Address Form
            if (a) {
                const a1 = document.getElementById('addrLine1');
                if (a1) a1.value = a.address_line1 || '';
                const a2 = document.getElementById('addrLine2');
                if (a2) a2.value = a.address_line2 || '';
                const city = document.getElementById('addrCity');
                if (city) city.value = a.city || '';
                const prov = document.getElementById('addrProvince');
                if (prov) prov.value = a.province_state || '';
                const post = document.getElementById('addrPostal');
                if (post) post.value = a.postal_code || '';
            }
        } else if (json && json.data && json.data.redirect) {
            window.location.href = json.data.redirect;
        }
    } catch (e) {
        console.error('[Account Load Error]', e);
    }
};

window.loadAccountOrders = async function(statusFilter = 'all') {
    const listEl = document.getElementById('accountOrdersList');
    const loadingEl = document.getElementById('accountOrdersLoading');
    const emptyEl = document.getElementById('accountOrdersEmpty');
    if (!listEl) return;

    if (loadingEl) loadingEl.style.display = 'block';
    if (emptyEl) emptyEl.style.display = 'none';
    listEl.innerHTML = '';

    try {
        const res = await fetch(`handler.php?action=customer_list_orders&status=${encodeURIComponent(statusFilter)}`);
        const json = await res.json();
        if (loadingEl) loadingEl.style.display = 'none';

        if (json && json.success && Array.isArray(json.data.orders)) {
            cachedAccountOrders = json.data.orders;
            const orders = json.data.orders;

            if (orders.length === 0) {
                if (emptyEl) emptyEl.style.display = 'block';
                return;
            }

            let html = '';
            orders.forEach(ord => {
                let statusClass = 'confirmed';
                if (ord.order_status === 'DISPATCHED') statusClass = 'dispatched';
                if (ord.order_status === 'DELIVERED') statusClass = 'delivered';
                if (ord.order_status === 'CANCELLED') statusClass = 'cancelled';

                let paymentBadge = ord.is_coins_paid 
                    ? `<span class="order-payment-badge coins">🪙 Prestige Coins</span>`
                    : `<span class="order-payment-badge">💵 Cash on Delivery</span>`;

                let itemsHtml = '';
                ord.items.forEach(item => {
                    const img = item.image_url || '../assets/images/logo.png';
                    itemsHtml += `
                        <div class="order-item-row">
                            <div class="order-item-left">
                                <img src="${img}" alt="${item.product_name}" class="order-item-img" onerror="this.src='../assets/images/logo.png'">
                                <div>
                                    <div class="order-item-title">${item.product_name}</div>
                                    <div class="order-item-specs">${item.variant_specs ? item.variant_specs + ' &bull; ' : ''}Qty: ${item.quantity}</div>
                                </div>
                            </div>
                            <div class="order-item-price">${item.formatted_line}</div>
                        </div>
                    `;
                });

                const safeJson = encodeURIComponent(JSON.stringify(ord));

                html += `
                    <div class="order-history-card">
                        <div class="order-card-header">
                            <div class="order-ref-group">
                                <span class="order-ref-no">${ord.order_number}</span>
                                <span class="order-date-tag">&bull; ${ord.date_short}</span>
                            </div>
                            <div class="order-badge-group">
                                ${paymentBadge}
                                <span class="order-status-badge ${statusClass}">${ord.order_status}</span>
                            </div>
                        </div>
                        <div class="order-card-body">
                            <div class="order-items-preview">
                                ${itemsHtml}
                            </div>
                        </div>
                        <div class="order-card-footer">
                            <div class="order-total-block">
                                <span class="label">Total Amount:</span>
                                <span class="amount">${ord.formatted_total}</span>
                            </div>
                            <div class="order-actions-group">
                                <button type="button" class="btn-order-details" onclick="openOrderReceiptDetails('${safeJson}')">
                                    <span>📄</span> Details &amp; Receipt
                                </button>
                                <a href="${ord.track_url}" class="btn-track-order">
                                    <span>🚚</span> Track Shipment &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }
    } catch (e) {
        if (loadingEl) loadingEl.style.display = 'none';
        console.error('[Orders Load Error]', e);
    }
};

window.filterOrderHistory = function(status, btnEl) {
    document.querySelectorAll('.order-filter-btn').forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    loadAccountOrders(status);
};

window.handleProfileSubmit = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveProfile');
    const msg = document.getElementById('profileSaveMsg');
    const name = document.getElementById('profName').value.trim();
    const phone = document.getElementById('profPhone').value.trim();

    if (btn) btn.disabled = true;
    if (msg) { msg.className = 'save-status-msg'; msg.textContent = 'Saving changes...'; }

    try {
        const res = await fetch('handler.php?action=customer_update_profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, phone })
        });
        const json = await res.json();
        if (btn) btn.disabled = false;

        if (json && json.success) {
            if (msg) { msg.className = 'save-status-msg success'; msg.textContent = '✓ Profile updated successfully!'; }
            showToast('Profile updated successfully!', 'success');
            const heroName = document.getElementById('heroCustomerName');
            if (heroName) heroName.textContent = name;
            const heroPhone = document.getElementById('heroCustomerPhone');
            if (heroPhone) heroPhone.textContent = phone;
        } else {
            const err = (json && json.message) ? json.message : 'Failed to update profile.';
            if (msg) { msg.className = 'save-status-msg error'; msg.textContent = '✕ ' + err; }
            showToast(err, 'error');
        }
    } catch (err) {
        if (btn) btn.disabled = false;
        if (msg) { msg.className = 'save-status-msg error'; msg.textContent = '✕ Network error.'; }
    }
};

window.handleAddressSubmit = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveAddress');
    const msg = document.getElementById('addressSaveMsg');
    const name = document.getElementById('profName') ? document.getElementById('profName').value.trim() : '';
    const phone = document.getElementById('profPhone') ? document.getElementById('profPhone').value.trim() : '';
    const address_line1 = document.getElementById('addrLine1').value.trim();
    const address_line2 = document.getElementById('addrLine2').value.trim();
    const city = document.getElementById('addrCity').value.trim();
    const province_state = document.getElementById('addrProvince').value.trim();
    const postal_code = document.getElementById('addrPostal').value.trim();

    if (btn) btn.disabled = true;
    if (msg) { msg.className = 'save-status-msg'; msg.textContent = 'Saving address...'; }

    try {
        const res = await fetch('handler.php?action=customer_update_profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, phone, address_line1, address_line2, city, province_state, postal_code })
        });
        const json = await res.json();
        if (btn) btn.disabled = false;

        if (json && json.success) {
            if (msg) { msg.className = 'save-status-msg success'; msg.textContent = '✓ Default shipping address saved!'; }
            showToast('Shipping address saved successfully!', 'success');
        } else {
            const err = (json && json.message) ? json.message : 'Failed to save address.';
            if (msg) { msg.className = 'save-status-msg error'; msg.textContent = '✕ ' + err; }
            showToast(err, 'error');
        }
    } catch (err) {
        if (btn) btn.disabled = false;
        if (msg) { msg.className = 'save-status-msg error'; msg.textContent = '✕ Network error.'; }
    }
};

window.handlePasswordSubmit = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSavePassword');
    const msg = document.getElementById('passwordSaveMsg');
    const current_password = document.getElementById('currPass').value;
    const new_password = document.getElementById('newPass').value;
    const confirm_password = document.getElementById('confPass').value;

    if (new_password !== confirm_password) {
        showToast('New passwords do not match.', 'error');
        return;
    }

    if (btn) btn.disabled = true;
    if (msg) { msg.className = 'save-status-msg'; msg.textContent = 'Updating password...'; }

    try {
        const res = await fetch('handler.php?action=customer_change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password, new_password, confirm_password })
        });
        const json = await res.json();
        if (btn) btn.disabled = false;

        if (json && json.success) {
            if (msg) { msg.className = 'save-status-msg success'; msg.textContent = '✓ Password changed successfully!'; }
            showToast('Account password updated successfully!', 'success');
            document.getElementById('passwordForm').reset();
        } else {
            const err = (json && json.message) ? json.message : 'Failed to change password.';
            if (msg) { msg.className = 'save-status-msg error'; msg.textContent = '✕ ' + err; }
            showToast(err, 'error');
        }
    } catch (err) {
        if (btn) btn.disabled = false;
        if (msg) { msg.className = 'save-status-msg error'; msg.textContent = '✕ Network error.'; }
    }
};

window.openOrderReceiptDetails = function(encodedOrderJson) {
    try {
        const ord = JSON.parse(decodeURIComponent(encodedOrderJson));
        const modal = document.getElementById('orderReceiptModal');
        const titleEl = document.getElementById('receiptModalOrderNum');
        const bodyEl = document.getElementById('receiptModalBody');
        const trackBtn = document.getElementById('receiptModalTrackBtn');
        if (!modal || !bodyEl) return;

        titleEl.textContent = `Order Receipt: ${ord.order_number}`;
        if (trackBtn) trackBtn.href = ord.track_url;

        let itemsHtml = '';
        ord.items.forEach(it => {
            itemsHtml += `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #f1f5f9;">
                    <div>
                        <div style="font-weight:700; font-size:13.5px; color:var(--apple-dark);">${it.product_name}</div>
                        <div style="font-size:12px; color:var(--apple-gray);">${it.variant_specs || ''}</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700; font-size:13.5px; color:var(--apple-dark);">${it.formatted_line}</div>
                        <div style="font-size:11.5px; color:var(--apple-gray);">${it.quantity} &times; ${it.formatted_unit}</div>
                    </div>
                </div>
            `;
        });

        bodyEl.innerHTML = `
            <div style="margin-bottom:16px;">
                <div style="font-size:12px; color:var(--apple-gray);">Placed on: <strong>${ord.created_at}</strong></div>
                <div style="font-size:12px; color:var(--apple-gray); margin-top:2px;">Payment: <strong>${ord.is_coins_paid ? 'Prestige Coins' : 'Cash on Delivery (COD)'}</strong></div>
                <div style="font-size:12px; color:var(--apple-gray); margin-top:2px;">Fulfillment Status: <strong>${ord.order_status}</strong></div>
            </div>
            <div style="margin-bottom:16px;">
                <div style="font-size:12px; font-weight:800; text-transform:uppercase; color:var(--apple-gray); margin-bottom:6px;">Line Items</div>
                ${itemsHtml}
            </div>
            <div style="background:#f8fafc; border-radius:10px; padding:14px; margin-top:16px;">
                <div style="display:flex; justify-content:space-between; font-size:13px; color:#475569; margin-bottom:4px;">
                    <span>Delivery &amp; Insured Transit:</span>
                    <span>${ord.delivery_fee === 0 ? 'FREE' : '₱' + ord.delivery_fee.toFixed(2)}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:16px; font-weight:800; color:var(--apple-dark); border-top:1px solid #e2e8f0; padding-top:8px;">
                    <span>Total Amount:</span>
                    <span>${ord.formatted_total}</span>
                </div>
            </div>
        `;

        modal.classList.add('show');
    } catch (e) {
        console.error('[Receipt Modal Error]', e);
    }
};

window.closeOrderReceiptModal = function(e) {
    if (e && e.target && e.target !== e.currentTarget) return;
    const modal = document.getElementById('orderReceiptModal');
    if (modal) modal.classList.remove('show');
};

// Auto-run if on account.php
document.addEventListener('DOMContentLoaded', () => {
    if (window.ACCOUNT_PAGE_DATA) {
        loadAccountData();
        const initialTab = window.ACCOUNT_PAGE_DATA.initial_tab || 'orders';
        if (initialTab === 'orders') {
            loadAccountOrders('all');
        }
    }
});
