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

   // Form
  const form = document.getElementById('contactForm');
  const note = document.getElementById('formHint');

  const showNote = (msg, ok = false) => {
    if (!note) return;
    note.textContent = msg;
    note.style.color = ok ? 'rgba(255,255,255,.9)' : 'rgba(255,200,180,.95)';
  };

  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const data = new FormData(form);
      const nombre = String(data.get('nombre') || '').trim();
      const email = String(data.get('email') || '').trim();
      const telefono = String(data.get('telefono') || '').trim();
      const mensaje = String(data.get('mensaje') || '').trim();
      const consent = form.querySelector('input[type="checkbox"]')?.checked;

      if (!nombre || !email || !telefono || !mensaje || !consent) {
        showNote('Revisa los campos obligatorios y acepta la política de privacidad.');
        return;
      }

      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: data
        });

        const json = await res.json();

        if (json.ok) {
          showNote('¡Listo! Hemos recibido tu solicitud.', true);

          console.log("Se dispara formulario_enviado");

          window.dataLayer = window.dataLayer || [];
          window.dataLayer.push({
            event: 'formulario_enviado'
          });

          form.reset();
        } else {
          showNote(json.error || 'No se pudo enviar el formulario.');
        }
      } catch (error) {
        console.error(error);
        showNote('Error de conexión. Inténtalo de nuevo.');
      }
    });
  }

  // =========================
  // Carrusel reseñas
  // =========================

  function scrollCarousel(direction){
    const carousel = document.getElementById("carousel");
    if(!carousel) return;

    const firstCard = carousel.querySelector(".review-card");
    const gap = 18;
    const step = firstCard
      ? (firstCard.getBoundingClientRect().width + gap)
      : 320;

    carousel.scrollBy({ left: direction * step, behavior: "smooth" });
  }

  // 👇 IMPORTANTE: hacerla global
  window.scrollCarousel = scrollCarousel;

  (function initReviewsCarousel(){
    const carousel = document.getElementById("carousel");
    if(!carousel) return;

    const prevBtn = document.querySelector(".carousel-nav.prev");
    const nextBtn = document.querySelector(".carousel-nav.next");

    function updateNav(){
      const maxScrollLeft = carousel.scrollWidth - carousel.clientWidth;
      if(prevBtn) prevBtn.disabled = carousel.scrollLeft <= 2;
      if(nextBtn) nextBtn.disabled = carousel.scrollLeft >= maxScrollLeft - 2;
    }

    carousel.addEventListener("scroll", updateNav, { passive: true });
    window.addEventListener("resize", updateNav);

    carousel.addEventListener("keydown", (e) => {
      if(e.key === "ArrowLeft") scrollCarousel(-1);
      if(e.key === "ArrowRight") scrollCarousel(1);
    });

    updateNav();
  })();
  // =========================
// Scroll to Top
// =========================

const scrollBtn = document.querySelector(".scroll-top");

if(scrollBtn){
  window.addEventListener("scroll", () => {
    if(window.scrollY > 1200){
      scrollBtn.classList.add("show");
    } else {
      scrollBtn.classList.remove("show");
    }
  });

  scrollBtn.addEventListener("click", () => {
    window.scrollTo({
      top: 0,
      behavior: "smooth"
    });
  });
}

})();
