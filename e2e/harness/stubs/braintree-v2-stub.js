/* Test double for https://js.braintreegateway.com/js/braintree-2.27.0.min.js (drop-in v2).
 * The real drop-in is a cross-origin iframe that needs the network and is not deterministic.
 * The calendar calls: braintree.setup(clientToken, 'dropin', { container: 'payment-form', onError: fn }).
 * The real drop-in injects payment_method_nonce into the surrounding form on submit; so does this stub. */
(function () {
  window.braintree = {
    setup: function (clientToken, integration, options) {
      var container = document.getElementById(options.container);
      if (!container) return;
      container.innerHTML =
        '<div class="fake-braintree" style="border:1px dashed #999;padding:12px;margin:8px 0">' +
        'Payment form placeholder (test double)</div>';
      var form = container.closest('form');
      if (!form) return;
      form.addEventListener('submit', function () {
        var input = form.querySelector('input[name="payment_method_nonce"]');
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'payment_method_nonce';
          form.appendChild(input);
        }
        input.value = 'fake-valid-nonce';
      }, true);
    }
  };
})();
