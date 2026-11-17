<?php
/**
 * Interactive Device Product Display Page (PDP)
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

$productId = (int) ($_GET['id'] ?? 0);
if ($productId <= 0) {
    header('Location: index.php');
    exit;
}

$db = get_db();
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';

// Fetch product details strictly excluding internal cost_price
$stmt = $db->prepare("
    SELECT p.id, p.category_id, c.name AS category_name,
           p.sku, p.name, p.description, p.specs, p.color, p.storage,
           p.condition_type, p.warranty,
           p.selling_price, p.current_stock
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE p.id = :id
      AND p.deleted_at IS NULL
      AND p.status = 'active'
    LIMIT 1
");
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $pageTitle = 'Product Not Found';
    require_once __DIR__ . '/includes/store_header.php';
    echo '<div class="store-container" style="padding: 80px 20px; text-align:center;">
            <h2>Device Not Found or Discontinued</h2>
            <p style="color:var(--apple-gray); margin: 12px 0 24px;">The selected Apple hardware may have been sold out or unlisted.</p>
            <a href="index.php" class="btn-apple-primary">Back to Official Store</a>
          </div>';
    require_once __DIR__ . '/includes/store_footer.php';
    exit;
}

// Fetch complete media gallery
$stmtImages = $db->prepare("
    SELECT image_path, is_primary 
    FROM product_images 
    WHERE product_id = :id 
    ORDER BY is_primary DESC, display_order ASC, id ASC
");
$stmtImages->execute([':id' => $productId]);
$galleryImages = $stmtImages->fetchAll(PDO::FETCH_ASSOC);

$primaryImage = !empty($galleryImages[0]['image_path']) ? $galleryImages[0]['image_path'] : 'assets/images/placeholder-apple.svg';

// Related ecosystem items
$stmtRelated = $db->prepare("
    SELECT p.id, p.name, p.selling_price, p.storage, p.color,
           (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) AS image_path
    FROM products p
    WHERE p.category_id = :cat_id AND p.id != :self_id AND p.deleted_at IS NULL AND p.status = 'active'
    ORDER BY p.id DESC LIMIT 4
");
$stmtRelated->execute([':cat_id' => $product['category_id'], ':self_id' => $productId]);
$relatedProducts = $stmtRelated->fetchAll(PDO::FETCH_ASSOC);

$basePrice = (float) $product['selling_price'];
$formattedPrice = $currency . number_format($basePrice, 2);
$stock = (int) $product['current_stock'];
$pageTitle = $product['name'] . ' (' . ($product['storage'] ?: 'Apple') . ')';

require_once __DIR__ . '/includes/store_header.php';
?>

<div class="store-container pdp-wrapper">
    <!-- Breadcrumb Nav -->
    <nav class="breadcrumb-nav">
        <a href="index.php">Store</a>
        <span>&rsaquo;</span>
        <a href="index.php"><?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?></a>
        <span>&rsaquo;</span>
        <span><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></span>
    </nav>

    <!-- Main PDP 2-Column Grid -->
    <div class="pdp-grid">
        
        <!-- Left: Image Gallery -->
        <div class="pdp-gallery-column">
            <div class="main-image-frame" id="mainImageFrame">
                <img id="mainPdpImage" src="../<?= htmlspecialchars($primaryImage, ENT_QUOTES, 'UTF-8') ?>" 
                     alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                     onerror="this.src='../assets/images/logo.png'">
            </div>

            <!-- Thumbnail Switcher Row -->
            <div class="thumbnail-switcher-row">
                <?php if (!empty($galleryImages)): ?>
                    <?php foreach ($galleryImages as $idx => $img): ?>
                        <div class="thumb-btn <?= $idx === 0 ? 'active' : '' ?>" 
                             onclick="switchMainPdpImage('../<?= htmlspecialchars($img['image_path'], ENT_QUOTES, 'UTF-8') ?>', this)">
                            <img src="../<?= htmlspecialchars($img['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Thumbnail <?= $idx + 1 ?>" onerror="this.src='../assets/images/logo.png'">
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="thumb-btn active">
                        <img src="../assets/images/logo.png" alt="Primary Thumbnail">
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Configuration & Purchasing Panel -->
        <div class="pdp-info-column">
            <div>
                <span class="pdp-header-tag"><?= htmlspecialchars($product['condition_type'] ?: 'Brand New Sealed', ENT_QUOTES, 'UTF-8') ?></span>
                <h1 class="pdp-title" id="pdpTitle"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            </div>

            <!-- Price Display & Installment Info -->
            <div class="pdp-price-box">
                <div class="pdp-main-price" id="pdpPriceDisplay"><?= $formattedPrice ?></div>
                <div class="pdp-installment-text">
                    Or ₱<?= number_format($basePrice / 12, 2) ?>/mo for 12 mos. with 0% interest or pay instantly with Prestige Coins 🪙
                </div>
            </div>

            <!-- 1. Color Finish Swatches -->
            <div>
                <div class="config-group-title">
                    <span>Finish: <strong id="selectedColorLabel"><?= htmlspecialchars($product['color'] ?: 'Desert Titanium', ENT_QUOTES, 'UTF-8') ?></strong></span>
                </div>
                <div class="swatches-row" id="colorSwatchesRow">
                    <div class="color-swatch-item active" onclick="selectPdpColor('Desert Titanium', this)">
                        <span class="color-swatch-circle" style="background:#bfa88f;"></span>
                        <span>Desert Titanium</span>
                    </div>
                    <div class="color-swatch-item" onclick="selectPdpColor('Natural Titanium', this)">
                        <span class="color-swatch-circle" style="background:#9a9895;"></span>
                        <span>Natural Titanium</span>
                    </div>
                    <div class="color-swatch-item" onclick="selectPdpColor('White Titanium', this)">
                        <span class="color-swatch-circle" style="background:#f2f1ed;"></span>
                        <span>White Titanium</span>
                    </div>
                    <div class="color-swatch-item" onclick="selectPdpColor('Black Titanium', this)">
                        <span class="color-swatch-circle" style="background:#3b3a38;"></span>
                        <span>Black Titanium</span>
                    </div>
                </div>
            </div>

            <!-- 2. Storage Capacity Pills with Price Deltas -->
            <div>
                <div class="config-group-title">
                    <span>Storage Capacity: <strong id="selectedStorageLabel"><?= htmlspecialchars($product['storage'] ?: '256GB', ENT_QUOTES, 'UTF-8') ?></strong></span>
                </div>
                <div class="storage-pills-grid" id="storagePillsGrid">
                    <div class="storage-pill-btn" onclick="selectPdpStorage('128GB', 0, this)">
                        <span class="storage-pill-cap">128GB</span>
                        <span class="storage-pill-sub">Base Config</span>
                    </div>
                    <div class="storage-pill-btn active" onclick="selectPdpStorage('256GB', 6000, this)">
                        <span class="storage-pill-cap">256GB</span>
                        <span class="storage-pill-sub">+₱6,000.00</span>
                    </div>
                    <div class="storage-pill-btn" onclick="selectPdpStorage('512GB', 16000, this)">
                        <span class="storage-pill-cap">512GB</span>
                        <span class="storage-pill-sub">+₱16,000.00</span>
                    </div>
                    <div class="storage-pill-btn" onclick="selectPdpStorage('1TB', 28000, this)">
                        <span class="storage-pill-cap">1TB</span>
                        <span class="storage-pill-sub">+₱28,000.00</span>
                    </div>
                </div>
            </div>

            <!-- CTA Actions (Add to Bag / Buy Now) -->
            <div class="pdp-actions-row">
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="changePdpQty(-1)">-</button>
                    <span class="qty-display" id="pdpQtyDisplay">1</span>
                    <button type="button" class="qty-btn" onclick="changePdpQty(1)">+</button>
                </div>
                <button type="button" class="btn-add-bag" onclick="executePdpAddToBag()">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                    Add to Bag
                </button>
                <button type="button" class="btn-buy-now" onclick="executePdpBuyNow()">
                    Buy Now
                </button>
            </div>

            <!-- 4-Pillar Trust Highlights -->
            <div class="pdp-trust-mini">
                <div class="mini-trust-item">
                    <span>🛡️</span> 1-Year Official Apple Warranty
                </div>
                <div class="mini-trust-item">
                    <span>🇵🇭</span> 100% NTC Approved Local Stock
                </div>
                <div class="mini-trust-item">
                    <span>🚚</span> Free Nationwide Insured Transit
                </div>
                <div class="mini-trust-item">
                    <span>🪙</span> COD &amp; Prestige Coins Accepted
                </div>
            </div>
        </div>
    </div>

    <!-- 5-Part Expandable Tech Specs Accordion -->
    <section class="specs-section">
        <h2 class="specs-section-title">Technical Specifications &amp; Inclusions</h2>
        
        <div class="accordion-item active">
            <button type="button" class="accordion-header" onclick="togglePdpAccordion(this)">
                <span>Display &amp; Engineering</span>
                <span class="accordion-icon">&#9662;</span>
            </button>
            <div class="accordion-body">
                <div class="specs-dl">
                    <div class="specs-dt">Display Type</div>
                    <div class="specs-dd">Super Retina XDR display with ProMotion technology (1Hz - 120Hz adaptive refresh)</div>
                    <div class="specs-dt">Brightness</div>
                    <div class="specs-dd">1,000 nits max brightness (typical); 1,600 nits peak brightness (HDR); 2,000 nits peak brightness (outdoor)</div>
                    <div class="specs-dt">Durability</div>
                    <div class="specs-dd">Latest-generation Ceramic Shield front, textured matte glass back, aerospace-grade titanium frame</div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="togglePdpAccordion(this)">
                <span>Processor &amp; Architecture</span>
                <span class="accordion-icon">&#9662;</span>
            </button>
            <div class="accordion-body">
                <div class="specs-dl">
                    <div class="specs-dt">Chipset</div>
                    <div class="specs-dd">A18 Pro chip with 6-core CPU (2 performance and 4 efficiency cores)</div>
                    <div class="specs-dt">Graphics &amp; Ray Tracing</div>
                    <div class="specs-dd">6-core GPU with 2x faster hardware-accelerated ray tracing compared to A17 Pro</div>
                    <div class="specs-dt">Neural Engine</div>
                    <div class="specs-dd">16-core Neural Engine optimized for high-performance machine learning &amp; computational photography</div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="togglePdpAccordion(this)">
                <span>Pro Camera System</span>
                <span class="accordion-icon">&#9662;</span>
            </button>
            <div class="accordion-body">
                <div class="specs-dl">
                    <div class="specs-dt">Main Sensor</div>
                    <div class="specs-dd">48MP Fusion: 24 mm, ƒ/1.78 aperture, second-generation sensor-shift optical image stabilization</div>
                    <div class="specs-dt">Telephoto</div>
                    <div class="specs-dd">12MP 5x Telephoto: 120 mm, ƒ/2.8 aperture, 3D sensor-shift optical image stabilization and autofocus</div>
                    <div class="specs-dt">Video Recording</div>
                    <div class="specs-dd">4K 120 fps Dolby Vision recording, ProRes video, Action mode, and Studio-quality 4-mic array</div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="togglePdpAccordion(this)">
                <span>Battery &amp; Charging</span>
                <span class="accordion-icon">&#9662;</span>
            </button>
            <div class="accordion-body">
                <div class="specs-dl">
                    <div class="specs-dt">Video Playback</div>
                    <div class="specs-dd">Up to 29 hours of continuous video playback</div>
                    <div class="specs-dt">Charging Speeds</div>
                    <div class="specs-dd">Fast-charge capable: Up to 50% charge in ~30 minutes with 20W adapter or higher</div>
                    <div class="specs-dt">Wireless Charging</div>
                    <div class="specs-dd">MagSafe wireless charging up to 25W with 30W adapter; Qi2 wireless charging up to 15W</div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <button type="button" class="accordion-header" onclick="togglePdpAccordion(this)">
                <span>What's in the Box</span>
                <span class="accordion-icon">&#9662;</span>
            </button>
            <div class="accordion-body">
                <div class="specs-dl">
                    <div class="specs-dt">Package Contents</div>
                    <div class="specs-dd">
                        1x Apple Hardware Device with iOS 18<br>
                        1x USB-C Charge Cable (1m, woven design)<br>
                        1x Official Warranty Certificate &amp; Regulatory Documentation
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Related Products Ecosystem -->
    <?php if (!empty($relatedProducts)): ?>
        <section style="margin-bottom: 40px;">
            <h3 style="font-size:20px; font-weight:800; margin-bottom:16px; color:var(--apple-dark);">You Might Also Like</h3>
            <div class="flash-deals-carousel">
                <?php foreach ($relatedProducts as $rel): ?>
                    <div class="deal-card" onclick="window.location.href='product.php?id=<?= (int)$rel['id'] ?>'">
                        <div class="deal-img-wrap">
                            <img src="../<?= htmlspecialchars($rel['image_path'] ?: 'assets/images/placeholder-apple.svg', ENT_QUOTES, 'UTF-8') ?>" 
                                 alt="<?= htmlspecialchars($rel['name'], ENT_QUOTES, 'UTF-8') ?>"
                                 onerror="this.src='../assets/images/logo.png'">
                        </div>
                        <div class="deal-title"><?= htmlspecialchars($rel['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="deal-specs"><?= htmlspecialchars($rel['storage'] ?: 'Apple Genuine', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="deal-price-wrap">
                            <span class="deal-price"><?= $currency . number_format((float)$rel['selling_price'], 2) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<!-- Mobile Sticky Bottom Action Bar -->
<div class="mobile-sticky-pdp-bar">
    <div class="sticky-bar-info">
        <span class="sticky-bar-title"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="sticky-bar-price" id="mobileStickyPrice"><?= $formattedPrice ?></span>
    </div>
    <div class="sticky-bar-btns">
        <button type="button" class="sticky-btn-bag" onclick="executePdpAddToBag()">Add to Bag</button>
        <button type="button" class="sticky-btn-buy" onclick="executePdpBuyNow()">Buy Now</button>
    </div>
</div>

<script>
// PDP Interactive State
const PDP = {
    id: <?= (int)$product['id'] ?>,
    name: <?= json_encode($product['name']) ?>,
    basePrice: <?= $basePrice ?>,
    currentPrice: <?= $basePrice ?>,
    selectedColor: <?= json_encode($product['color'] ?: 'Desert Titanium') ?>,
    selectedStorage: <?= json_encode($product['storage'] ?: '256GB') ?>,
    image: <?= json_encode($primaryImage) ?>,
    currentStock: <?= (int)$product['current_stock'] ?>,
    quantity: 1,
    currency: <?= json_encode($currency) ?>
};

function switchMainPdpImage(src, element) {
    const mainImg = document.getElementById('mainPdpImage');
    if (mainImg) mainImg.src = src;
    document.querySelectorAll('.thumb-btn').forEach(btn => btn.classList.remove('active'));
    if (element) element.classList.add('active');
    PDP.image = src;
}

function selectPdpColor(colorName, element) {
    PDP.selectedColor = colorName;
    document.getElementById('selectedColorLabel').textContent = colorName;
    document.querySelectorAll('.color-swatch-item').forEach(el => el.classList.remove('active'));
    if (element) element.classList.add('active');
}

function selectPdpStorage(storage, priceDelta, element) {
    PDP.selectedStorage = storage;
    PDP.currentPrice = PDP.basePrice + priceDelta;
    
    document.getElementById('selectedStorageLabel').textContent = storage;
    document.querySelectorAll('.storage-pill-btn').forEach(el => el.classList.remove('active'));
    if (element) element.classList.add('active');

    const formatted = PDP.currency + Number(PDP.currentPrice).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('pdpPriceDisplay').textContent = formatted;
    const mobilePrice = document.getElementById('mobileStickyPrice');
    if (mobilePrice) mobilePrice.textContent = formatted;
}

function changePdpQty(delta) {
    if (delta > 0 && PDP.quantity >= PDP.currentStock) {
        showToast(`Cannot add more. Only ${PDP.currentStock} unit(s) available in stock.`, 'error');
        return;
    }
    PDP.quantity = Math.max(1, Math.min(PDP.currentStock, PDP.quantity + delta));
    document.getElementById('pdpQtyDisplay').textContent = PDP.quantity;
}

function togglePdpAccordion(headerElement) {
    const parent = headerElement.parentElement;
    parent.classList.toggle('active');
}

function executePdpAddToBag() {
    addToBag(PDP.id, PDP.name, PDP.currentPrice, PDP.image, PDP.selectedColor, PDP.selectedStorage, PDP.quantity, PDP.currentStock);
}

function executePdpBuyNow() {
    addToBag(PDP.id, PDP.name, PDP.currentPrice, PDP.image, PDP.selectedColor, PDP.selectedStorage, PDP.quantity, PDP.currentStock);
    setTimeout(() => {
        window.location.href = 'cart.php';
    }, 200);
}
</script>

<?php
require_once __DIR__ . '/includes/store_footer.php';
?>