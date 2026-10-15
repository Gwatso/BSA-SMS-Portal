/**
 * BSA Intranet: top stories slider and attendance chart sharing.
 * Slider: auto-advances every 7 s, pauses on hover/focus and with the pause button,
 * does not auto-advance when the reader prefers reduced motion; arrows, dots and swipe.
 * Share: draws the attendance chart as a picture and opens the phone's share sheet
 * (WhatsApp → BSA Staff group); where that is not possible the picture is downloaded.
 */
( function () {
	'use strict';
	document.documentElement.classList.add( 'bsi-js' );
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	Array.prototype.forEach.call( document.querySelectorAll( '[data-bsi-carousel]' ), function ( root ) {
		var track = root.querySelector( '.bsi-track' );
		var slides = Array.prototype.slice.call( root.querySelectorAll( '.bsi-slide' ) );
		var dots = Array.prototype.slice.call( root.querySelectorAll( '.bsi-dot' ) );
		var pause = root.querySelector( '.bsi-pause' );
		var i = 0, timer = null, held = false;
		var playing = ! reduce && slides.length > 1;

		function show( n ) {
			i = ( n + slides.length ) % slides.length;
			track.style.transform = 'translateX(' + ( -100 * i ) + '%)';
			slides.forEach( function ( s, k ) {
				var on = k === i;
				s.setAttribute( 'aria-hidden', on ? 'false' : 'true' );
				if ( on ) {
					s.removeAttribute( 'inert' );
				} else {
					s.setAttribute( 'inert', '' );
				}
			} );
			dots.forEach( function ( d, k ) {
				d.setAttribute( 'aria-current', k === i ? 'true' : 'false' );
			} );
		}
		function stop() {
			if ( timer ) {
				clearInterval( timer );
				timer = null;
			}
		}
		function start() {
			stop();
			if ( playing ) {
				timer = setInterval( function () {
					if ( ! held ) {
						show( i + 1 );
					}
				}, 7000 );
			}
		}
		function label() {
			if ( pause ) {
				pause.setAttribute( 'aria-label', playing ? pause.getAttribute( 'data-pause' ) : pause.getAttribute( 'data-play' ) );
				pause.textContent = playing ? '❚❚' : '▶';
			}
		}
		var prev = root.querySelector( '.bsi-prev' ), next = root.querySelector( '.bsi-next' );
		if ( prev ) {
			prev.addEventListener( 'click', function () { show( i - 1 ); start(); } );
		}
		if ( next ) {
			next.addEventListener( 'click', function () { show( i + 1 ); start(); } );
		}
		dots.forEach( function ( d, k ) {
			d.addEventListener( 'click', function () { show( k ); start(); } );
		} );
		if ( pause ) {
			pause.addEventListener( 'click', function () { playing = ! playing; label(); start(); } );
		}
		root.addEventListener( 'mouseenter', function () { held = true; } );
		root.addEventListener( 'mouseleave', function () { held = false; } );
		root.addEventListener( 'focusin', function () { held = true; } );
		root.addEventListener( 'focusout', function () { held = false; } );
		var x0 = null;
		root.addEventListener( 'touchstart', function ( e ) { x0 = e.touches[0].clientX; }, { passive: true } );
		root.addEventListener( 'touchend', function ( e ) {
			if ( null === x0 ) {
				return;
			}
			var dx = e.changedTouches[0].clientX - x0;
			x0 = null;
			if ( Math.abs( dx ) > 40 ) {
				show( dx < 0 ? i + 1 : i - 1 );
				start();
			}
		} );
		show( 0 );
		label();
		start();
	} );

	function roundRect( ctx, x, y, w, h, r ) {
		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}

	function draw( d ) {
		var rows = d.classes.length, rowH = 64, W = 1080, H = 330 + rows * rowH + 90;
		var c = document.createElement( 'canvas' );
		c.width = W;
		c.height = H;
		var g = c.getContext( '2d' ), font = '"Plus Jakarta Sans", "Segoe UI", Arial, sans-serif';
		g.fillStyle = '#ffffff';
		g.fillRect( 0, 0, W, H );
		g.fillStyle = '#1B3674';
		g.fillRect( 0, 0, W, 170 );
		g.fillStyle = '#ffffff';
		g.font = '700 40px ' + font;
		g.fillText( d.title, 60, 82 );
		g.font = '400 26px ' + font;
		g.fillText( 'Bindura Science Academy · Passion for Excellence', 60, 128 );
		g.fillStyle = '#1D212B';
		g.font = '700 84px ' + font;
		g.fillText( null === d.rate ? '–' : d.rate + '%', 60, 270 );
		g.font = '400 28px ' + font;
		g.fillStyle = '#343A48';
		g.fillText( d.attended + ' of ' + d.possible + ' attended · ' + d.absent + ' absent · ' + d.late + ' late', 300, 258 );
		d.classes.forEach( function ( k, n ) {
			var y = 330 + n * rowH, pct = k.possible ? Math.round( 100 * k.attended / k.possible ) : 0;
			g.fillStyle = '#1D212B';
			g.font = '600 28px ' + font;
			g.fillText( k.title, 60, y + 30 );
			g.fillStyle = '#DCE6F5';
			roundRect( g, 330, y + 10, 460, 24, 12 );
			g.fill();
			if ( k.marked && pct > 0 ) {
				g.fillStyle = '#376DBE';
				roundRect( g, 330, y + 10, Math.max( 24, 460 * pct / 100 ), 24, 12 );
				g.fill();
			}
			g.font = '400 26px ' + font;
			g.fillStyle = k.marked ? '#1D212B' : '#9A4A00';
			g.fillText( k.marked ? pct + '% (' + k.attended + '/' + k.possible + ')' : '⚠ Not marked', 810, y + 31 );
		} );
		g.fillStyle = '#6B7280';
		g.font = '400 22px ' + font;
		g.fillText( 'Present and late count as attended. From the BSA Intranet.', 60, H - 40 );
		return c;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.bsi-attendance' ), function ( card ) {
		var data = card.querySelector( '.bsi-att-data' ), btn = card.querySelector( '.bsi-share-image' );
		if ( ! data || ! btn || ! window.HTMLCanvasElement ) {
			return;
		}
		var d = JSON.parse( data.textContent );
		btn.hidden = false;
		btn.addEventListener( 'click', function () {
			draw( d ).toBlob( function ( blob ) {
				var file = new File( [ blob ], 'bsa-attendance.png', { type: 'image/png' } );
				if ( navigator.canShare && navigator.canShare( { files: [ file ] } ) ) {
					navigator.share( { files: [ file ], text: d.text } ).catch( function () {} );
					return;
				}
				var a = document.createElement( 'a' );
				a.href = URL.createObjectURL( blob );
				a.download = 'bsa-attendance.png';
				document.body.appendChild( a );
				a.click();
				a.remove();
				btn.insertAdjacentHTML( 'afterend', '<p class="bsi-muted bsi-small" role="status">Picture saved. Attach it in the WhatsApp group, or use "Send as WhatsApp text".</p>' );
			}, 'image/png' );
		} );
	} );
}() );
