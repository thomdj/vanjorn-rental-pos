/**
 * VanPOS Product Gallery – carousel & lightbox wiring
 *
 * Reads the full-size image URLs from the data-images attribute on the
 * #vanposProductGallery element (JSON array set by PHP). Uses the same
 * slide/dot/lightbox pattern as the in-plugin van-details gallery.
 *
 * Depends on vanposOpenLightbox() from the VanPOS app.js bundle.
 *
 * Migrated from child theme to plugin (frontend/js/).
 *
 * @package VJ_Rental_POS
 */
(function () {
	'use strict';

	var hero = document.getElementById( 'vanposProductGallery' );
	if ( ! hero ) return;

	var images;
	try {
		images = JSON.parse( hero.getAttribute( 'data-images' ) || '[]' );
	} catch ( e ) {
		images = [];
	}

	// Single image — just wire lightbox click.
	if ( images.length < 2 ) {
		if ( hero && images.length === 1 ) {
			hero.style.cursor = 'pointer';
			hero.addEventListener( 'click', function () {
				if ( typeof vanposOpenLightbox === 'function' ) vanposOpenLightbox( images, 0 );
			});
		}
		return;
	}

	// Multi-image carousel.
	var slides  = hero.querySelectorAll( '.vanpos-gallery-slide' );
	var dots    = hero.querySelectorAll( '.vanpos-gallery-dot' );
	var current = 0;
	var total   = slides.length;

	function preload( index ) {
		[ -1, 0, 1 ].forEach( function ( offset ) {
			var idx = ( index + offset + total ) % total;
			var img = slides[ idx ].querySelector( 'img' );
			if ( img && ! img.getAttribute( 'src' ) ) img.src = img.dataset.src;
		});
	}
	preload( 0 );

	function goTo( index ) {
		current = ( index + total ) % total;
		preload( current );
		for ( var i = 0; i < total; i++ ) {
			slides[ i ].classList.toggle( 'active', i === current );
			dots[ i ].classList.toggle( 'active', i === current );
		}
	}

	hero.querySelector( '.vanpos-gallery-prev' ).addEventListener( 'click', function ( e ) { e.stopPropagation(); goTo( current - 1 ); });
	hero.querySelector( '.vanpos-gallery-next' ).addEventListener( 'click', function ( e ) { e.stopPropagation(); goTo( current + 1 ); });

	dots.forEach( function ( dot ) {
		dot.addEventListener( 'click', function ( e ) { e.stopPropagation(); goTo( parseInt( dot.dataset.index, 10 ) ); });
	});

	// Lightbox on slide click.
	slides.forEach( function ( slide ) {
		slide.addEventListener( 'click', function () {
			if ( typeof vanposOpenLightbox === 'function' ) vanposOpenLightbox( images, current );
		});
	});
})();
