<?php
/**
 * Public Customer Storefront Index
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 */

declare(strict_types=1);

$pageTitle = 'Official Store — Authentic Apple Devices, Macs & Accessories';
require_once __DIR__ . '/includes/store_header.php';
?>

<main class="store-main">
    <div class="store-container">
        
        <!-- ====================================================================
             1. PROMOTIONAL HERO BANNER CAROUSEL
             ==================================================================== -->
        <section class="hero-carousel" id="heroCarousel">
            <div class="carousel-slides" id="carouselSlides">
                <!-- Slide 1: iPhone 16 Pro -->
                <div class="carousel-slide carousel-slide-1">
                    <div class="slide-content">
                        <span class="slide-badge">Now Available in Desert Titanium</span>
                        <h2 class="slide-title">iPhone 16 Pro &amp; Pro Max</h2>
                        <p class="slide-subtitle">
                            Powered by the A18 Pro chip. Titanium design with thinner borders and Camera Control button.
                        </p>
                        <div class="slide-actions">
                            <button type="button" class="btn-apple-primary" onclick="applySearchChip('iPhone 16 Pro')">
                                Shop iPhone 16 Pro &rarr;
                            </button>
                            <button type="button" class="btn-apple-ghost" onclick="applyCategoryFilterByName('iPhone')">
                                All iPhone Models
                            </button>
                        </div>
                    </div>
                    <div class="slide-image-art">
                        <svg viewBox="0 0 300 400" width="260" height="340">
                            <rect x="50" y="30" width="200" height="340" rx="36" fill="#2d2926" stroke="#bfa88f" stroke-width="4"/>
                            <rect x="62" y="44" width="176" height="312" rx="26" fill="#0c0a09"/>
                            <rect x="115" y="52" width="70" height="18" rx="9" fill="#000"/>
                            <circle cx="150" cy="200" r="48" fill="none" stroke="#bfa88f" stroke-width="2" stroke-dasharray="4 4"/>
                            <text x="150" y="205" text-anchor="middle" fill="#bfa88f" font-size="14" font-weight="700">A18 PRO</text>
                        </svg>
                    </div>
                </div>

                <!-- Slide 2: MacBook Air M3 -->
                <div class="carousel-slide carousel-slide-2">
                    <div class="slide-content">
                        <span class="slide-badge" style="background:rgba(59,130,246,0.25);">Lean. Mean. M3 Machine.</span>
                        <h2 class="slide-title">MacBook Air with M3</h2>
                        <p class="slide-subtitle">
                            Impossibly thin. Blazingly fast. Up to 18 hours of battery life with Liquid Retina display.
                        </p>
                        <div class="slide-actions">
                            <button type="button" class="btn-apple-primary" onclick="applySearchChip('MacBook Air M3')">
                                Explore MacBooks &rarr;
                            </button>
                            <button type="button" class="btn-apple-ghost" onclick="applyCategoryFilterByName('MacBook')">
                                View Specs
                            </button>
                        </div>
                    </div>
                    <div class="slide-image-art">
                        <svg viewBox="0 0 340 300" width="300" height="260">
                            <rect x="40" y="50" width="260" height="170" rx="12" fill="#1e293b" stroke="#94a3b8" stroke-width="3"/>
                            <rect x="52" y="62" width="236" height="146" rx="6" fill="#0f172a"/>
                            <path d="M20 220 L320 220 L300 236 L40 236 Z" fill="#64748b"/>
                            <text x="170" y="140" text-anchor="middle" fill="#38bdf8" font-size="22" font-weight="800">Apple M3</text>
                        </svg>
                    </div>
                </div>

                <!-- Slide 3: AirPods Pro 2 -->
                <div class="carousel-slide carousel-slide-3">
                    <div class="slide-content">
                        <span class="slide-badge" style="background:rgba(255,255,255,0.2);">Adaptive Audio &amp; USB-C</span>
                        <h2 class="slide-title">AirPods Pro (2nd Gen)</h2>
                        <p class="slide-subtitle">
                            Up to 2x more Active Noise Cancellation. Transparency mode and Personalized Spatial Audio.
                        </p>
                        <div class="slide-actions">
                            <button type="button" class="btn-apple-primary" onclick="applySearchChip('AirPods Pro 2')">
                                Discover Audio &rarr;
                            </button>
                        </div>
                    </div>
                    <div class="slide-image-art">
                        <svg viewBox="0 0 280 280" width="240" height="240">
                            <rect x="60" y="70" width="160" height="140" rx="40" fill="#f8fafc" stroke="#e2e8f0" stroke-width="4"/>
                            <path d="M60 110 L220 110" stroke="#cbd5e1" stroke-width="2"/>
                            <circle cx="140" cy="150" r="4" fill="#22c55e"/>
                            <text x="140" y="180" text-anchor="middle" fill="#64748b" font-size="11" font-weight="700">H2 CHIP</text>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <button type="button" class="carousel-nav-btn carousel-prev" onclick="prevHeroSlide()" aria-label="Previous Slide">&larr;</button>
            <button type="button" class="carousel-nav-btn carousel-next" onclick="nextHeroSlide()" aria-label="Next Slide">&rarr;</button>

            <!-- Indicator Dots -->
            <div class="carousel-dots" id="carouselDots">
                <div class="carousel-dot active" onclick="goToHeroSlide(0)"></div>
                <div class="carousel-dot" onclick="goToHeroSlide(1)"></div>
                <div class="carousel-dot" onclick="goToHeroSlide(2)"></div>
            </div>
        </section>

        <!-- ====================================================================
             2. CATEGORY ICON BAR WITH QUICK FILTERS
             ==================================================================== -->
        <section class="category-bar-wrapper">
            <div class="category-bar" id="categoryBar">
                <div class="category-chip active" data-cat-id="all" onclick="selectCategory('all')">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    <span>All Apple Devices</span>
                    <span class="category-chip-count" id="countAll">...</span>
                </div>
                <!-- Dynamic categories loaded via store.js -->
            </div>
        </section>

        <!-- ====================================================================
             3. FLASH DEALS WITH 18H LIVE COUNTDOWN TIMER
             ==================================================================== -->
        <section class="flash-deals-section" id="flashDealsSection">
            <div class="flash-deals-header">
                <div class="flash-title-wrap">
                    <span class="flame-icon">🔥</span>
                    <h2 class="flash-title">Flash Deals</h2>
                    <span style="font-size:12px; color:var(--promo-orange); font-weight:700; background:var(--promo-orange-light); padding:2px 8px; border-radius:4px;">
                        Limited Stock Offers
                    </span>
                </div>

                <!-- 18h Countdown Clock -->
                <div class="flash-countdown">
                    <span>Ends In:</span>
                    <span class="countdown-box" id="flashHours">17</span>
                    <span>:</span>
                    <span class="countdown-box" id="flashMinutes">59</span>
                    <span>:</span>
                    <span class="countdown-box" id="flashSeconds">59</span>
                </div>
            </div>

            <!-- Flash Deal Products Grid -->
            <div class="flash-deals-carousel" id="flashDealsGrid">
                <!-- Loaded dynamically by store.js -->
            </div>
        </section>

        <!-- ====================================================================
             4. MAIN PRODUCT CATALOG
             ==================================================================== -->
        <section class="catalog-section" id="catalogSection">
            <div class="catalog-header">
                <div>
                    <h2 class="catalog-title" id="catalogTitle">Explore Apple Catalog</h2>
                    <p style="font-size:13px; color:var(--apple-gray); margin-top:2px;" id="catalogSubtitle">
                        Browse 100% genuine Apple devices with official local warranty
                    </p>
                </div>

                <div class="catalog-controls">
                    <!-- Condition Filter Tabs -->
                    <div class="condition-pill-bar">
                        <button type="button" class="condition-pill active" onclick="setConditionFilter('all', this)">All</button>
                        <button type="button" class="condition-pill" onclick="setConditionFilter('brand_new', this)">Brand New</button>
                        <button type="button" class="condition-pill" onclick="setConditionFilter('refurbished', this)">Refurbished</button>
                        <button type="button" class="condition-pill" onclick="setConditionFilter('open_box', this)">Open Box</button>
                    </div>

                    <!-- Sort Select -->
                    <select class="sort-select" id="catalogSortSelect" onchange="setSortOrder(this.value)">
                        <option value="popular">Sort: Most Popular</option>
                        <option value="price_asc">Price: Low to High</option>
                        <option value="price_desc">Price: High to Low</option>
                        <option value="newest">Newest Arrivals</option>
                    </select>
                </div>
            </div>

            <!-- Main Dynamic Grid -->
            <div class="product-grid" id="productGrid">
                <!-- Dynamically populated by store.js -->
            </div>

            <!-- Empty State / Loading Indicator -->
            <div id="catalogLoading" style="text-align:center; padding:48px 0; color:var(--apple-gray);">
                Loading genuine Apple products...
            </div>
        </section>

    </div>
</main>

<?php
require_once __DIR__ . '/includes/store_footer.php';
?>