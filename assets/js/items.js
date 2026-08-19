/**
 * Adds/removes line-item rows on item-entry tables.
 * Usage: <table data-items-table data-row-template="#row-template">...</table>
 *        <button type="button" data-add-row="tableId">+ Add line item</button>
 */
document.addEventListener('click', function (e) {
  const addBtn = e.target.closest('[data-add-row]');
  if (addBtn) {
    const tbody = document.getElementById(addBtn.getAttribute('data-add-row'));
    const tmpl = document.getElementById(tbody.dataset.rowTemplate);
    const clone = tmpl.content.cloneNode(true);
    const idx = tbody.querySelectorAll('tr').length;
    clone.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace('__IDX__', idx);
    });
    tbody.appendChild(clone);
  }
  const rmBtn = e.target.closest('[data-remove-row]');
  if (rmBtn) {
    const tr = rmBtn.closest('tr');
    const tbody = tr.parentElement;
    if (tbody.querySelectorAll('tr').length > 1) {
      tr.remove();
    } else {
      tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    }
  }
});

// live total calculation on item-entry tables that have qty/price inputs
document.addEventListener('input', function (e) {
  if (e.target.matches('[data-qty], [data-price]')) {
    const tr = e.target.closest('tr');
    const qty = parseFloat(tr.querySelector('[data-qty]')?.value) || 0;
    const price = parseFloat(tr.querySelector('[data-price]')?.value) || 0;
    const totalEl = tr.querySelector('[data-line-total]');
    if (totalEl) totalEl.textContent = (qty * price).toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    recomputeGrandTotal();
  }
});

function recomputeGrandTotal() {
  const grandEl = document.querySelector('[data-grand-total]');
  if (!grandEl) return;
  let sum = 0;
  document.querySelectorAll('[data-qty]').forEach(function (qtyEl) {
    const tr = qtyEl.closest('tr');
    const qty = parseFloat(qtyEl.value) || 0;
    const price = parseFloat(tr.querySelector('[data-price]')?.value) || 0;
    sum += qty * price;
  });
  grandEl.textContent = sum.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
