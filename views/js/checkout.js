/**
 * Live update of COD fee in checkout summary when payment method changes.
 * Avoids full page reload so payment selection is preserved.
 */
(function () {
  if (typeof prestashop === 'undefined') {
    return;
  }

  var config = window.codwithfeeConfig || {};
  var ajaxUrl = config.ajaxUrl || '';
  var moduleName = config.moduleName || 'codwithfee';
  var busy = false;
  var lastWanted = null; // true = fee should be present, false = should be absent

  function isCodSelected() {
    var selected = document.querySelector('input[name="payment-option"]:checked');
    if (!selected) {
      return false;
    }

    if (selected.getAttribute('data-module-name') === moduleName) {
      return true;
    }

    var form = document.getElementById('pay-with-' + selected.id + '-form');
    if (form && form.action) {
      if (form.action.indexOf('/' + moduleName + '/') !== -1) {
        return true;
      }
      if (form.action.indexOf('module=' + moduleName) !== -1) {
        return true;
      }
    }

    var container = selected.closest('[data-module-name]');
    if (container && container.getAttribute('data-module-name') === moduleName) {
      return true;
    }

    var label = document.querySelector('label[for="' + selected.id + '"]');
    if (label && form && form.action && form.action.indexOf(moduleName) !== -1) {
      return true;
    }

    return false;
  }

  /**
   * Refresh only the order summary blocks (no full page reload).
   * Uses the same cart refresh URL as core, but bypasses updateCart
   * so we never hit refreshCheckoutPage() on the payment step.
   */
  function refreshOrderSummary() {
    var $cart = typeof window.jQuery !== 'undefined' ? window.jQuery('.js-cart') : null;
    var refreshUrl = $cart && $cart.length ? $cart.data('refresh-url') : null;

    if (!refreshUrl || typeof window.jQuery === 'undefined') {
      // Fallback: soft visual update only – totals will be correct after order place
      return Promise.resolve();
    }

    return window.jQuery
      .post(refreshUrl, {})
      .then(function (resp) {
        if (!resp || typeof resp !== 'object') {
          return;
        }

        var selectors = (prestashop && prestashop.selectors && prestashop.selectors.cart) || {};

        function replace(sel, html) {
          if (!sel || html === undefined || html === null) {
            return;
          }
          var $el = window.jQuery(sel);
          if ($el.length) {
            $el.replaceWith(html);
          }
        }

        replace(selectors.detailedTotals, resp.cart_detailed_totals);
        replace(selectors.summaryItemsSubtotal, resp.cart_summary_items_subtotal);
        replace(selectors.summarySubTotalsContainer, resp.cart_summary_subtotals_container);
        replace(selectors.summaryProducts, resp.cart_summary_products);
        replace(selectors.summaryTotals, resp.cart_summary_totals);
        replace(selectors.detailedActions, resp.cart_detailed_actions);
        replace(selectors.voucher, resp.cart_voucher);
        replace(selectors.overview, resp.cart_detailed);
        replace(selectors.summaryTop, resp.cart_summary_top);

        // Classic / common checkout summary container
        if (resp.cart_summary_totals) {
          var $summary = window.jQuery('#js-checkout-summary, .cart-summary, .checkout-summary');
          if ($summary.length && resp.cart_summary_totals) {
            // totals already replaced above when selectors match
          }
        }

        // Notify listeners that care about updated totals (without triggering payment-step reload)
        if (typeof prestashop.emit === 'function') {
          try {
            prestashop.emit('updatedCart', { eventType: 'codwithfee', resp: resp });
          } catch (e) {
            // ignore
          }
        }
      })
      .catch(function () {
        // Silent fail – fee is still applied server-side at validation
      });
  }

  function callFee(action) {
    if (!ajaxUrl || busy) {
      return Promise.resolve(null);
    }
    busy = true;
    var body = 'action=' + encodeURIComponent(action) + '&ajax=1';

    return fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body,
      credentials: 'same-origin'
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        busy = false;
        if (data && data.success) {
          return refreshOrderSummary().then(function () {
            return data;
          });
        }
        return data;
      })
      .catch(function () {
        busy = false;
        return null;
      });
  }

  function syncFee() {
    var wantFee = isCodSelected();
    if (lastWanted === wantFee) {
      return;
    }
    lastWanted = wantFee;
    callFee(wantFee ? 'add' : 'remove');
  }

  function bind() {
    document.addEventListener('change', function (e) {
      var t = e.target;
      if (t && t.name === 'payment-option') {
        lastWanted = null; // force re-evaluate
        syncFee();
      }
    });

    // On first load: align fee product with current selection (no reload)
    setTimeout(function () {
      if (!ajaxUrl) {
        return;
      }
      lastWanted = null;
      if (isCodSelected()) {
        callFee('add').then(function () {
          lastWanted = true;
        });
      } else {
        // Ensure fee is not left over from a previous selection
        callFee('remove').then(function () {
          lastWanted = false;
        });
      }
    }, 400);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
