/**
 * EcoDom Quote calculator. Vanilla JS, no dependencies.
 * All prices are computed on the server; this script only collects input and renders the answer.
 */
( function () {
	'use strict';

	var cfg = window.edqConfig;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	/* ---------- Attribution (utm_*, gclid, fbclid) ---------- */

	function readCookie( name ) {
		var parts = document.cookie ? document.cookie.split( '; ' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			var eq = parts[ i ].indexOf( '=' );
			if ( parts[ i ].slice( 0, eq ) === name ) {
				try {
					// PHP setcookie() encodes spaces as "+".
					return JSON.parse( decodeURIComponent( parts[ i ].slice( eq + 1 ).replace( /\+/g, ' ' ) ) ) || {};
				} catch ( e ) {
					return {};
				}
			}
		}
		return {};
	}

	function captureAttribution() {
		var params = new URLSearchParams( window.location.search );
		var found = {};
		var any = false;
		cfg.cookie.keys.forEach( function ( key ) {
			var v = params.get( key );
			if ( v ) {
				found[ key ] = v.slice( 0, 255 );
				any = true;
			}
		} );
		if ( any ) {
			var expires = new Date( Date.now() + cfg.cookie.days * 864e5 ).toUTCString();
			document.cookie =
				cfg.cookie.name + '=' + encodeURIComponent( JSON.stringify( found ) ) +
				'; expires=' + expires + '; path=' + cfg.cookie.path + '; SameSite=Lax' +
				( window.location.protocol === 'https:' ? '; Secure' : '' );
			return found;
		}
		return readCookie( cfg.cookie.name );
	}

	var attribution = captureAttribution();

	/* ---------- Helpers ---------- */

	function track( event, params ) {
		window.dataLayer = window.dataLayer || [];
		var payload = { event: event, currency: cfg.currency };
		Object.keys( params || {} ).forEach( function ( k ) {
			payload[ k ] = params[ k ];
		} );
		window.dataLayer.push( payload );
	}

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function num( v ) {
		var n = parseFloat( String( v || '' ).replace( ',', '.' ).replace( /\s/g, '' ) );
		return isFinite( n ) ? n : 0;
	}

	function post( path, body ) {
		return fetch( cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
			body: JSON.stringify( body ),
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! res.ok ) {
					var err = new Error( ( json && json.message ) || t.error );
					err.status = res.status;
					throw err;
				}
				return json;
			} );
		} );
	}

	/* ---------- Phone mask +380 (XX) XXX-XX-XX ---------- */

	function phoneDigits( value ) {
		var d = String( value ).replace( /\D/g, '' );
		if ( d.indexOf( '380' ) === 0 ) {
			d = d.slice( 3 );
		} else if ( d.indexOf( '38' ) === 0 && d.length > 10 ) {
			d = d.slice( 2 );
		}
		// Ukrainian numbers are often typed with the trunk prefix: 067… -> 67….
		return d.replace( /^0+/, '' ).slice( 0, 9 );
	}

	function formatPhone( d ) {
		var out = '+380';
		if ( d.length ) {
			out += ' (' + d.slice( 0, 2 );
		}
		if ( d.length >= 2 ) {
			out += ')';
		}
		if ( d.length > 2 ) {
			out += ' ' + d.slice( 2, 5 );
		}
		if ( d.length > 5 ) {
			out += '-' + d.slice( 5, 7 );
		}
		if ( d.length > 7 ) {
			out += '-' + d.slice( 7, 9 );
		}
		return out;
	}

	function bindPhone( input ) {
		input.addEventListener( 'focus', function () {
			if ( ! input.value ) {
				input.value = '+380 ';
			}
		} );
		input.addEventListener( 'blur', function () {
			if ( ! phoneDigits( input.value ).length ) {
				input.value = '';
			}
		} );
		input.addEventListener( 'input', function ( e ) {
			// Let the user delete a formatting character without it reappearing.
			if ( e.inputType === 'deleteContentBackward' && /[\s()\-]$/.test( input.value ) ) {
				return;
			}
			input.value = formatPhone( phoneDigits( input.value ) );
		} );
	}

	/* ---------- Calculator instance ---------- */

	function Calc( root ) {
		this.root = root;
		this.data = JSON.parse( root.getAttribute( 'data-edq' ) || '{}' );
		this.form = root.querySelector( '[data-edq-calc]' );
		this.lead = root.querySelector( '[data-edq-lead]' );
		this.result = root.querySelector( '[data-edq-result]' );
		this.started = false;
		this.last = null; // Last successful request + response.
		this.init();
	}

	Calc.prototype.q = function ( sel ) {
		return this.root.querySelector( sel );
	};

	Calc.prototype.mode = function () {
		var checked = this.form.querySelector( '[name="mode"]:checked' ) || this.form.querySelector( '[name="mode"]' );
		return checked ? checked.value : 'roof';
	};

	Calc.prototype.model = function () {
		var id = parseInt( this.form.elements.model_id.value, 10 );
		return this.data.models.filter( function ( m ) {
			return m.id === id;
		} )[ 0 ] || null;
	};

	Calc.prototype.init = function () {
		var self = this;

		this.form.addEventListener( 'change', function ( e ) {
			if ( e.target.name === 'mode' ) {
				self.applyMode();
			}
			if ( e.target.name === 'model_id' ) {
				self.fillVariants();
			}
			if ( e.target.name === 'thickness' ) {
				self.fillCoatings();
			}
		} );

		var start = function () {
			if ( ! self.started ) {
				self.started = true;
				var m = self.model();
				track( 'calc_start', { calc_type: self.mode(), model: m ? m.title : '', sqm: 0, value: 0 } );
			}
		};
		this.form.addEventListener( 'input', start );
		this.form.addEventListener( 'change', start );

		this.q( '[data-edq-add]' ).addEventListener( 'click', function () {
			self.addSlope();
		} );

		this.form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			self.calculate();
		} );

		this.lead.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			self.submitLead();
		} );

		bindPhone( this.lead.querySelector( '[data-edq-phone]' ) );

		// Hidden fields: attribution and page URL.
		cfg.cookie.keys.forEach( function ( key ) {
			var input = self.lead.elements[ key ];
			if ( input && attribution[ key ] ) {
				input.value = attribution[ key ];
			}
		} );
		this.lead.elements.page_url.value = window.location.href.split( '#' )[ 0 ];

		this.addSlope();
		this.applyMode();
	};

	Calc.prototype.applyMode = function () {
		var mode = this.mode();
		var allowed = mode === 'fence' ? [ 'fence', 'profnastyl' ] : [ 'roof', 'profnastyl' ];
		var select = this.form.elements.model_id;
		var current = select.value;
		var models = this.data.models.filter( function ( m ) {
			return allowed.indexOf( m.type ) !== -1;
		} );

		// Rebuild options (hiding <option> is unreliable in Safari).
		var placeholder = select.querySelector( 'option[value=""]' );
		select.textContent = '';
		if ( placeholder && models.length > 1 ) {
			select.appendChild( placeholder );
		}
		models.forEach( function ( m ) {
			var o = el( 'option', '', m.title );
			o.value = String( m.id );
			select.appendChild( o );
		} );
		if ( models.some( function ( m ) {
			return String( m.id ) === current;
		} ) ) {
			select.value = current;
		} else if ( models.length === 1 ) {
			select.value = String( models[ 0 ].id );
		}

		this.root.querySelectorAll( '[data-edq-mode]' ).forEach( function ( s ) {
			s.hidden = s.getAttribute( 'data-edq-mode' ) !== mode;
		} );
		this.fillVariants();
		this.result.hidden = true;
	};

	Calc.prototype.fillVariants = function () {
		var m = this.model();
		var sel = this.form.elements.thickness;
		var prev = sel.value;
		sel.textContent = '';
		if ( m ) {
			Object.keys( m.variants ).sort().forEach( function ( th ) {
				var o = el( 'option', '', th );
				o.value = th;
				sel.appendChild( o );
			} );
			if ( m.variants[ prev ] ) {
				sel.value = prev;
			}
		}
		sel.disabled = ! m;
		this.fillCoatings();
	};

	Calc.prototype.fillCoatings = function () {
		var m = this.model();
		var sel = this.form.elements.coating;
		var prev = sel.value;
		var list = m && m.variants[ this.form.elements.thickness.value ] ? m.variants[ this.form.elements.thickness.value ] : [];
		sel.textContent = '';
		list.forEach( function ( c ) {
			var o = el( 'option', '', t.coatings[ c ] || c );
			o.value = c;
			sel.appendChild( o );
		} );
		if ( list.indexOf( prev ) !== -1 ) {
			sel.value = prev;
		}
		sel.disabled = ! list.length;
	};

	Calc.prototype.addSlope = function () {
		var self = this;
		var box = this.q( '[data-edq-slopes]' );
		if ( box.children.length >= 20 ) {
			return;
		}
		var node = this.q( '[data-edq-slope-tpl]' ).content.firstElementChild.cloneNode( true );
		var shape = node.querySelector( '[data-k="shape"]' );
		var onShape = function () {
			var trap = shape.value === 'trap';
			node.querySelector( '[data-only="trap"]' ).hidden = ! trap;
			var label = node.querySelector( '[data-label-rect]' );
			label.textContent = label.getAttribute( trap ? 'data-label-trap' : 'data-label-rect' );
		};
		shape.addEventListener( 'change', onShape );
		node.querySelector( '[data-edq-remove]' ).addEventListener( 'click', function () {
			node.remove();
			self.renumber();
		} );
		box.appendChild( node );
		this.renumber();
		onShape();
	};

	Calc.prototype.renumber = function () {
		var nodes = this.q( '[data-edq-slopes]' ).children;
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].querySelector( '.edq-slope__title' ).textContent = t.slope + ' ' + ( i + 1 );
			var rm = nodes[ i ].querySelector( '[data-edq-remove]' );
			rm.textContent = '×';
			rm.setAttribute( 'aria-label', t.remove + ' ' + ( i + 1 ) );
			rm.hidden = nodes.length === 1;
		}
	};

	Calc.prototype.payload = function () {
		var f = this.form.elements;
		var body = {
			nonce: cfg.nonce,
			mode: this.mode(),
			model_id: parseInt( f.model_id.value, 10 ) || 0,
			thickness: f.thickness.value,
			coating: f.coating.value,
		};
		if ( body.mode === 'fence' ) {
			body.fence = { length: num( f.fence_length.value ), height: num( f.fence_height.value ), post: f.fence_post ? f.fence_post.value : '' };
		} else {
			body.slopes = Array.prototype.map.call( this.q( '[data-edq-slopes]' ).children, function ( n ) {
				var v = function ( k ) {
					return n.querySelector( '[data-k="' + k + '"]' ).value;
				};
				return { shape: v( 'shape' ), a: num( v( 'a' ) ), b: num( v( 'b' ) ), h: num( v( 'h' ) ) };
			} );
		}
		return body;
	};

	Calc.prototype.setBusy = function ( btn, busy, label ) {
		if ( busy ) {
			btn.setAttribute( 'data-label', btn.textContent );
			btn.textContent = label;
		} else if ( btn.getAttribute( 'data-label' ) ) {
			btn.textContent = btn.getAttribute( 'data-label' );
		}
		btn.disabled = busy;
		btn.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	};

	Calc.prototype.calculate = function () {
		var self = this;
		var msg = this.q( '[data-edq-calc-msg]' );
		var btn = this.q( '[data-edq-calc-btn]' );
		var body = this.payload();
		msg.textContent = '';
		if ( ! body.model_id ) {
			msg.textContent = t.chooseModel;
			this.form.elements.model_id.focus();
			return;
		}
		this.setBusy( btn, true, t.calculating );
		post( '/calc', body )
			.then( function ( res ) {
				self.last = { body: body, res: res };
				self.render( res );
				track( 'calc_complete', { calc_type: res.mode, model: res.model_title, sqm: res.sqm, value: res.total } );
			} )
			.catch( function ( err ) {
				msg.textContent = err.message || t.error;
			} )
			.then( function () {
				self.setBusy( btn, false );
			} );
	};

	Calc.prototype.render = function ( r ) {
		var rows = this.q( '[data-edq-rows]' );
		rows.textContent = '';
		r.positions.forEach( function ( p ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'td', '', p.name ) );
			tr.appendChild( el( 'td', 'edq-num', p.qty_txt ) );
			tr.appendChild( el( 'td', 'edq-num', p.price_txt ) );
			tr.appendChild( el( 'td', 'edq-num', p.sum_txt ) );
			rows.appendChild( tr );
		} );

		var summary = this.q( '[data-edq-summary]' );
		summary.textContent = '';
		[
			[ t.sheetArea, r.sqm.toLocaleString( 'uk-UA' ) + ' ' + t.sqm ],
			[ t.usefulArea, r.useful_sqm.toLocaleString( 'uk-UA' ) + ' ' + t.sqm ],
			[ t.sheets, String( r.sheet_count ) ],
		].forEach( function ( pair ) {
			var item = el( 'div', 'edq-kpi' );
			item.appendChild( el( 'span', 'edq-kpi__label', pair[ 0 ] ) );
			item.appendChild( el( 'strong', 'edq-kpi__value', pair[ 1 ] ) );
			summary.appendChild( item );
		} );

		this.q( '[data-edq-range]' ).textContent = r.min_txt + ' – ' + r.max_txt;
		this.result.hidden = false;
		this.lead.hidden = false;
		this.q( '[data-edq-success]' ).hidden = true;
		this.result.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	};

	Calc.prototype.submitLead = function () {
		var self = this;
		var f = this.lead.elements;
		var msg = this.q( '[data-edq-lead-msg]' );
		var btn = this.q( '[data-edq-lead-btn]' );
		msg.textContent = '';

		if ( f.name.value.trim().length < 2 ) {
			msg.textContent = t.nameInvalid;
			f.name.focus();
			return;
		}
		if ( phoneDigits( f.phone.value ).length !== 9 ) {
			msg.textContent = t.phoneInvalid;
			f.phone.focus();
			return;
		}
		if ( ! this.last ) {
			return;
		}

		var lead = {
			name: f.name.value.trim(),
			phone: '+380' + phoneDigits( f.phone.value ),
			channel: ( this.lead.querySelector( '[name="channel"]:checked' ) || {} ).value || 'call',
			page_url: f.page_url.value,
			edq_website: f.edq_website.value,
			// Server render time: the server measures the fill time itself, client clock skew does not matter.
			edq_ts: cfg.serverTime,
		};
		cfg.cookie.keys.forEach( function ( key ) {
			if ( f[ key ] && f[ key ].value ) {
				lead[ key ] = f[ key ].value;
			}
		} );

		var body = Object.assign( {}, this.last.body, { lead: lead } );
		this.setBusy( btn, true, t.sending );
		post( '/lead', body )
			.then( function ( res ) {
				self.lead.hidden = true;
				var ok = self.q( '[data-edq-success]' );
				ok.hidden = false;
				ok.focus();
				track( 'calc_lead', {
					calc_type: self.last.res.mode,
					model: self.last.res.model_title,
					sqm: self.last.res.sqm,
					value: self.last.res.total,
					lead_id: res.lead_id || 0,
				} );
			} )
			.catch( function ( err ) {
				msg.textContent = err.message || t.error;
			} )
			.then( function () {
				self.setBusy( btn, false );
			} );
	};

	function boot() {
		document.querySelectorAll( '.edq-root[data-edq]' ).forEach( function ( root ) {
			if ( ! root.edq ) {
				root.edq = new Calc( root );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
