// Uniformes Pro — minimal JS for menú + formulario (demo)
(() => {
  const toggle = document.querySelector('.nav__toggle');
  const menu = document.querySelector('.nav__menu');

  const setOpen = (open) => {
    if (!menu || !toggle) return;
    menu.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('menu-open', open);
  };

  if (toggle && menu) {
    toggle.addEventListener('click', () => {
      const open = !menu.classList.contains('is-open');
      setOpen(open);
    });

    // Cerrar al clicar un link
    menu.querySelectorAll('a[href^="#"]').forEach(a => {
      a.addEventListener('click', () => setOpen(false));
    });

    // Cerrar con ESC
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') setOpen(false);
    });

    // Cerrar si clicas fuera (solo cuando está abierto)
    document.addEventListener('click', (e) => {
      if (!menu.classList.contains('is-open')) return;
      const target = e.target;
      if (!(target instanceof Element)) return;
      const clickedInside = menu.contains(target) || toggle.contains(target);
      if (!clickedInside) setOpen(false);
    });
  }

  // Footer year
  const year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();

  // Form (demo)
  const form = document.getElementById('quoteForm');
  const note = document.getElementById('formNote');

  const showNote = (msg, ok=false) => {
    if (!note) return;
    note.textContent = msg;
    note.style.color = ok ? 'rgba(255,255,255,.9)' : 'rgba(255,200,180,.95)';
  };

  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();

      const data = new FormData(form);
      const name = String(data.get('name') || '').trim();
      const email = String(data.get('email') || '').trim();
      const message = String(data.get('message') || '').trim();
      const consent = form.querySelector('input[type="checkbox"]')?.checked;

      if (!name || !email || !message || !consent) {
        showNote('Revisa los campos obligatorios y acepta la política de privacidad.');
        return;
      }

      // Demo: simular éxito (reemplaza por tu endpoint)
      showNote('¡Listo! Hemos recibido tu solicitud. (Demo sin envío real)', true);
      form.reset();
    });
  }
})();
