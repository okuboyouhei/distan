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
			manifest: null,

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
