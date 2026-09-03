/**
 * Distan admin.
 *
 * Registered on document.alpine:init is not used here: this file is loaded
 * before alpine.min.js via a dependency declaration, so the component is
 * defined on window before Alpine boots and picks it up.
 */
( function () {
	'use strict';

	var data = window.distanData || {};

	function post( action, payload ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', data.nonce );

		Object.keys( payload || {} ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// Copy helper shared by the marker snippets in the help modal and the
	// settings screen. navigator.clipboard needs a secure context (https or
	// localhost); on a plain-http dev host like http://site.local it is absent,
	// so fall back to a hidden textarea + execCommand, which works there too.
	function distanCopyFallback( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {}
		document.body.removeChild( ta );
	}

	window.distanCopy = function ( text ) {
		if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).catch( function () {
				distanCopyFallback( text );
			} );
			return;
		}
		distanCopyFallback( text );
	};

	window.distanAdmin = function () {
		return {
			envResults: [],
			envLoading: false,
			envError: '',
			envUsable: null,

			batchSize: 5,
			genRunning: false,
			genError: '',
			genIndex: 0,
			genTotal: 0,
			genAssets: 0,
			genErrors: [],
			manifest: data.manifest || null,

			dispatchEnabled: !! data.dispatchEnabled,
			lastDispatch: data.lastDispatch || 0,
			lastDispatchLabel: data.lastDispatchLabel || '',
			dispatching: false,
			dispatchError: '',

			active: 'env',

			init: function () {
				var self = this;

				if ( typeof window.IntersectionObserver !== 'function' ) {
					return;
				}

				var map = {
					'distan-env': 'env',
					'distan-generate': 'gen',
					'distan-settings': 'settings'
				};

				var observer = new window.IntersectionObserver( function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting && map[ entry.target.id ] ) {
							self.active = map[ entry.target.id ];
						}
					} );
				}, { rootMargin: '-30% 0px -60% 0px', threshold: 0 } );

				Object.keys( map ).forEach( function ( id ) {
					var el = document.getElementById( id );
					if ( el ) {
						observer.observe( el );
					}
				} );
			},

			percent: function () {
				if ( ! this.genTotal ) {
					return 0;
				}
				return Math.round( ( this.genIndex / this.genTotal ) * 100 );
			},

			startGeneration: function () {
				var self = this;

				self.genRunning = true;
				self.genError = '';
				self.genErrors = [];
				self.genIndex = 0;
				self.genTotal = 0;
				self.genAssets = 0;
				self.manifest = null;

				post( 'distan_start', {} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							self.genError =
								( json && json.data && json.data.message ) || 'Failed to start.';
							self.genRunning = false;
							return;
						}

						self.genTotal = json.data.total;

						if ( ! self.genTotal ) {
							self.genError = '生成対象が見つかりませんでした。';
							self.genRunning = false;
							return;
						}

						self.runBatch();
					} )
					.catch( function ( error ) {
						self.genError = String( error );
						self.genRunning = false;
					} );
			},

			runBatch: function () {
				var self = this;

				post( 'distan_batch', { size: self.batchSize } )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							self.genError =
								( json && json.data && json.data.message ) || 'Batch failed.';
							self.genRunning = false;
							return;
						}

						var d = json.data;

						self.genIndex = d.index;
						self.genTotal = d.total;
						self.genAssets = d.assets;
						self.genErrors = d.errors || [];

						if ( d.done ) {
							self.manifest = d.manifest || null;
							self.genRunning = false;

							// The template-export, take-up, and diff panels are
							// rendered server-side from the stored manifest, so a
							// first run leaves them a step behind until the page
							// reloads. Reload once here — the results view is
							// restored from the manifest on load, so nothing is
							// lost — instead of asking the operator to do it.
							if ( self.manifest ) {
								window.setTimeout( function () {
									window.location.reload();
								}, 600 );
							}

							return;
						}

						self.runBatch();
					} )
					.catch( function ( error ) {
						self.genError = String( error );
						self.genRunning = false;
					} );
			},

			statusLabel: function ( status ) {
				switch ( status ) {
					case 'ok':
						return 'OK';
					case 'warning':
						return '注意';
					case 'error':
						return 'NG';
					default:
						return status;
				}
			},

			runEnvCheck: function () {
				var self = this;

				self.envLoading = true;
				self.envError = '';

				post( 'distan_env_check', {} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							self.envError =
								( json && json.data && json.data.message ) ||
								( data.i18n && data.i18n.failed ) ||
								'Request failed.';
							return;
						}

						self.envResults = json.data.results || [];
						self.envUsable = !! json.data.usable;
					} )
					.catch( function ( error ) {
						self.envError = String( error );
					} )
					.finally( function () {
						self.envLoading = false;
					} );
			},

			dispatch: function () {
				var self = this;

				self.dispatching = true;
				self.dispatchError = '';

				post( 'distan_dispatch', {} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							self.dispatchError =
								( json && json.data && json.data.message ) ||
								( data.i18n && data.i18n.dispatchFailed ) ||
								'Dispatch failed.';
							return;
						}

						self.lastDispatch = json.data.dispatched_at || Math.floor( Date.now() / 1000 );
						self.lastDispatchLabel = json.data.dispatched_label || self.lastDispatchLabel;
					} )
					.catch( function ( error ) {
						self.dispatchError = String( error );
					} )
					.finally( function () {
						self.dispatching = false;
					} );
			},

			fmtTime: function ( unix ) {
				if ( ! unix ) {
					return '—';
				}
				var d = new Date( unix * 1000 );
				var p = function ( n ) {
					return ( n < 10 ? '0' : '' ) + n;
				};
				return (
					d.getFullYear() +
					'-' + p( d.getMonth() + 1 ) +
					'-' + p( d.getDate() ) +
					' ' + p( d.getHours() ) +
					':' + p( d.getMinutes() )
				);
			}
		};
	};

	// Alpine 3 resolves x-data="distanAdmin" against window, but
	// registering explicitly keeps it working if Alpine.data() is preferred
	// later.
	document.addEventListener( 'alpine:init', function () {
		if ( window.Alpine && typeof window.Alpine.data === 'function' ) {
			window.Alpine.data( 'distanAdmin', window.distanAdmin );
		}
	} );
} )();
