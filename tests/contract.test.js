#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const source = [
  'ranau-5post-for-woocommerce.php',
  'includes/class-delivery-state.php',
  'includes/class-rest-controller.php',
  'includes/class-blocks-checkout-adapter.php',
  'includes/class-classic-checkout-adapter.php',
  'assets/js/delivery-adapter.js',
  'assets/js/map-widget.js',
].map((file) => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');

assert(!/glow[\s_-]?me|goflow/i.test(source), 'Legacy product identifiers must not ship.');
assert(source.includes('ProviderStateStore'), '5Post must bundle server-owned delivery state.');
assert(source.includes('createDeliveryStateMachine'), '5Post must instantiate its browser state machine.');
assert(source.includes('createCheckoutBridge'), '5Post must use one Woo checkout bridge per commit.');
assert(source.includes("'/commit'"), 'Classic checkout must commit server state before refreshing totals.');
assert(!/\$_POST\[['\"]ranau_fivepost_point/.test(source), 'Order metadata must not trust posted pickup-point fields.');

process.stdout.write('Ranau 5Post contract checks passed.\n');
