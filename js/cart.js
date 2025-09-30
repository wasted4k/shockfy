// js/cart.js (reemplaza completamente tu archivo actual)
(() => {
  console.log('✅ cart.js cargado');

  // Estado
  let cart = []; // { id, name, unit_price, qty, image, stock, color, size }

  // DOM
  const productsGrid = document.getElementById('productsGrid');
  const cartItemsEl = document.getElementById('cart-items');
  const cartCountEl = document.getElementById('cart-count');
  const subtotalEl = document.getElementById('cart-subtotal');
  const totalEl = document.getElementById('cart-total');
  const discountInput = document.getElementById('cart-discount');
  const checkoutBtn = document.getElementById('checkout-btn');
  const clearBtn = document.getElementById('clear-cart');
  const searchInput = document.getElementById('productSearch');
  const categoryFilter = document.getElementById('categoryFilter');
  const resetFiltersBtn = document.getElementById('resetFilters');

  // Seguridad: asegurar variables inyectadas
  if (typeof CURRENCY === 'undefined') window.CURRENCY = '';
  if (typeof PRODUCTS === 'undefined') window.PRODUCTS = [];

  // Helpers
  function formatMoney(v){
    const n = Number(v) || 0;
    return `${CURRENCY} ${n.toFixed(2)}`;
  }
  function productById(id){ return PRODUCTS.find(p => String(p.id) === String(id)); }
  function findInCart(id){ return cart.find(it => String(it.id) === String(id)); }

  // Recalcula totales
  function recalcTotals(){
    let subtotal = 0;
    let qtySum = 0;
    cart.forEach(i => { subtotal += Number(i.unit_price) * Number(i.qty); qtySum += Number(i.qty); });
    const discount = Math.max(0, parseFloat(discountInput?.value || 0));
    const total = Math.max(0, subtotal - discount);

    subtotalEl.textContent = formatMoney(subtotal);
    totalEl.textContent = formatMoney(total);
    cartCountEl.textContent = `${qtySum} ${qtySum === 1 ? 'item' : 'items'}`;
    // disable checkout when empty
    if (checkoutBtn) checkoutBtn.disabled = cart.length === 0;
  }

  // Render del carrito (genera DOM dinámico)
  function renderCart(){
    cartItemsEl.innerHTML = '';
    if (!cart.length) {
      cartItemsEl.innerHTML = '<div class="empty">Agrega productos para vender</div>';
      recalcTotals();
      return;
    }

    cart.forEach(item => {
      const row = document.createElement('div');
      row.className = 'cart-item';
      row.dataset.id = item.id;

      // imagen
      const img = document.createElement('img');
      img.src = item.image || 'assets/img/placeholder.png';
      img.alt = item.name;

      // info (nombre + line total & editable price)
      const info = document.createElement('div');
      info.className = 'info';
      const nameDiv = document.createElement('div');
      nameDiv.style.fontWeight = '700';
      nameDiv.textContent = item.name;
      info.appendChild(nameDiv);

      const metaDiv = document.createElement('div');
      metaDiv.style.fontSize = '12px';
      metaDiv.style.color = '#666';

      // editable price input and line total span
      const priceLabel = document.createElement('label');
      priceLabel.style.marginRight = '8px';
      priceLabel.innerHTML = `Precio: `;

      const priceInput = document.createElement('input');
      priceInput.type = 'number';
      priceInput.step = '0.01';
      priceInput.min = '0';
      priceInput.className = 'price-input';
      priceInput.value = Number(item.unit_price).toFixed(2);
      priceInput.style.width = '90px';
      priceInput.style.padding = '4px';
      priceInput.style.borderRadius = '6px';
      priceInput.style.border = '1px solid #ddd';
      priceInput.dataset.productId = item.id;

      const lineTotalSpan = document.createElement('span');
      lineTotalSpan.className = 'line-total';
      lineTotalSpan.style.marginLeft = '10px';
      lineTotalSpan.textContent = formatMoney(Number(item.unit_price) * Number(item.qty));

      metaDiv.appendChild(priceLabel);
      metaDiv.appendChild(priceInput);
      metaDiv.appendChild(lineTotalSpan);

      info.appendChild(metaDiv);

      // qty controls
      const qtyWrap = document.createElement('div');
      qtyWrap.className = 'qty';
      qtyWrap.innerHTML = `
        <button class="btn-decrease" title="Disminuir">−</button>
        <input class="qty-input" type="number" min="1" value="${item.qty}" style="width:56px;padding:6px;border-radius:6px;border:1px solid #ddd;text-align:center;">
        <button class="btn-increase" title="Aumentar">+</button>
        <button class="btn-remove" title="Eliminar" style="margin-left:8px; background:#e74c3c; color:#fff; border:none; padding:6px 8px; border-radius:6px; cursor:pointer;">✕</button>
      `;

      row.appendChild(img);
      row.appendChild(info);
      row.appendChild(qtyWrap);
      cartItemsEl.appendChild(row);

      // asignar listeners (por cada row para asegurar control)
      const inputQty = qtyWrap.querySelector('.qty-input');
      const btnInc = qtyWrap.querySelector('.btn-increase');
      const btnDec = qtyWrap.querySelector('.btn-decrease');
      const btnRem = qtyWrap.querySelector('.btn-remove');

      // increase / decrease
      btnInc.addEventListener('click', () => updateQuantity(item.id, Number(item.qty) + 1));
      btnDec.addEventListener('click', () => updateQuantity(item.id, Number(item.qty) - 1));
      inputQty.addEventListener('change', () => updateQuantity(item.id, Number(inputQty.value)));

      // remove
      btnRem.addEventListener('click', () => removeFromCart(item.id));

      // price editable
      priceInput.addEventListener('change', () => {
        let v = parseFloat(priceInput.value);
        if (isNaN(v) || v < 0) {
          alert('Precio inválido');
          priceInput.value = Number(item.unit_price).toFixed(2);
          return;
        }
        item.unit_price = v;
        // actualizar line total en DOM
        lineTotalSpan.textContent = formatMoney(Number(item.unit_price) * Number(item.qty));
        recalcTotals();
      });
    }); // end forEach

    recalcTotals();
  }

  // Agregar producto al carrito
  function addToCart(id){
    const prod = productById(id);
    if (!prod) { alert('Producto no encontrado'); return; }
    if (Number(prod.stock) <= 0) { alert('Producto sin stock'); return; }

    const existing = findInCart(id);
    if (existing) {
      if (existing.qty + 1 > Number(existing.stock)) { alert('No hay suficiente stock'); return; }
      existing.qty += 1;
    } else {
      cart.push({
        id: String(prod.id),
        name: prod.name,
        unit_price: Number(prod.price) || 0,
        qty: 1,
        image: prod.image || '',
        stock: Number(prod.stock) || 0,
        color: prod.color || '',
        size: prod.size || ''
      });
    }
    renderCart();
  }

  // update quantity
  function updateQuantity(id, qty){
    qty = parseInt(qty) || 0;
    const item = findInCart(id);
    if (!item) return;
    if (qty <= 0) { removeFromCart(id); return; }
    if (qty > item.stock) { alert('La cantidad solicitada supera el stock disponible.'); item.qty = item.stock; }
    else item.qty = qty;
    renderCart();
  }

  // remove item
  function removeFromCart(id){
    cart = cart.filter(i => String(i.id) !== String(id));
    renderCart();
  }

  // clear cart
  function clearCart(){
    if (!confirm('¿Vaciar el carrito?')) return;
    cart = [];
    renderCart();
  }

  // Checkout (envía unit_price y quantity)
  async function checkout(){
    if (!cart.length) { alert('El carrito está vacío.'); return; }
    const discount = Math.max(0, parseFloat(discountInput?.value || 0));
    const payload = {
      user_id: USER_ID,
      discount,
      items: cart.map(i => ({ product_id: i.id, qty: i.qty, unit_price: i.unit_price }))
    };

    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Procesando...';

    try {
      const res = await fetch('process_sale.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      });
      if (!res.ok) { const t = await res.text(); throw new Error(t || 'Error'); }
      const json = await res.json().catch(()=>null);
      if (json && json.success) {
        alert(json.message || 'Venta registrada correctamente');
        cart = []; renderCart();
        if (json.sale_id) window.location.href = 'sales_report.php';
      } else {
        alert((json && (json.message||json.msg)) || 'Respuesta inesperada del servidor');
      }
    } catch(err) {
      console.error(err);
      alert('Error al procesar la venta: ' + (err.message || err));
    } finally {
      checkoutBtn.disabled = false;
      checkoutBtn.textContent = 'Ir al pago';
    }
  }

  // Filtrar productos por nombre, color o talla (size)
  function filterProducts(){
    const term = (searchInput?.value || '').trim().toLowerCase();
    const cat = categoryFilter?.value || '';
    const cards = Array.from(document.querySelectorAll('.product-card'));
    cards.forEach(card => {
      const name = (card.dataset.name || '').toLowerCase();
      const color = (card.dataset.color || '').toLowerCase();
      const size = (card.dataset.size || '').toLowerCase();
      const catId = card.dataset.cat || '';
      const matchesTerm = term === '' || name.includes(term) || color.includes(term) || size.includes(term);
      const matchesCat = !cat || String(cat) === String(catId);
      card.style.display = (matchesTerm && matchesCat) ? '' : 'none';
    });
  }

  // Bind events (add-to-cart, search, filters, checkout)
  function bindEvents(){
    // Add to cart (delegation to cover future dynamic buttons)
    document.addEventListener('click', (e) => {
      const addBtn = e.target.closest && e.target.closest('.add-to-cart');
      if (addBtn) {
        e.preventDefault();
        const id = addBtn.dataset.id;
        if (id) addToCart(id);
      }
    });

    if (searchInput) searchInput.addEventListener('input', filterProducts);
    if (categoryFilter) categoryFilter.addEventListener('change', filterProducts);
    if (resetFiltersBtn) resetFiltersBtn.addEventListener('click', () => { if (searchInput) searchInput.value = ''; if (categoryFilter) categoryFilter.value = ''; filterProducts(); });

    if (checkoutBtn) checkoutBtn.addEventListener('click', checkout);
    if (clearBtn) clearBtn.addEventListener('click', clearCart);
    if (discountInput) discountInput.addEventListener('input', recalcTotals);
  }

  // Init
  function init(){
    bindEvents();
    renderCart();
    filterProducts();
  }

  // Run when DOM ready
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  // Expose for quick debugging
  window.__pos_cart = { cart, addToCart, renderCart, recalcTotals };
})();
