/* ws-chapters — map of Europe. Countries with chapters are filled gold; markers use official logos.
 * mode "countries": one marker per country (national logo, name, chapter count) linking to the country page.
 * mode "chapters":  one marker per chapter (chapter logo) linking to the chapter page.
 * Country shapes come from world-atlas (Natural Earth); no map tiles are loaded. */
(function () {
	'use strict';

	var EUROPE = [[35, -24], [70.5, 42]];
	var cfg = window.wsMapConfig || {};
	var atlasPromise = null;

	function loadAtlas() {
		if (!atlasPromise) {
			atlasPromise = fetch(cfg.atlas, { integrity: cfg.atlasIntegrity, mode: 'cors' })
				.then(function (r) { return r.json(); })
				.then(function (topo) { return trimWrapped(window.topojson.feature(topo, topo.objects.countries)); });
		}
		return atlasPromise;
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text) n.textContent = text;
		return n;
	}

	function countLabel(p, labels) {
		return p.count + ' ' + (p.count === 1 ? labels.chapter : labels.chapters);
	}

	/* The Widows Sons pin; its tip sits exactly on the location. */
	function pinIcon(width) {
		var height = Math.round(width * 172 / 127);
		return L.icon({
			iconUrl: cfg.pin,
			iconSize: [width, height],
			iconAnchor: [width / 2, height],
			popupAnchor: [0, -height + 6],
			tooltipAnchor: [0, -height + 4],
			className: 'ws-map-pin-icon'
		});
	}

	function countryTooltip(p, labels) {
		var tag = el('span', 'ws-map-country__label');
		tag.appendChild(el('strong', '', p.name));
		tag.appendChild(el('span', '', countLabel(p, labels)));
		return tag;
	}

	/* Shapes that cross the 180° meridian (Russia's far east, Fiji) would smear across the map as
	 * horizontal bands. Shift their western-hemisphere points by +360° so each ring stays continuous. */
	function trimWrapped(geo) {
		var fixRing = function (ring) {
			var east = ring.some(function (pt) { return pt[0] > 100; });
			var west = ring.some(function (pt) { return pt[0] < -100; });
			return east && west ? ring.map(function (pt) { return pt[0] < 0 ? [pt[0] + 360, pt[1]] : pt; }) : ring;
		};
		var fixPoly = function (poly) { return poly.map(fixRing); };
		geo.features.forEach(function (f) {
			var g = f.geometry;
			if (!g) return;
			if (g.type === 'MultiPolygon') {
				g.coordinates = g.coordinates.map(fixPoly);
			} else if (g.type === 'Polygon') {
				g.coordinates = fixPoly(g.coordinates);
			}
		});
		return geo;
	}

	function chapterPopup(p, labels) {
		var box = el('div', 'ws-map-popup');
		var logo = el('div', 'ws-map-popup__logo');
		if (p.logo) {
			var img = el('img');
			img.src = p.logo;
			img.alt = '';
			logo.appendChild(img);
		} else {
			logo.appendChild(el('div', 'ws-insignia ws-insignia--pending', labels.pending));
		}
		var text = el('div');
		text.appendChild(el('strong', '', p.name));
		var where = el('span');
		if (p.flag) {
			var flag = el('span', 'ws-flag fi fi-' + p.flag);
			flag.setAttribute('aria-hidden', 'true');
			where.appendChild(flag);
			where.appendChild(document.createTextNode(' '));
		}
		where.appendChild(document.createTextNode([p.city, p.country].filter(Boolean).join(' · ')));
		text.appendChild(where);
		var link = el('a', '', labels.profile + ' →');
		link.href = p.url;
		text.appendChild(link);
		box.appendChild(logo);
		box.appendChild(text);
		return box;
	}

	function init(node) {
		var mode = node.dataset.mode || 'chapters';
		var points = JSON.parse(node.dataset.points || '[]');
		var highlight = JSON.parse(node.dataset.highlight || '[]');
		var focus = JSON.parse(node.dataset.focus || 'null');
		var labels = JSON.parse(node.dataset.labels || '{}');
		var map = L.map(node, {
			scrollWheelZoom: false,
			zoomSnap: 0.25,
			maxBounds: [[20, -60], [80, 80]],
			minZoom: 2.5
		});
		map.attributionControl.setPrefix(false).addAttribution('Countries: <a href="https://www.naturalearthdata.com/">Natural Earth</a>');

		var bounds = [];
		points.forEach(function (p) {
			if (p.lat === null || p.lat === undefined || p.lng === null || p.lng === undefined) return;
			if (mode === 'countries') {
				L.marker([p.lat, p.lng], { icon: pinIcon(34), alt: p.name, riseOnHover: true })
					.bindTooltip(countryTooltip(p, labels), { direction: 'top', className: 'ws-map-tip ws-map-tip--country', opacity: 1 })
					.on('click keypress', function () { location.href = p.url; })
					.addTo(map);
			} else {
				L.marker([p.lat, p.lng], { icon: pinIcon(30), title: p.name, alt: p.name, riseOnHover: true }).bindPopup(chapterPopup(p, labels)).addTo(map);
			}
			bounds.push([p.lat, p.lng]);
		});

		if (focus) {
			map.setView(focus, 7);
		} else if (mode === 'chapters' && bounds.length > 1) {
			map.fitBounds(bounds, { padding: [60, 60], maxZoom: 7 });
		} else if (mode === 'chapters' && bounds.length === 1) {
			map.setView(bounds[0], 6);
		} else {
			map.fitBounds(EUROPE);
		}

		// Country shapes under the markers; members in gold, clickable in "countries" mode.
		var byShape = {};
		points.forEach(function (p) { if (p.shape) byShape[p.shape] = p; });
		loadAtlas().then(function (geo) {
			L.geoJSON(geo, {
				style: function (f) {
					var on = highlight.indexOf(String(f.id)) !== -1;
					return { className: on ? 'ws-map-land ws-map-land--member' : 'ws-map-land', weight: on ? 1 : 0.6 };
				},
				onEachFeature: function (f, l) {
					var p = byShape[String(f.id)];
					if (mode === 'countries' && p) {
						l.on('click', function () { location.href = p.url; });
						l.bindTooltip(countryTooltip(p, labels), { sticky: true, className: 'ws-map-tip ws-map-tip--country', opacity: 1 });
					} else if (f.properties && f.properties.name) {
						// Every other country still shows its name on hover.
						l.bindTooltip(f.properties.name, { sticky: true, className: 'ws-map-tip ws-map-tip--name', opacity: 1 });
					}
				}
			}).addTo(map).bringToBack();
		}).catch(function () { node.classList.add('ws-chapter-map--no-shapes'); });

		map.once('focus click', function () { map.scrollWheelZoom.enable(); });
		window.addEventListener('resize', function () { map.invalidateSize(); });
	}

	function boot() {
		if (!window.L) return;
		document.querySelectorAll('.ws-chapter-map').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
