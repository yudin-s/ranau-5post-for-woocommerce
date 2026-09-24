(function () {
    'use strict';

    var config = window.RanauFivePost || {};
    var i18n = config.i18n || {};
    var state = {
        scriptLoading: null,
        map: null,
        ymaps3: null,
        modal: null,
        mapContainer: null,
        status: null,
        list: null,
        debounceTimer: null,
        suggestTimer: null,
        geolocationWatchId: null,
        geolocationTimer: null,
        lastBoundsKey: '',
        selectedPoint: null,
        points: [],
        searchSuggestions: [],
        selectorRenderQueued: false,
        markers: [],
        markersById: {},
        focusedPointId: '',
        activePopup: null,
        activePopupAnchor: null,
        shippingMethodGuardVersion: 0
    };

    function text(key, fallback) {
        return i18n[key] || fallback;
    }

    function loadYandexMaps() {
        if (window.ymaps3) {
            return window.ymaps3.ready.then(function () {
                state.ymaps3 = window.ymaps3;
                return window.ymaps3;
            });
        }

        if (state.scriptLoading) {
            return state.scriptLoading;
        }

        state.scriptLoading = new Promise(function (resolve, reject) {
            if (!config.yandexMapsKey) {
                reject(new Error(text('yandexKeyMissing', 'Не указан ключ JavaScript API Яндекс Карт.')));
                return;
            }

            var script = document.createElement('script');
            script.src = yandexMapsUrl();
            script.async = true;
            script.onload = function () {
                if (!window.ymaps3) {
                    reject(new Error(text('yandexLoadFailed', 'Не удалось загрузить Яндекс Карты.')));
                    return;
                }
                window.ymaps3.ready.then(function () {
                    state.ymaps3 = window.ymaps3;
                    resolve(window.ymaps3);
                }).catch(function () {
                    reject(new Error(text('yandexLoadFailed', 'Не удалось загрузить Яндекс Карты.')));
                });
            };
            script.onerror = function () {
                reject(new Error(text('yandexLoadFailed', 'Не удалось загрузить Яндекс Карты.')));
            };
            document.head.appendChild(script);
        });

        return state.scriptLoading;
    }

    function yandexMapsUrl() {
        return 'https://api-maps.yandex.ru/v3/?lang=ru_RU&apikey=' + encodeURIComponent(config.yandexMapsKey);
    }

    function ensureModal() {
        if (state.modal) {
            return state.modal;
        }

        var modal = document.createElement('div');
        modal.className = 'ranau-fivepost-modal';
        modal.innerHTML = [
            '<div class="ranau-fivepost-dialog" role="dialog" aria-modal="true">',
            '<div class="ranau-fivepost-toolbar">',
            '<div class="ranau-fivepost-title">' + escapeHtml(text('modalTitle', 'Выберите пункт выдачи 5Post')) + '</div>',
            '<button type="button" class="button ranau-fivepost-close" aria-label="' + escapeHtml(text('close', 'Закрыть')) + '">×</button>',
            '</div>',
            '<div class="ranau-fivepost-body">',
            '<div class="ranau-fivepost-map"></div>',
            '<aside class="ranau-fivepost-results" aria-label="' + escapeHtml(text('results', 'Пункты выдачи')) + '">',
            '<div class="ranau-fivepost-results-head">',
            '<span class="ranau-fivepost-results-count">0</span>',
            '</div>',
            '<div class="ranau-fivepost-results-list"></div>',
            '</aside>',
            '</div>',
            '<div class="ranau-fivepost-status" aria-live="polite"></div>',
            '</div>'
        ].join('');

        document.body.appendChild(modal);
        state.modal = modal;
        state.status = modal.querySelector('.ranau-fivepost-status');
        state.list = modal.querySelector('.ranau-fivepost-results-list');

        modal.querySelector('.ranau-fivepost-close').addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
        state.list.addEventListener('click', function (event) {
            var focusButton = event.target.closest('.ranau-fivepost-result-focus');
            var chooseButton = event.target.closest('.ranau-fivepost-result-choose');
            var item = event.target.closest('.ranau-fivepost-result');
            var point = item ? findPoint(item.getAttribute('data-point-id')) : null;

            if (!point) {
                return;
            }

            if (chooseButton) {
                selectPoint(point);
                return;
            }

            if (focusButton || item) {
                focusPoint(point);
            }
        });

        return modal;
    }

    function openModal() {
        var modal = ensureModal();
        modal.classList.add('is-open');
        setStatus(text('loading', 'Загружаем пункты выдачи...'));

        loadYandexMaps().then(function (ymaps3) {
            if (!state.map) {
                return createMap(ymaps3, modal.querySelector('.ranau-fivepost-map'));
            }
            scheduleVisiblePointsLoad();
            return null;
        }).then(function () {
            applySharedDeliveryCenter();
            loadVisiblePoints();
        }).catch(function (error) {
            setStatus(error.message);
        });
    }

    function closeModal(options) {
        options = options || {};
        if (!options.skipCenterPublish) {
            publishMapCenterOnClose();
        }
        if (state.modal) {
            state.modal.classList.remove('is-open');
        }
    }

    function createMap(ymaps3, container) {
        var center = centerFromSharedDeliveryCenter() || toYMaps3Coordinates(config.defaultCenter || [55.755864, 37.617698]);
        state.mapContainer = container;

        state.map = new ymaps3.YMap(container, {
            location: {
                center: center,
                zoom: 11
            },
            margin: [60, 20, 20, 20]
        });

        state.map.addChild(new ymaps3.YMapDefaultSchemeLayer({}));
        state.map.addChild(new ymaps3.YMapDefaultFeaturesLayer({}));

        if (ymaps3.YMapListener) {
            state.map.addChild(new ymaps3.YMapListener({
                onUpdate: scheduleVisiblePointsLoad
            }));
        }

        createMapOverlayControls(container);

        return setupYandexControls(ymaps3);
    }

    function sharedDeliveryCenter() {
        if (!window.RanauDeliveryCenter || typeof window.RanauDeliveryCenter.get !== 'function') {
            return null;
        }

        return normalizeSharedDeliveryCenter(window.RanauDeliveryCenter.get());
    }

    function normalizeSharedDeliveryCenter(center) {
        center = center || {};
        var lat = Number(center.lat);
        var lon = Number(center.lon);
        var zoom = center.zoom === null || center.zoom === undefined || center.zoom === ''
            ? null
            : Number(center.zoom);

        if (!isFinite(lat) || !isFinite(lon)) {
            return null;
        }

        return {
            lat: lat,
            lon: lon,
            zoom: isFinite(zoom) ? zoom : null,
            source: center.source || '',
            label: center.label || '',
            updated_at: center.updated_at || ''
        };
    }

    function centerFromSharedDeliveryCenter() {
        var center = sharedDeliveryCenter();

        return center ? [center.lon, center.lat] : null;
    }

    function publishSharedDeliveryCenter(center) {
        if (!window.RanauDeliveryCenter || typeof window.RanauDeliveryCenter.set !== 'function') {
            return;
        }

        window.RanauDeliveryCenter.set(center);
    }

    function applySharedDeliveryCenter() {
        var center = sharedDeliveryCenter();
        if (!state.map || !center) {
            return;
        }

        state.map.update({
            location: {
                center: [center.lon, center.lat],
                zoom: center.zoom || 11,
                duration: 250
            }
        });
        state.lastBoundsKey = '';
    }

    function currentMapCenter() {
        var location = state.map && state.map.location ? state.map.location : null;
        var center = location && location.center ? location.center : null;
        if (!center || center.length < 2) {
            var bounds = currentMapBounds();
            if (!bounds || !bounds[0] || !bounds[1]) {
                return null;
            }

            return {
                lon: (bounds[0].lng + bounds[1].lng) / 2,
                lat: (bounds[0].lat + bounds[1].lat) / 2,
                zoom: location && location.zoom ? location.zoom : null
            };
        }

        return {
            lon: Number(center[0]),
            lat: Number(center[1]),
            zoom: location.zoom || null
        };
    }

    function publishMapCenterOnClose() {
        var center = currentMapCenter();
        if (!center || !isFinite(center.lat) || !isFinite(center.lon)) {
            return;
        }

        publishSharedDeliveryCenter({
            lat: center.lat,
            lon: center.lon,
            zoom: center.zoom,
            source: 'fivepost_map',
            label: text('modalTitle', 'Выберите пункт выдачи 5Post')
        });
    }

    function createMapOverlayControls(container) {
        if (container.querySelector('.ranau-fivepost-map-controls')) {
            return;
        }

        var controls = document.createElement('div');
        controls.className = 'ranau-fivepost-map-controls';
        controls.innerHTML = [
            '<form class="ranau-fivepost-map-search" autocomplete="off">',
            '<input type="search" name="ranau-fivepost-query" placeholder="' + escapeHtml(text('searchPlaceholder', 'Введите адрес')) + '" aria-label="' + escapeHtml(text('searchPlaceholder', 'Введите адрес')) + '">',
            '<button type="submit" class="button">' + escapeHtml(text('searchButton', 'Найти')) + '</button>',
            '</form>',
            '<button type="button" class="button ranau-fivepost-map-geolocate" title="' + escapeHtml(text('useLocation', 'Мое местоположение')) + '" aria-label="' + escapeHtml(text('useLocation', 'Мое местоположение')) + '">⌖</button>',
            '<div class="ranau-fivepost-map-suggestions" hidden></div>'
        ].join('');

        container.appendChild(controls);
        var form = controls.querySelector('.ranau-fivepost-map-search');
        var input = controls.querySelector('input');
        var suggestions = controls.querySelector('.ranau-fivepost-map-suggestions');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            hideSuggestions(suggestions);
            searchAddress(input.value);
        });

        input.addEventListener('input', function () {
            window.clearTimeout(state.suggestTimer);
            state.suggestTimer = window.setTimeout(function () {
                renderAddressSuggestions(input.value, suggestions, input);
            }, 250);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hideSuggestions(suggestions);
            }
        });

        suggestions.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-index]');
            if (!button) {
                return;
            }
            var index = Number(button.getAttribute('data-index'));
            var suggestion = state.searchSuggestions && state.searchSuggestions[index];
            if (!suggestion) {
                return;
            }
            input.value = normalizeSearchQuery(suggestion);
            hideSuggestions(suggestions);
            searchAddress(suggestion);
        });

        controls.querySelector('.ranau-fivepost-map-geolocate').addEventListener('click', locateBrowserPosition);
    }

    function setupYandexControls(ymaps3) {
        return ymaps3.import('@yandex/ymaps3-default-ui-theme').then(function (ui) {
            var topControls = new ymaps3.YMapControls({position: 'top left'});
            var rightControls = new ymaps3.YMapControls({position: 'right'});

            if (ui.YMapSearchControl) {
                topControls.addChild(new ui.YMapSearchControl({
                    placeholder: text('searchPlaceholder', 'Введите адрес'),
                    search: yandexGeocoderSearch,
                    suggest: yandexGeoSuggest,
                    searchResult: handleYandexSearchResult
                }));
            }

            if (ui.YMapZoomControl) {
                rightControls.addChild(new ui.YMapZoomControl({}));
            }

            if (ui.YMapGeolocationControl) {
                rightControls.addChild(new ui.YMapGeolocationControl({}));
            }

            state.map.addChild(topControls);
            state.map.addChild(rightControls);
        }).catch(function () {
            setStatus(text('yandexLoadFailed', 'Не удалось загрузить Яндекс Карты.'));
        });
    }

    function scheduleVisiblePointsLoad() {
        window.clearTimeout(state.debounceTimer);
        state.debounceTimer = window.setTimeout(loadVisiblePoints, 350);
    }

    function loadVisiblePoints() {
        if (!state.map) {
            return;
        }

        var bounds = currentMapBounds();
        if (!bounds || !bounds[0] || !bounds[1]) {
            return;
        }

        var sw = bounds[0];
        var ne = bounds[1];
        var boundsKey = [ne.lat.toFixed(5), ne.lng.toFixed(5), sw.lat.toFixed(5), sw.lng.toFixed(5)].join(',');
        if (boundsKey === state.lastBoundsKey) {
            return;
        }
        state.lastBoundsKey = boundsKey;

        setStatus(text('loading', 'Загружаем пункты выдачи...'));
        var url = new URL((config.restUrl || '') + '/pickup-points');
        url.searchParams.set('ne_lat', ne.lat);
        url.searchParams.set('ne_lng', ne.lng);
        url.searchParams.set('sw_lat', sw.lat);
        url.searchParams.set('sw_lng', sw.lng);

        fetch(url.toString(), {
            credentials: 'same-origin',
            headers: {'X-WP-Nonce': config.restNonce || ''}
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body.message || text('pointsLoadFailed', 'Не удалось загрузить пункты выдачи.'));
                }
                return body;
            });
        }).then(function (body) {
            state.points = body.points || [];
            renderPoints(state.points);
            renderList(state.points);
            setStatus(formatResultsStatus(state.points.length));
        }).catch(function (error) {
            setStatus(error.message);
            renderList([]);
        });
    }

    function renderPoints(points) {
        clearMarkers();
        if (!state.map || !state.ymaps3) {
            return;
        }

        points.forEach(function (point) {
            var marker = createPickupMarker(point);
            if (!marker) {
                return;
            }
            state.markers.push(marker);
            state.markersById[String(point.id)] = marker;
            state.map.addChild(marker);
        });
        reopenFocusedPopup();
    }

    function clearMarkers() {
        if (state.map) {
            state.markers.forEach(function (marker) {
                state.map.removeChild(marker);
            });
        }
        state.markers = [];
        state.markersById = {};
        closeActivePopup({keepFocused: true});
    }

    function createPickupMarker(point) {
        if (!point.latitude || !point.longitude) {
            return null;
        }

        var element = document.createElement('div');
        element.className = 'ranau-fivepost-marker';
        element.setAttribute('role', 'button');
        element.setAttribute('tabindex', '0');
        element.setAttribute('aria-label', point.name || text('pickupPoint', 'Пункт выдачи'));
        element.title = point.name || text('pickupPoint', 'Пункт выдачи');
        element.innerHTML = '<span></span>';
        element.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            focusPoint(point);
        });
        element.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                focusPoint(point);
            }
        });

        var marker = new state.ymaps3.YMapMarker({
            coordinates: [Number(point.longitude), Number(point.latitude)]
        }, element);
        marker._ranauElement = element;

        return marker;
    }

    function openPointPopup(point, anchor) {
        closeActivePopup({keepFocused: true});

        if (anchor) {
            anchor.classList.add('is-active');
        }

        var estimate = point.estimate || {};
        var popup = document.createElement('div');
        popup.className = 'ranau-fivepost-map-popup';
        popup.innerHTML = [
            '<strong>' + escapeHtml(point.name || text('pickupPoint', 'Пункт выдачи')) + '</strong>',
            point.address ? '<span>' + escapeHtml(point.address) + '</span>' : '',
            point.additional ? '<span>' + escapeHtml(point.additional) + '</span>' : '',
            estimate.label ? '<span class="ranau-fivepost-map-popup-estimate">' + escapeHtml(estimate.label) + '</span>' : '',
            '<div class="ranau-fivepost-map-popup-actions">',
            '<button type="button" class="ranau-fivepost-map-popup-choose">' + escapeHtml(text('choose', 'Выбрать')) + '</button>'
            + '<button type="button" class="ranau-fivepost-map-popup-close">' + escapeHtml(text('close', 'Закрыть')) + '</button>',
            '</div>'
        ].join('');

        popup.addEventListener('click', function (event) {
            event.stopPropagation();
        });
        popup.querySelector('.ranau-fivepost-map-popup-choose').addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            selectPoint(point);
        });
        popup.querySelector('.ranau-fivepost-map-popup-close').addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            closeActivePopup();
        });

        anchor.appendChild(popup);
        if (state.mapContainer) {
            state.mapContainer.classList.add('has-active-popup');
        }
        state.activePopup = popup;
        state.activePopupAnchor = anchor || null;
    }

    function reopenFocusedPopup() {
        if (!state.focusedPointId) {
            return;
        }

        var marker = state.markersById[String(state.focusedPointId)];
        var element = marker && marker._ranauElement ? marker._ranauElement : null;
        var point = findPoint(state.focusedPointId);
        if (element && point) {
            openPointPopup(point, element);
        }
    }

    function closeActivePopup(options) {
        options = options || {};
        if (!options.keepFocused) {
            state.focusedPointId = '';
        }
        if (state.activePopupAnchor) {
            state.activePopupAnchor.classList.remove('is-active');
        }
        if (state.activePopup && state.activePopup.parentNode) {
            state.activePopup.parentNode.removeChild(state.activePopup);
        }
        if (state.mapContainer) {
            state.mapContainer.classList.remove('has-active-popup');
        }
        state.activePopup = null;
        state.activePopupAnchor = null;
    }

    function currentMapBounds() {
        var bounds = state.map && state.map.bounds;
        if (!bounds || !bounds[0] || !bounds[1]) {
            return null;
        }

        var first = toBoundsPoint(bounds[0]);
        var second = toBoundsPoint(bounds[1]);
        if (!first || !second) {
            return null;
        }

        return [
            {
                lat: Math.min(first.lat, second.lat),
                lng: Math.min(first.lng, second.lng)
            },
            {
                lat: Math.max(first.lat, second.lat),
                lng: Math.max(first.lng, second.lng)
            }
        ];
    }

    function toBoundsPoint(point) {
        if (!point || point.length < 2) {
            return null;
        }

        return {
            lng: Number(point[0]),
            lat: Number(point[1])
        };
    }

    function toYMaps3Coordinates(latLng) {
        if (!latLng || latLng.length < 2) {
            return [37.617698, 55.755864];
        }

        return [Number(latLng[1]), Number(latLng[0])];
    }

    function yandexGeoSuggest(query) {
        query = normalizeSearchQuery(query);
        if (!config.yandexSuggestApiKey || query.length < 2) {
            return Promise.resolve([]);
        }

        var url = new URL('https://suggest-maps.yandex.ru/v1/suggest');
        url.searchParams.set('apikey', config.yandexSuggestApiKey);
        url.searchParams.set('text', query);
        url.searchParams.set('lang', 'ru_RU');
        url.searchParams.set('results', '10');
        url.searchParams.set('print_address', '1');
        url.searchParams.set('attrs', 'uri');

        return fetch(url.toString()).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body.message || text('addressSearchFailed', 'Не удалось выполнить поиск адреса.'));
                }
                return (body.results || []).map(mapSuggestResult);
            });
        }).catch(function () {
            return [];
        });
    }

    function yandexGeocoderSearch(request) {
        var normalized = normalizeGeocoderRequest(request);
        if (!config.yandexGeocoderApiKey || (!normalized.query && !normalized.uri)) {
            return Promise.resolve([]);
        }

        var url = new URL('https://geocode-maps.yandex.ru/v1/');
        url.searchParams.set('apikey', config.yandexGeocoderApiKey);
        url.searchParams.set('lang', 'ru_RU');
        url.searchParams.set('format', 'json');
        url.searchParams.set('results', '10');
        if (normalized.uri) {
            url.searchParams.set('uri', normalized.uri);
        } else {
            url.searchParams.set('geocode', normalized.query);
        }

        return fetch(url.toString()).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body.message || text('addressSearchFailed', 'Не удалось выполнить поиск адреса.'));
                }
                return mapGeocoderResponse(body);
            });
        }).catch(function () {
            setStatus(text('addressSearchFailed', 'Не удалось выполнить поиск адреса.'));
            return [];
        });
    }

    function searchAddress(request) {
        yandexGeocoderSearch(request).then(function (results) {
            if (!results.length) {
                setStatus(text('addressNotFound', 'Адрес не найден.'));
                return;
            }
            handleYandexSearchResult(results);
        });
    }

    function renderAddressSuggestions(query, suggestions, input) {
        yandexGeoSuggest(query).then(function (items) {
            state.searchSuggestions = items;
            if (!items.length || document.activeElement !== input) {
                hideSuggestions(suggestions);
                return;
            }

            suggestions.innerHTML = items.map(function (item, index) {
                var title = item.title && item.title.text ? item.title.text : normalizeSearchQuery(item);
                var subtitle = item.subtitle && item.subtitle.text ? item.subtitle.text : '';
                return [
                    '<button type="button" data-index="' + index + '">',
                    '<strong>' + escapeHtml(title) + '</strong>',
                    subtitle ? '<span>' + escapeHtml(subtitle) + '</span>' : '',
                    '</button>'
                ].join('');
            }).join('');
            suggestions.hidden = false;
        });
    }

    function hideSuggestions(suggestions) {
        if (suggestions) {
            suggestions.hidden = true;
            suggestions.innerHTML = '';
        }
    }

    function locateBrowserPosition() {
        if (!navigator.geolocation) {
            setStatus(text('geolocationUnavailable', 'Геолокация недоступна.'));
            return;
        }

        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({name: 'geolocation'}).then(function (permission) {
                if (permission.state === 'denied') {
                    setStatus(text('geolocationDenied', 'Браузер запретил доступ к местоположению. Разрешите геопозицию для сайта и попробуйте снова.'));
                    return;
                }
                permission.onchange = function () {
                    if (permission.state === 'denied') {
                        setStatus(text('geolocationDenied', 'Браузер запретил доступ к местоположению. Разрешите геопозицию для сайта и попробуйте снова.'));
                    }
                    if (permission.state === 'granted') {
                        setStatus(text('geolocationWaiting', 'Ожидаем координаты браузера...'));
                    }
                };
                requestBrowserPosition();
            }).catch(requestBrowserPosition);
            return;
        }

        requestBrowserPosition();
    }

    function requestBrowserPosition() {
        clearGeolocationWatch();
        setStatus(text('geolocationWaiting', 'Ожидаем разрешение браузера на определение местоположения...'));
        state.geolocationTimer = window.setTimeout(function () {
            clearGeolocationWatch();
            setStatus(text('geolocationTimeout', 'Браузер не успел определить местоположение за минуту. Попробуйте еще раз.'));
        }, 60000);

        state.geolocationWatchId = navigator.geolocation.watchPosition(function (position) {
            var coords = position.coords || {};
            var latitude = Number(coords.latitude);
            var longitude = Number(coords.longitude);
            if (!isFinite(latitude) || !isFinite(longitude)) {
                return;
            }

            clearGeolocationWatch();
            state.map.update({
                location: {
                    center: [longitude, latitude],
                    zoom: 13,
                    duration: 350
                }
            });
            state.lastBoundsKey = '';
            scheduleVisiblePointsLoad();
        }, function (error) {
            if (error && error.code === 1) {
                clearGeolocationWatch();
                setStatus(geolocationErrorMessage(error));
            }
        }, {
            enableHighAccuracy: false,
            timeout: 60000,
            maximumAge: 600000
        });
    }

    function clearGeolocationWatch() {
        if (state.geolocationWatchId !== null) {
            navigator.geolocation.clearWatch(state.geolocationWatchId);
            state.geolocationWatchId = null;
        }
        if (state.geolocationTimer) {
            window.clearTimeout(state.geolocationTimer);
            state.geolocationTimer = null;
        }
    }

    function geolocationErrorMessage(error) {
        if (error && error.code === 1) {
            return text('geolocationDenied', 'Браузер запретил доступ к местоположению. Разрешите геопозицию для сайта и попробуйте снова.');
        }
        if (error && error.code === 2) {
            return text('geolocationPositionUnavailable', 'Браузер не смог получить координаты устройства.');
        }
        if (error && error.code === 3) {
            return text('geolocationTimeout', 'Браузер не успел определить местоположение за минуту. Попробуйте еще раз.');
        }

        return text('geolocationFailed', 'Не удалось определить местоположение.');
    }

    function handleYandexSearchResult(searchResult) {
        if (!searchResult || !searchResult.length) {
            setStatus(text('addressNotFound', 'Адрес не найден.'));
            return;
        }

        var bounds = searchResult.length === 1 ? null : searchResultsBounds(searchResult);
        var location = {
            duration: 400
        };

        if (bounds) {
            location.bounds = bounds;
        } else if (searchResult[0].geometry && searchResult[0].geometry.coordinates) {
            location.center = searchResult[0].geometry.coordinates;
            location.zoom = 12;
        }

        state.map.update({location: location});
        state.lastBoundsKey = '';
        scheduleVisiblePointsLoad();
    }

    function mapSuggestResult(result) {
        var title = result.title || {};
        var subtitle = result.subtitle || {};

        return {
            title: title,
            subtitle: subtitle,
            tags: result.tags || [],
            uri: result.uri || '',
            value: title.text || ''
        };
    }

    function mapGeocoderResponse(body) {
        var collection = body && body.response && body.response.GeoObjectCollection;
        var members = collection && collection.featureMember ? collection.featureMember : [];

        return members.map(function (member) {
            var geoObject = member.GeoObject || {};
            var point = geoObject.Point && geoObject.Point.pos ? geoObject.Point.pos.split(/\s+/).map(Number) : null;
            if (!point || point.length < 2) {
                return null;
            }

            return {
                type: 'Feature',
                geometry: {
                    type: 'Point',
                    coordinates: [point[0], point[1]]
                },
                properties: {
                    name: geoObject.name || '',
                    description: geoObject.description || '',
                    boundedBy: geoObject.boundedBy || null
                }
            };
        }).filter(Boolean);
    }

    function searchResultsBounds(results) {
        var lng = [];
        var lat = [];
        results.forEach(function (result) {
            if (result.geometry && result.geometry.coordinates) {
                lng.push(Number(result.geometry.coordinates[0]));
                lat.push(Number(result.geometry.coordinates[1]));
            }
        });

        if (!lng.length || !lat.length) {
            return null;
        }

        return [
            [Math.min.apply(Math, lng), Math.min.apply(Math, lat)],
            [Math.max.apply(Math, lng), Math.max.apply(Math, lat)]
        ];
    }

    function normalizeGeocoderRequest(request) {
        if (typeof request === 'string') {
            return {query: request.trim(), uri: ''};
        }

        request = request || {};
        return {
            query: normalizeSearchQuery(request),
            uri: request.uri || (request.action && request.action.uri) || ''
        };
    }

    function normalizeSearchQuery(request) {
        if (typeof request === 'string') {
            return request.trim();
        }

        request = request || {};
        if (request.value) {
            return String(request.value).trim();
        }
        if (request.title && request.title.text) {
            return String(request.title.text).trim();
        }
        if (request.text) {
            return String(request.text).trim();
        }

        return '';
    }

    function renderList(points) {
        var count = state.modal ? state.modal.querySelector('.ranau-fivepost-results-count') : null;
        if (count) {
            count.textContent = formatResultsStatus(points.length);
        }

        if (!state.list) {
            return;
        }

        if (!points.length) {
            state.list.innerHTML = '<div class="ranau-fivepost-empty">' + escapeHtml(text('empty', 'В этой области карты нет доступных пунктов выдачи.')) + '</div>';
            return;
        }

        state.list.innerHTML = points.map(function (point) {
            var isSelected = state.selectedPoint && String(state.selectedPoint.id) === String(point.id);
            return [
                '<article class="ranau-fivepost-result' + (isSelected ? ' is-selected' : '') + '" data-point-id="' + escapeHtml(point.id) + '">',
                '<button type="button" class="ranau-fivepost-result-focus">',
                '<span class="ranau-fivepost-result-name">' + escapeHtml(point.name || text('pickupPoint', 'Пункт выдачи')) + '</span>',
                '<span class="ranau-fivepost-result-address">' + escapeHtml(point.address || '') + '</span>',
                point.additional ? '<span class="ranau-fivepost-result-extra">' + escapeHtml(point.additional) + '</span>' : '',
                point.estimate && point.estimate.label ? '<span class="ranau-fivepost-result-estimate">' + escapeHtml(point.estimate.label) + '</span>' : '',
                '</button>',
                '<button type="button" class="button ranau-fivepost-result-choose">' + escapeHtml(text('choose', 'Выбрать')) + '</button>',
                '</article>'
            ].join('');
        }).join('');
    }

    function pointBalloon(point) {
        var estimate = point.estimate || {};
        return [
            '<div class="ranau-fivepost-balloon">',
            '<div>' + escapeHtml(point.address || '') + '</div>',
            point.additional ? '<div>' + escapeHtml(point.additional) + '</div>' : '',
            estimate.label ? '<div class="ranau-fivepost-estimate">' + escapeHtml(estimate.label) + '</div>' : '',
            '</div>'
        ].join('');
    }

    function selectPoint(point) {
        state.selectedPoint = point;
        if (point.latitude && point.longitude) {
            publishSharedDeliveryCenter({
                lat: point.latitude,
                lon: point.longitude,
                zoom: 15,
                source: 'fivepost_pickup',
                label: point.name || text('pickupPoint', 'Пункт выдачи')
            });
        }
        renderList(state.points);
        document.dispatchEvent(new CustomEvent('ranauFivePost:pointSelected', {detail: point}));
        closeModal({skipCenterPublish: true});
    }

    function persistSelectedPoint(point) {
        if (!config.restUrl) {
            return Promise.resolve();
        }

        var calculation = null;
        if (point && point.id) {
            try {
                calculation = window.RanauFivePostCommitV1.begin(point);
            } catch (error) {
                return Promise.reject(error);
            }
        }

        return fetch((config.restUrl || '') + '/selected-point', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.restNonce || ''
            },
            body: JSON.stringify({point_id: point && point.id ? point.id : ''})
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('selection_save_failed');
            }
            return response.json();
        }).then(function (result) {
            return point && point.id ? window.RanauFivePostCommitV1.commit(calculation, result) : result;
        });
    }

    function focusPoint(point) {
        if (!state.map || !point.latitude || !point.longitude) {
            return;
        }

        state.focusedPointId = String(point.id || '');
        state.map.update({
            location: {
                center: popupSafeCenter(point, 15),
                zoom: 15,
                duration: 250
            }
        });

        var marker = state.markersById[String(point.id)];
        var element = marker && marker._ranauElement ? marker._ranauElement : null;
        if (element) {
            openPointPopup(point, element);
        }
    }

    function popupSafeCenter(point, zoom) {
        var latitude = Number(point.latitude);
        var longitude = Number(point.longitude);
        var worldSize = 256 * Math.pow(2, zoom);
        var latitudeRadians = latitude * Math.PI / 180;
        var pixelsPerLatitudeDegree = worldSize / 360 / Math.max(0.35, Math.cos(latitudeRadians));
        var popupOffsetPixels = 130;

        return [longitude, latitude + popupOffsetPixels / pixelsPerLatitudeDegree];
    }

    function setStatus(message) {
        if (state.status) {
            state.status.textContent = message;
        }
    }

    function fillClassicCheckout(point, options) {
        var pointKind = pickupPointKind(point);
        var map = mapPickupPointData(point);

        Object.keys(map).forEach(function (key) {
            var field = document.getElementById('ranau_fivepost_' + key);
            if (field) {
                field.value = map[key];
            }
        });

        setFieldValue('shipping_address_1', map.point_address);
        setFieldValue('shipping_city', map.point_city);
        setFieldValue('shipping_state', map.point_region);
        setFieldValue('shipping_postcode', map.point_postcode);
        setFieldValue('shipping_country', map.point_country);
        setOrderCommentLines({
            'ПВЗ 5Post': point.name || pickupPointNumber(point) || point.id || '',
            'Тип ПВЗ': pointKind
        });

        document.querySelectorAll('.ranau-fivepost-selected').forEach(function (node) {
            setTextIfChanged(node, selectedPointSummary(point, map, currentFivePostShippingSummary()));
            node.classList.add('is-filled');
        });
        refreshFivePostRatePresentation();

        if (window.jQuery && !hasBlocksCheckoutCartUpdate()) {
            window.jQuery(document.body).trigger('update_checkout', {clear: false});
        }
    }

    function mapPickupPointData(point) {
        var pointNumber = pickupPointNumber(point);
        var deliveryAddress = pointNumber
            ? 'ПВЗ ' + pointNumber + ', ' + (point.address || '')
            : (point.address || '');

        return {
            point_id: checkoutExtensionString(point.id),
            point_name: checkoutExtensionString(point.name || pointNumber || point.id),
            point_address: checkoutExtensionString(deliveryAddress),
            point_region: checkoutExtensionString(point.region),
            point_city: checkoutExtensionString(pickupCityFromPoint(point)),
            point_postcode: checkoutExtensionString(point.postcode),
            point_country: checkoutExtensionString(normalizeCountry(point.country || '')),
            point_latitude: checkoutExtensionString(point.latitude),
            point_longitude: checkoutExtensionString(point.longitude)
        };
    }

    function checkoutExtensionString(value) {
        if (value === undefined || value === null) {
            return '';
        }

        return String(value);
    }

    function emptyCheckoutExtensionData() {
        return {
            point_id: '',
            point_name: '',
            point_address: '',
            point_region: '',
            point_city: '',
            point_postcode: '',
            point_country: '',
            point_latitude: '',
            point_longitude: ''
        };
    }

    function hasBlocksCheckoutCartUpdate() {
        return Boolean(
            window.wc &&
            window.wc.blocksCheckout &&
            typeof window.wc.blocksCheckout.extensionCartUpdate === 'function'
        );
    }

    function normalizePickupCity(city) {
        return String(city || '')
            .trim()
            .replace(/\s+/g, ' ')
            .replace(/^г\.?\s+/i, '')
            .replace(/\s+г\.?$/i, '');
    }

    function pickupCityFromPoint(point) {
        var city = normalizePickupCity(point && point.city);
        var address = String((point && point.address) || '');
        var match;

        if (city) {
            return city;
        }

        match = address.match(/^([^,]+?)\s+г\.?(?:,|$)/i) || address.match(/^г\.?\s*([^,]+)/i);
        return match ? normalizePickupCity(match[1]) : '';
    }

    function currentCheckoutDeliveryCity() {
        var field = document.querySelector('#shipping-city, #shipping_city, #shipping-city, input[name="shipping_city"], input[name="shipping.city"]');

        return normalizePickupCity(field && field.value);
    }

    function pickupCityMatchesCheckout(map) {
        var checkoutCity = currentCheckoutDeliveryCity();

        return Boolean(checkoutCity && normalizePickupCity(map && map.point_city) === checkoutCity);
    }

    function publishCheckoutDeliveryCity(point, map) {
        var lat = Number(map.point_latitude || (point && point.latitude));
        var lon = Number(map.point_longitude || (point && point.longitude));
        var hasCoordinates = isFinite(lat) && isFinite(lon);

        if (!map.point_city) {
            return;
        }

        if (pickupCityMatchesCheckout(map)) {
            return;
        }

        document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryCitySelected', {
            detail: {
                method: 'ranau_fivepost_pickup',
                city: {
                    city: map.point_city,
                    region: map.point_region,
                    country: map.point_country || 'RU',
                    postal_code: map.point_postcode,
                    source: 'delivery_point',
                    confirmed: true,
                    geo: hasCoordinates ? {
                        lat: lat,
                        lon: lon
                    } : null,
                    delivery_map_center: hasCoordinates ? {
                        lat: lat,
                        lon: lon,
                        zoom: 11,
                        source: 'fivepost_pickup',
                        label: (point && point.name) || map.point_city,
                        updated_at: new Date().toISOString()
                    } : null
                }
            }
        }));
    }

    function isFivePostShippingMethodInput(input) {
        return Boolean(input && String(input.value || '').indexOf('ranau_fivepost_pickup') === 0);
    }

    function activateFivePostShippingMethodGuard() {
        state.shippingMethodGuardVersion += 1;
        return state.shippingMethodGuardVersion;
    }

    function cancelFivePostShippingMethodGuard() {
        state.shippingMethodGuardVersion += 1;
    }

    function ensureFivePostShippingMethodSelected() {
        var input = document.querySelector('input[type="radio"][value^="ranau_fivepost_pickup"]');
        var clickTarget;

        if (!input || input.checked) {
            return;
        }

        clickTarget = input.closest('label.wc-block-components-radio-control__option, label') || input;
        clickTarget.click();
        input.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function scheduleFivePostShippingMethodGuard(guardVersion) {
        guardVersion = guardVersion || state.shippingMethodGuardVersion;

        [0, 300, 1000, 2000].forEach(function (delay) {
            window.setTimeout(function () {
                if (guardVersion !== state.shippingMethodGuardVersion) {
                    return;
                }
                ensureFivePostShippingMethodSelected();
            }, delay);
        });
    }

    function renderStorefrontCheckoutSelectors() {
        state.selectorRenderQueued = false;
        document.querySelectorAll('.checkout-extension-slot').forEach(function (slot) {
            var methodId = slot.getAttribute('data-shipping-method-id') || '';
            var rateId = slot.getAttribute('data-shipping-rate-id') || '';
            var isFivePost = methodId === 'ranau_fivepost_pickup' || rateId.indexOf('ranau_fivepost_pickup') === 0;
            var isSelected = isCheckoutSlotSelected(slot);

            if (!isFivePost) {
                return;
            }

            if (!isSelected) {
                slot.removeAttribute('data-extension-required');
                slot.removeAttribute('data-extension-error');
                slot.removeAttribute('data-extension-ready');
                var existing = slot.querySelector('.ranau-fivepost-selector');
                if (existing) {
                    existing.remove();
                }
                return;
            }

            setAttributeIfChanged(slot, 'data-extension-required', 'true');
            setAttributeIfChanged(slot, 'data-extension-error', 'Выберите пункт выдачи 5Post перед оформлением заказа.');

            if (!slot.querySelector('.ranau-fivepost-selector')) {
                slot.innerHTML = [
                    '<div class="fivepost-selector ranau-fivepost-selector">',
                    hiddenExtensionFieldsHtml(),
                    '<div class="fivepost-selector-head">',
                    '<div><strong>ПУНКТ ВЫДАЧИ 5POST</strong><span>Выберите удобный пункт выдачи на карте.</span></div>',
                    '<button type="button" class="ghost-button ranau-fivepost-open-map">' + escapeHtml(state.selectedPoint ? 'ИЗМЕНИТЬ ПВЗ' : text('openMap', 'Выбрать пункт выдачи')) + '</button>',
                    '</div>',
                    '<div class="ranau-fivepost-selected fivepost-selected" aria-live="polite">Пункт выдачи пока не выбран</div>',
                    '<button type="button" class="fivepost-clear ranau-fivepost-clear" hidden>СБРОСИТЬ ВЫБОР</button>',
                    '</div>'
                ].join('');
            }

            updateStorefrontCheckoutSelector(slot, state.selectedPoint);
        });
        renderBlocksRadioCheckoutSelectors();
        syncClassicCheckoutSelectors();
        refreshFivePostRatePresentation();
    }

    function renderBlocksRadioCheckoutSelectors() {
        document.querySelectorAll('.ranau-fivepost-blocks-selector').forEach(function (selector) {
            var rateValue = selector.getAttribute('data-ranau-fivepost-rate-value') || '';
            var radio = rateValue
                ? document.querySelector('input[type="radio"][value="' + cssEscape(rateValue) + '"]')
                : null;
            if (!radio || !radio.checked) {
                selector.remove();
            }
        });

        document.querySelectorAll('label.wc-block-components-radio-control__option').forEach(function (option) {
            var radio = option.querySelector('input[type="radio"][value^="ranau_fivepost_pickup"]');
            if (!radio) {
                return;
            }

            var rateValue = radio.value || '';
            var existing = rateValue
                ? document.querySelector('.ranau-fivepost-blocks-selector[data-ranau-fivepost-rate-value="' + cssEscape(rateValue) + '"]')
                : null;

            if (!radio.checked) {
                if (existing) {
                    existing.remove();
                }
                return;
            }

            if (!existing) {
                existing = document.createElement('div');
                existing.className = 'fivepost-selector ranau-fivepost-selector ranau-fivepost-blocks-selector is-active';
                existing.setAttribute('data-ranau-fivepost-rate-value', rateValue);
                existing.innerHTML = [
                    hiddenExtensionFieldsHtml(),
                    '<div class="fivepost-selector-head">',
                    '<div><strong>ПУНКТ ВЫДАЧИ 5POST</strong><span>Выберите удобный пункт выдачи на карте.</span></div>',
                    '<button type="button" class="ghost-button ranau-fivepost-open-map">' + escapeHtml(state.selectedPoint ? 'ИЗМЕНИТЬ ПВЗ' : text('openMap', 'Выбрать пункт выдачи')) + '</button>',
                    '</div>',
                    '<div class="ranau-fivepost-selected fivepost-selected" aria-live="polite">Пункт выдачи пока не выбран</div>',
                    '<button type="button" class="fivepost-clear ranau-fivepost-clear" hidden>СБРОСИТЬ ВЫБОР</button>'
                ].join('');
            }

            if (existing.previousElementSibling !== option) {
                option.insertAdjacentElement('afterend', existing);
            }

            updateStorefrontSelectorElement(existing, state.selectedPoint);
        });
        refreshFivePostRatePresentation();
    }

    function scheduleStorefrontCheckoutSelectors() {
        if (state.selectorRenderQueued) {
            return;
        }

        state.selectorRenderQueued = true;
        window.requestAnimationFrame(renderStorefrontCheckoutSelectors);
    }

    function hiddenExtensionFieldsHtml() {
        return [
            'point_id',
            'point_name',
            'point_address',
            'point_region',
            'point_city',
            'point_postcode',
            'point_country',
            'point_latitude',
            'point_longitude'
        ].map(function (field) {
            return '<input type="hidden" class="checkout-extension-data" data-extension-namespace="ranau-fivepost" id="ranau_fivepost_' + field + '" name="' + field + '" value="">';
        }).join('');
    }

    function updateStorefrontCheckoutSelector(slot, point) {
        updateStorefrontSelectorElement(slot, point);
    }

    function updateStorefrontSelectorElement(root, point) {
        var selected = root.querySelector('.ranau-fivepost-selected');
        var clear = root.querySelector('.ranau-fivepost-clear');
        var button = root.querySelector('.ranau-fivepost-open-map');
        var map = point ? mapPickupPointData(point) : {};

        setAttributeIfChanged(root, 'data-ranau-delivery-method', 'ranau_fivepost_pickup');
        setAttributeIfChanged(root, 'data-ranau-delivery-complete', point ? '1' : '0');

        Object.keys(map).forEach(function (key) {
            var field = root.querySelector('.checkout-extension-data[name="' + cssEscape(key) + '"]');
            if (field) {
                field.value = map[key] || '';
            }
        });

        if (point) {
            setAttributeIfChanged(root, 'data-extension-ready', 'true');
            if (selected) {
                setTextIfChanged(selected, selectedPointSummary(point, map, currentFivePostShippingSummary()));
                selected.classList.add('is-filled');
            }
            if (clear) {
                clear.hidden = false;
            }
            if (button) {
                setTextIfChanged(button, 'ИЗМЕНИТЬ ПВЗ');
            }
        } else {
            root.removeAttribute('data-extension-ready');
            root.querySelectorAll('.checkout-extension-data').forEach(function (field) {
                field.value = '';
            });
            if (selected) {
                setTextIfChanged(selected, 'Пункт выдачи пока не выбран');
                selected.classList.remove('is-filled');
            }
            if (clear) {
                clear.hidden = true;
            }
            if (button) {
                setTextIfChanged(button, text('openMap', 'Выбрать пункт выдачи'));
            }
        }

        document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {
            detail: {
                method: 'ranau_fivepost_pickup',
                complete: Boolean(point)
            }
        }));
    }

    function selectedPointSummary(point, map, shippingSummary) {
        var parts = [map.point_name + ' — ' + map.point_address];
        var estimate = point && point.estimate ? point.estimate : {};
        if (shippingSummary) {
            parts.push(shippingSummary);
        } else if (estimate.label) {
            parts.push(estimate.label);
        }

        return parts.join(' · ');
    }

    function fivePostRateOption() {
        var input = document.querySelector('input[type="radio"][value^="ranau_fivepost_pickup"]');

        return input ? input.closest('label.wc-block-components-radio-control__option, label') : null;
    }

    function fivePostRatePresentationSummary(option) {
        option = option || fivePostRateOption();
        var textValue = option ? option.textContent.replace(/\s+/g, ' ').trim() : '';
        var selectedSummary = document.querySelector('.ranau-fivepost-selected.is-filled');
        var selectedText = selectedSummary ? selectedSummary.textContent.replace(/\s+/g, ' ').trim() : '';
        var combined = [textValue, selectedText].join(' ');
        var priceMatch = combined.match(/(\d[\d\s.,]*₽)(?!.*\d[\d\s.,]*₽)/);
        var freeMatch = combined.match(/бесплатно/i);
        var daysMatch = combined.match(/(?:\(|·\s*|срок:\s*)(\d+\s*дн\.)/i);

        if (!priceMatch && !freeMatch) {
            return '';
        }

        return [freeMatch ? 'Бесплатно' : priceMatch[1].trim(), daysMatch ? daysMatch[1].replace(/\s+/g, ' ').trim() : ''].filter(Boolean).join(' · ');
    }

    function refreshFivePostRatePresentation() {
        var option = fivePostRateOption();
        var target = option ? option.querySelector('.wc-block-components-radio-control__secondary-label') : null;
        if (!target) {
            return;
        }

        var summary = state.selectedPoint ? fivePostRatePresentationSummary(option) : '';
        target.classList.add('ranau-fivepost-rate-meta');
        target.setAttribute('data-ranau-fivepost-rate-meta', summary || 'Рассчитаем после выбора ПВЗ');
        target.setAttribute('data-ranau-fivepost-rate-pending', summary ? '0' : '1');
    }

    function currentFivePostShippingSummary() {
        var checked = document.querySelector('input[type="radio"][value^="ranau_fivepost_pickup"]:checked');
        var label = checked ? checked.closest('label') : null;
        var textValue = label ? label.textContent.replace(/\s+/g, ' ').trim() : '';
        if (!textValue) {
            return '';
        }

        var priceMatch = textValue.match(/(\d[\d\s.,]*₽)(?!.*\d[\d\s.,]*₽)/);
        var freeMatch = textValue.match(/бесплатно/i);
        if (!priceMatch && !freeMatch) {
            return '';
        }

        var daysMatch = textValue.match(/(?:\(|·\s*|срок:\s*)(\d+\s*дн\.)/i);
        var parts = ['Доставка: ' + (freeMatch ? 'бесплатно' : priceMatch[1].trim())];
        if (daysMatch) {
            parts.push('срок: ' + daysMatch[1].replace(/\s+/g, ' ').trim());
        }

        return parts.join(', ');
    }

    function syncSelectedPointShippingSummary() {
        if (!state.selectedPoint) {
            return;
        }

        var map = mapPickupPointData(state.selectedPoint);
        var summary = selectedPointSummary(state.selectedPoint, map, currentFivePostShippingSummary());
        document.querySelectorAll('.ranau-fivepost-selected').forEach(function (node) {
            setTextIfChanged(node, summary);
            node.classList.add('is-filled');
        });
        refreshFivePostRatePresentation();
    }

    function syncClassicCheckoutSelectors() {
        document.querySelectorAll('[data-ranau-fivepost-classic]').forEach(function (selector) {
            selector.classList.toggle('is-active', isClassicFivePostRateSelected(selector));
        });
    }

    function isClassicFivePostRateSelected(selector) {
        var row = selector.closest('li, tr') || document;
        var localRadio = row.querySelector('input.shipping_method[value^="ranau_fivepost_pickup"]');
        if (localRadio) {
            return localRadio.checked;
        }

        return Boolean(document.querySelector('input.shipping_method[value^="ranau_fivepost_pickup"]:checked'));
    }

    function isCheckoutSlotSelected(slot) {
        var selectedAttr = slot.getAttribute('data-selected') || slot.getAttribute('aria-selected');
        if (selectedAttr === 'true') {
            return true;
        }
        if (selectedAttr === 'false') {
            return false;
        }

        if (slot.querySelector('input[type="radio"]:checked, input[type="checkbox"]:checked')) {
            return true;
        }

        if (slot.closest('[aria-checked="true"], [data-selected="true"], .is-selected, .wc-block-components-radio-control-accordion-option--checked, .wc-block-components-radio-control__option-checked')) {
            return true;
        }

        var methodId = slot.getAttribute('data-shipping-method-id') || '';
        var rateId = slot.getAttribute('data-shipping-rate-id') || '';
        return Boolean(document.querySelector('input[type="radio"][value="' + cssEscape(rateId) + '"]:checked, input[type="radio"][value="' + cssEscape(methodId) + '"]:checked'));
    }

    function setAttributeIfChanged(node, name, value) {
        if (node.getAttribute(name) !== value) {
            node.setAttribute(name, value);
        }
    }

    function setTextIfChanged(node, value) {
        value = String(value || '');
        if (node && node.textContent !== value) {
            node.textContent = value;
        }
    }

    function updateStorefrontCheckoutFromPoint(point, options) {
        var map = mapPickupPointData(point);

        renderStorefrontCheckoutSelectors();
        document.querySelectorAll('.checkout-extension-slot[data-shipping-method-id="ranau_fivepost_pickup"], .checkout-extension-slot[data-shipping-rate-id^="ranau_fivepost_pickup"]').forEach(function (slot) {
            updateStorefrontCheckoutSelector(slot, point);
        });

        document.dispatchEvent(new CustomEvent('ranauCheckout:checkoutAddressUpdate', {
            detail: {
                source: 'fivepost',
                kind: 'pickup',
                shipping: {
                    address_1: map.point_address,
                    city: map.point_city,
                    state: map.point_region,
                    postcode: map.point_postcode,
                    country: map.point_country
                }
            }
        }));
        refreshStoreApiShippingRates(point, options);
    }

    function clearStorefrontCheckoutPoint() {
        state.selectedPoint = null;
        persistSelectedPoint(null);
        document.querySelectorAll('.checkout-extension-slot[data-shipping-method-id="ranau_fivepost_pickup"], .checkout-extension-slot[data-shipping-rate-id^="ranau_fivepost_pickup"]').forEach(function (slot) {
            updateStorefrontCheckoutSelector(slot, null);
        });
        renderStorefrontCheckoutSelectors();
        refreshStoreApiShippingRates(null);
    }

    function refreshStoreApiShippingRates(point, options) {
        var updateResult = Promise.resolve();
        var guardVersion = options && options.guardVersion ? options.guardVersion : state.shippingMethodGuardVersion;

        if (!point && hasBlocksCheckoutCartUpdate()) {
            updateResult = window.wc.blocksCheckout.extensionCartUpdate({
                namespace: 'ranau-fivepost',
                data: emptyCheckoutExtensionData()
            });
        }

        if (!updateResult || typeof updateResult.then !== 'function') {
            updateResult = Promise.resolve();
        }

        updateResult.catch(function () {
            return Promise.resolve();
        }).then(function () {
            if (point && guardVersion === state.shippingMethodGuardVersion) {
                scheduleFivePostShippingMethodGuard(guardVersion);
            }

            document.dispatchEvent(new CustomEvent('ranauFivePost:shippingRateChanged'));
            window.setTimeout(refreshFivePostRatePresentation, 50);
            window.setTimeout(syncSelectedPointShippingSummary, 100);
            window.setTimeout(refreshFivePostRatePresentation, 300);
            window.setTimeout(refreshFivePostRatePresentation, 500);
            window.setTimeout(syncSelectedPointShippingSummary, 600);
            window.setTimeout(syncSelectedPointShippingSummary, 1200);
        });
    }

    function registerBlocksCheckoutButton() {
        renderStorefrontCheckoutSelectors();
    }

    function updateBlocksExtensionData(point) {
        var wp = window.wp || {};
        var data = point ? mapPickupPointData(point) : emptyCheckoutExtensionData();
        var setter;

        if (!wp.data || !wp.data.dispatch) {
            return;
        }

        var dispatch = wp.data.dispatch('wc/store/checkout');
        if (!dispatch) {
            return;
        }

        setter = typeof dispatch.setExtensionData === 'function'
            ? dispatch.setExtensionData
            : dispatch.__internalSetExtensionData;

        if (typeof setter === 'function') {
            setter.call(dispatch, 'ranau-fivepost', data);
        }
    }

    function clearFivePostCheckoutExtensionData() {
        updateBlocksExtensionData(null);
    }

    function setFieldValue(id, value) {
        var field = document.getElementById(id);
        if (field && value) {
            field.value = value;
            field.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }

    function setOrderCommentLines(nextLines) {
        var field = document.getElementById('order_comments');
        if (!field) {
            return;
        }

        var lines = field.value ? field.value.split(/\r?\n/) : [];
        var managedLabels = Object.keys(nextLines).concat(['Населенный пункт']);

        lines = lines.filter(function (line) {
            return !managedLabels.some(function (label) {
                return line.indexOf(label + ':') === 0;
            });
        });

        Object.keys(nextLines).forEach(function (label) {
            if (nextLines[label]) {
                lines.push(label + ': ' + nextLines[label]);
            }
        });

        field.value = lines.filter(function (line) {
            return line.trim() !== '';
        }).join('\n');
        field.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function pickupPointNumber(point) {
        var name = String(point.name || '').trim();
        if (!name) {
            return '';
        }

        return name.split(' - ')[0].trim();
    }

    function pickupPointKind(point) {
        var additional = String(point.additional || '').trim().replace(/^5post\.\s*/i, '');
        var type = String(point.type || '').toUpperCase();

        if (additional) {
            return additional;
        }

        if (type === 'POSTAMAT') {
            return 'Постамат';
        }

        if (point.cash_allowed) {
            return 'Касса';
        }

        return type || '';
    }

    function normalizeCountry(country) {
        country = String(country || '').trim();
        if (country.toLowerCase() === 'россия') {
            return 'RU';
        }

        return country;
    }

    function findPoint(id) {
        id = String(id || '');
        for (var i = 0; i < state.points.length; i++) {
            if (String(state.points[i].id) === id) {
                return state.points[i];
            }
        }
        return null;
    }

    function formatResultsStatus(count) {
        if (count === 0) {
            return text('zeroPoints', '0 пунктов выдачи');
        }

        return count + ' ' + text('pointsShort', 'пунктов выдачи');
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function cssEscape(value) {
        if (window.CSS && window.CSS.escape) {
            return window.CSS.escape(String(value));
        }
        return String(value).replace(/"/g, '\\"');
    }

    function elementTouchesSelector(element, selector) {
        return Boolean(
            element &&
            element.nodeType === 1 &&
            (
                (element.matches && element.matches(selector)) ||
                (element.closest && element.closest(selector)) ||
                (element.querySelector && element.querySelector(selector))
            )
        );
    }

    function mutationTouchesFivePostSelector(mutations) {
        var selector = '.ranau-fivepost-selector, .ranau-fivepost-blocks-selector';

        for (var i = 0; i < mutations.length; i++) {
            if (elementTouchesSelector(mutations[i].target, selector)) {
                return true;
            }

            for (var j = 0; j < mutations[i].addedNodes.length; j++) {
                if (elementTouchesSelector(mutations[i].addedNodes[j], selector)) {
                    return true;
                }
            }

            for (var k = 0; k < mutations[i].removedNodes.length; k++) {
                if (elementTouchesSelector(mutations[i].removedNodes[k], selector)) {
                    return true;
                }
            }
        }

        return false;
    }

    function mutationTouchesShippingMethods(mutations) {
        var selector = [
            '.wc-block-components-shipping-rates-control',
            '.wc-block-checkout__shipping-option',
            '.wc-block-components-radio-control__option',
            '.checkout-extension-slot',
            '#shipping_method',
            '.woocommerce-shipping-methods',
            'input[type="radio"][value^="ranau_fivepost_pickup"]'
        ].join(', ');

        for (var i = 0; i < mutations.length; i++) {
            if (elementTouchesSelector(mutations[i].target, selector)) {
                return true;
            }

            for (var j = 0; j < mutations[i].addedNodes.length; j++) {
                if (elementTouchesSelector(mutations[i].addedNodes[j], selector)) {
                    return true;
                }
            }

            for (var k = 0; k < mutations[i].removedNodes.length; k++) {
                if (elementTouchesSelector(mutations[i].removedNodes[k], selector)) {
                    return true;
                }
            }
        }

        return false;
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('.ranau-fivepost-open-map')) {
            event.preventDefault();
            openModal();
        }
        if (event.target.closest('.ranau-fivepost-clear')) {
            event.preventDefault();
            clearStorefrontCheckoutPoint();
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('input.shipping_method, input[type="radio"], input[type="checkbox"]')) {
            if (event.target.matches('input[type="radio"]') && !isFivePostShippingMethodInput(event.target)) {
                cancelFivePostShippingMethodGuard();
                clearFivePostCheckoutExtensionData();
            }
            scheduleStorefrontCheckoutSelectors();
            window.setTimeout(refreshFivePostRatePresentation, 100);
        }
    });

    document.addEventListener('ranauFivePost:pointSelected', function (event) {
        var point = event.detail || {};
        var applySelection = function () {
            var map = mapPickupPointData(point);
            var guardVersion = activateFivePostShippingMethodGuard();
            var options = {guardVersion: guardVersion};

            ensureFivePostShippingMethodSelected();
            publishCheckoutDeliveryCity(point, map);
            fillClassicCheckout(point, options);
            updateStorefrontCheckoutFromPoint(point, options);
            updateBlocksExtensionData(point);
            scheduleFivePostShippingMethodGuard(guardVersion);
        };

        persistSelectedPoint(point).then(applySelection).catch(function () {
            state.selectedPoint = null;
            setStatus(text('selectionFailed', 'Не удалось сохранить выбранный пункт. Попробуйте ещё раз.'));
            scheduleStorefrontCheckoutSelectors();
        });
    });

    document.addEventListener('ranauCheckout:checkoutShippingUpdated', scheduleStorefrontCheckoutSelectors);
    document.addEventListener('ranauFivePost:shippingRateChanged', refreshFivePostRatePresentation);
    document.addEventListener('ranauCheckout:deliveryCenterChanged', function () {
        if (state.modal && state.modal.classList.contains('is-open')) {
            applySharedDeliveryCenter();
            scheduleVisiblePointsLoad();
        }
    });
    function handleCheckoutDestinationChange(event) {
        var selectedCity = normalizePickupCity(pickupCityFromPoint(state.selectedPoint || {}));
        var destinationCity = normalizePickupCity(event.detail && event.detail.city ? event.detail.city.city : '');

        if (state.selectedPoint && selectedCity && destinationCity && selectedCity === destinationCity) {
            persistSelectedPoint(state.selectedPoint);
            refreshStoreApiShippingRates(state.selectedPoint);
            return;
        }

        cancelFivePostShippingMethodGuard();
        clearStorefrontCheckoutPoint();
        updateBlocksExtensionData(null);
    }
    document.addEventListener('ranauCheckout:cityContextChanging', handleCheckoutDestinationChange);
    document.addEventListener('ranauCheckout:cityContextChanged', handleCheckoutDestinationChange);

    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', function () {
            scheduleStorefrontCheckoutSelectors();
            window.setTimeout(refreshFivePostRatePresentation, 100);
        });
    }

    if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
            if (!mutationTouchesShippingMethods(mutations) || mutationTouchesFivePostSelector(mutations)) {
                return;
            }

            scheduleStorefrontCheckoutSelectors();
        }).observe(document.body || document.documentElement, {
            childList: true,
            subtree: true
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            registerBlocksCheckoutButton();
            renderStorefrontCheckoutSelectors();
        });
    } else {
        registerBlocksCheckoutButton();
        renderStorefrontCheckoutSelectors();
    }

    window.RanauFivePostMap = {
        open: openModal,
        close: closeModal,
        selectedPoint: function () {
            return state.selectedPoint;
        }
    };
}());
