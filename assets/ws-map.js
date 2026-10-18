/* ws-chapters — European chapter map (Leaflet). Markers use each chapter's official logo. */
(function () {
	'use strict';

	var EUROPE = [[34, -12], [71, 42]];

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text) n.textContent = text;
		return n;
	}

	function pinHtml(p) {
		var pin = el('span', 'ws-map-pin' + (p.logo ? '' : ' ws-map-pin--pending'));
		if (p.logo) {
			var img = el('img');
			img.src = p.logo;
			img.alt = '';
			pin.appendChild(img);
		}
		return pin.outerHTML;
	}

	function popup(p, labels) {
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
		var where = [p.city, p.country].filter(Boolean).join(' · ');
		if (where) text.appendChild(el('span', '', where));
		var link = el('a', '', labels.profile + ' →');
		link.href = p.url;
		text.appendChild(link);
		box.appendChild(logo);
		box.appendChild(text);
		return box;
	}

	function init(node) {
		var points = JSON.parse(node.dataset.points || '[]');
		var labels = JSON.parse(node.dataset.labels || '{}');
		var map = L.map(node, { scrollWheelZoom: false, worldCopyJump: true });

		// Standard OpenStreetMap tiles (no API key); darkened in CSS via .ws-map-tiles, markers untouched.
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 18,
			className: 'ws-map-tiles',
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		}).addTo(map);

		var bounds = [];
		points.forEach(function (p) {
			var marker = L.marker([p.lat, p.lng], {
				title: p.name,
				alt: p.name,
				icon: L.divIcon({ className: '', html: pinHtml(p), iconSize: [48, 48], iconAnchor: [24, 24], popupAnchor: [0, -24] })
			}).addTo(map);
			marker.bindPopup(popup(p, labels));
			bounds.push([p.lat, p.lng]);
		});

		if (bounds.length > 1) {
			map.fitBounds(bounds, { padding: [48, 48], maxZoom: 7 });
		} else if (bounds.length === 1) {
			map.setView(bounds[0], 6);
		} else {
			map.fitBounds(EUROPE);
		}

		// Scroll-zoom only after the visitor interacts, so the page still scrolls past the map.
		map.once('focus click', function () { map.scrollWheelZoom.enable(); });
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
