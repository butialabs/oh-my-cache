/**
 * Oh My Cache!
 */
( function () {
	'use strict';

	var settings = window.OhMyCache || {};

	/**
	 * Show one tab panel and mark its nav item active.
	 *
	 * @param {string} id Tab id.
	 */
	function showTab( id ) {
		var panels = document.querySelectorAll( '[data-omc-panel]' );
		var found = false;

		panels.forEach( function ( panel ) {
			var match = panel.getAttribute( 'data-omc-panel' ) === id;
			panel.hidden = ! match;
			found = found || match;
		} );

		if ( ! found ) {
			return;
		}

		document.querySelectorAll( '[data-omc-tab]' ).forEach( function ( tab ) {
			tab.classList.toggle( 'nav-tab-active', tab.getAttribute( 'data-omc-tab' ) === id );
		} );

		// Keep the address bar honest so a reload or a bookmark lands on the same tab.
		if ( window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'tab', id );
			window.history.replaceState( {}, '', url.toString() );
		}
	}

	/**
	 * Show only the block belonging to the selected local driver.
	 */
	function syncDriverBlocks() {
		var checked = document.querySelector( '.omc-driver-choice:checked' );
		var value = checked ? checked.value : 'none';

		document.querySelectorAll( '[data-omc-driver-block]' ).forEach( function ( block ) {
			block.hidden = block.getAttribute( 'data-omc-driver-block' ) !== value;
		} );
	}

	/**
	 * Show only the block belonging to the selected CDN provider.
	 */
	function syncCdnBlocks() {
		var checked = document.querySelector( '.omc-cdn-choice:checked' );
		var value = checked ? checked.value : 'cloudflare';

		document.querySelectorAll( '[data-omc-cdn-block]' ).forEach( function ( block ) {
			block.hidden = block.getAttribute( 'data-omc-cdn-block' ) !== value;
		} );
	}

	/**
	 * Ask the server to test one driver and report inline.
	 *
	 * @param {HTMLElement} button The clicked button.
	 */
	function testDriver( button ) {
		var driver = button.getAttribute( 'data-driver' );
		var output = button.parentNode.querySelector( '.omc-test-result' );

		if ( ! output ) {
			output = document.createElement( 'span' );
			output.className = 'omc-test-result';
			button.parentNode.appendChild( output );
		}

		button.disabled = true;
		output.textContent = '…';
		output.className = 'omc-test-result';

		var body = new URLSearchParams();
		body.append( 'action', 'oh_my_cache_test_driver' );
		body.append( 'nonce', settings.nonce || '' );
		body.append( 'driver', driver );

		window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			var data = ( payload && payload.data ) || {};
			var message = data.message || 'Unexpected response.';

			if ( data.hint ) {
				message += ' ' + data.hint;
			}

			// textContent, never innerHTML: this string can originate from a remote API.
			output.textContent = ' ' + message;
			output.className = 'omc-test-result ' + ( payload && payload.success ? 'is-ok' : 'is-missing' );
		} ).catch( function () {
			output.textContent = ' Request failed.';
			output.className = 'omc-test-result is-missing';
		} ).finally( function () {
			button.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var tab = target.closest( '[data-omc-tab]' );

		if ( tab ) {
			var id = tab.getAttribute( 'data-omc-tab' );

			// Only intercept when the panel is on this page; otherwise let the link navigate.
			if ( document.querySelector( '[data-omc-panel="' + id + '"]' ) ) {
				event.preventDefault();
				showTab( id );
			}

			return;
		}

		if ( target.matches( '.omc-test' ) ) {
			event.preventDefault();
			testDriver( target );
			return;
		}

		if ( target.matches( '[name="do"][value="purge_all"]' ) ) {
			if ( ! window.confirm( 'Clear the entire cache?' ) ) {
				event.preventDefault();
			}
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( ! event.target || ! event.target.matches ) {
			return;
		}

		if ( event.target.matches( '.omc-driver-choice' ) ) {
			syncDriverBlocks();
		}

		if ( event.target.matches( '.omc-cdn-choice' ) ) {
			syncCdnBlocks();
		}
	} );

	syncDriverBlocks();
	syncCdnBlocks();
}() );
