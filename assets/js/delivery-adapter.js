(function (window, document) {
    'use strict';

    var runtime = window.RanauFivePostDeliveryRuntimeV1;
    var machine = null;

    function selectedRateId() {
        var field = document.querySelector('input[type="radio"][value^="ranau_fivepost_pickup"]:checked, input.shipping_method[value^="ranau_fivepost_pickup"]:checked');
        return field ? String(field.value || '') : '';
    }

    function fieldValue(selectors) {
        for (var index = 0; index < selectors.length; index += 1) {
            var field = document.querySelector(selectors[index]);
            if (field && String(field.value || '').trim()) {
                return String(field.value).trim();
            }
        }
        return '';
    }

    function begin(selection) {
        var rateId = selectedRateId();
        if (!runtime || !rateId) {
            throw new Error('delivery_state_unavailable');
        }
        if (!machine || machine.rateId !== rateId) {
            machine = runtime.createDeliveryStateMachine({rateId: rateId});
        }
        machine.setAvailable(true, {
            country: fieldValue(['#shipping-country', '#shipping_country', 'select[name="shipping_country"]']) || 'RU',
            region: fieldValue(['#shipping-state', '#shipping_state', 'input[name="shipping_state"]']),
            city: fieldValue(['#shipping-city', '#shipping_city', 'input[name="shipping_city"]']),
            postcode: fieldValue(['#shipping-postcode', '#shipping_postcode', 'input[name="shipping_postcode"]']),
            packageFingerprint: String((window.RanauFivePost || {}).packageFingerprint || '')
        });

        return {
            rateId: rateId,
            token: machine.beginCalculation(selection),
            bridge: runtime.createCheckoutBridge({machine: machine, namespace: 'ranau-fivepost', environment: window})
        };
    }

    function commit(calculation, result) {
        var config = window.RanauFivePost || {};
        var serverCommit = result && result.commit ? result.commit : {};
        if (!calculation || !calculation.token || !serverCommit.commit_token) {
            return Promise.reject(new Error('delivery_commit_missing'));
        }
        if (!(window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function')) {
            var accepted = machine.commit(calculation.token, result.quote);
            if (!accepted.accepted || selectedRateId() !== calculation.rateId) {
                return Promise.reject(new Error('delivery_response_stale'));
            }
            return fetch((config.restUrl || '') + '/commit', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || ''},
                body: JSON.stringify(serverCommit)
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('delivery_commit_failed');
                }
                if (window.jQuery) {
                    window.jQuery(document.body).trigger('update_checkout');
                }
                return result;
            });
        }

        return calculation.bridge.commitCalculation({
            selectedRateId: selectedRateId(),
            token: calculation.token,
            quote: result.quote,
            packageFingerprint: String(serverCommit.package_fingerprint || config.packageFingerprint || ''),
            commitToken: String(serverCommit.commit_token || ''),
            data: serverCommit
        }).then(function (commitResult) {
            if (!commitResult.accepted || !commitResult.checkoutUpdated) {
                throw new Error(commitResult.reason || 'delivery_commit_failed');
            }
            return result;
        });
    }

    Object.defineProperty(window, 'RanauFivePostCommitV1', {
        configurable: false,
        enumerable: false,
        writable: false,
        value: Object.freeze({begin: begin, commit: commit})
    });
}(window, document));
