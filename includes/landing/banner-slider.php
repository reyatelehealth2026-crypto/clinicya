<?php
/**
 * Banner Slider Component - Landing Page
 * แสดงโปสเตอร์/แบนเนอร์แบบสไลด์
 */

$banners = $bannerService->getActiveBanners(10);
if (empty($banners)) return;
?>

<!-- Banner Slider Section -->
<section class="banner-slider-section">
    <div class="banner-slider" id="bannerSlider">
        <div class="slider-track">
            <?php foreach ($banners as $index => $banner): ?>
            <div class="slide <?= $index === 0 ? 'active' : '' ?>">
                <?php if (!empty($banner['link_url'])): ?>
                <a href="<?= htmlspecialchars($banner['link_url']) ?>" 
                   <?= $banner['link_type'] === 'external' ? 'target="_blank" rel="noopener"' : '' ?>>
                <?php endif; ?>
                
                <img src="<?= htmlspecialchars($banner['image_url']) ?>" 
                     alt="<?= htmlspecialchars($banner['title'] ?: 'โปรโมชั่น') ?>"
                     loading="<?= $index === 0 ? 'eager' : 'lazy' ?>">
                
                <?php if (!empty($banner['title'])): ?>
                <div class="slide-caption">
                    <span><?= htmlspecialchars($banner['title']) ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($banner['link_url'])): ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (count($banners) > 1): ?>
        <!-- Navigation Arrows -->
        <button class="slider-nav prev" onclick="slideNav(-1)" aria-label="Previous">
            <i class="fas fa-chevron-left"></i>
        </button>
        <button class="slider-nav next" onclick="slideNav(1)" aria-label="Next">
            <i class="fas fa-chevron-right"></i>
        </button>
        
        <!-- Dots Indicator -->
        <div class="slider-dots">
            <?php foreach ($banners as $index => $banner): ?>
            <button class="dot <?= $index === 0 ? 'active' : '' ?>" 
                    onclick="goToSlide(<?= $index ?>)" 
                    aria-label="Go to slide <?= $index + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
/* Banner Slider Styles */
.banner-slider-section {
    background: #f8fafc;
    padding: 0;
}

.banner-slider {
    position: relative;
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    overflow: hidden;
    border-radius: 0;
}

@media (min-width: 768px) {
    .banner-slider {
        border-radius: 16px;
        margin: 16px auto;
    }
    .banner-slider-section {
        padding: 8px 16px;
    }
}

.slider-track {
    display: flex;
    transition: transform 0.5s ease-in-out;
}

.slide {
    min-width: 100%;
    position: relative;
}

.slide img {
    width: 100%;
    aspect-ratio: 16/7;
    object-fit: contain;
    display: block;
    background: #f8fafc;
}

@media (max-width: 767px) {
    .slide img {
        aspect-ratio: 16/9;
    }
}

.slide-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 16px 20px;
    background: linear-gradient(transparent, rgba(0,0,0,0.7));
    color: white;
}

.slide-caption span {
    font-size: 1rem;
    font-weight: 600;
}

/* Navigation Arrows */
.slider-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,0.9);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #374151;
    font-size: 14px;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    z-index: 10;
}

.slider-nav:hover {
    background: white;
    transform: translateY(-50%) scale(1.1);
}

.slider-nav.prev { left: 12px; }
.slider-nav.next { right: 12px; }

@media (max-width: 767px) {
    .slider-nav {
        width: 32px;
        height: 32px;
        font-size: 12px;
    }
    .slider-nav.prev { left: 8px; }
    .slider-nav.next { right: 8px; }
}

/* Dots Indicator */
.slider-dots {
    position: absolute;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 8px;
    z-index: 10;
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.5);
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    padding: 0;
}

.dot.active {
    background: white;
    width: 24px;
    border-radius: 4px;
}

.dot:hover {
    background: rgba(255,255,255,0.8);
}
</style>

<script>
(function() {
    let currentSlide = 0;
    const slides = document.querySelectorAll('#bannerSlider .slide');
    const dots = document.querySelectorAll('#bannerSlider .dot');
    const track = document.querySelector('#bannerSlider .slider-track');
    const totalSlides = slides.length;
    let autoSlideInterval;
    
    if (totalSlides <= 1) return;
    
    function updateSlider() {
        track.style.transform = `translateX(-${currentSlide * 100}%)`;
        dots.forEach((dot, i) => dot.classList.toggle('active', i === currentSlide));
    }
    
    window.slideNav = function(direction) {
        currentSlide = (currentSlide + direction + totalSlides) % totalSlides;
        updateSlider();
        resetAutoSlide();
    };
    
    window.goToSlide = function(index) {
        currentSlide = index;
        updateSlider();
        resetAutoSlide();
    };
    
    function autoSlide() {
        currentSlide = (currentSlide + 1) % totalSlides;
        updateSlider();
    }
    
    function resetAutoSlide() {
        clearInterval(autoSlideInterval);
        autoSlideInterval = setInterval(autoSlide, 5000);
    }
    
    // Start auto-slide
    autoSlideInterval = setInterval(autoSlide, 5000);
    
    // Touch/Swipe support
    let touchStartX = 0;
    let touchEndX = 0;
    
    const slider = document.getElementById('bannerSlider');
    slider.addEventListener('touchstart', e => touchStartX = e.changedTouches[0].screenX, {passive: true});
    slider.addEventListener('touchend', e => {
        touchEndX = e.changedTouches[0].screenX;
        const diff = touchStartX - touchEndX;
        if (Math.abs(diff) > 50) {
            slideNav(diff > 0 ? 1 : -1);
        }
    }, {passive: true});
})();
</script>
