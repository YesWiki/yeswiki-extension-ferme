/* The farm's creation form: the address kept to a slug, the screenshot of the chosen theme, the access account of a protected wiki, and no sending before consent. */
ywInitEach('[data-ferme-form]', (block) => {
  const form = block.closest('form')
  const slug = block.querySelector('[data-ferme-slug]')
  if (slug) {
    slug.addEventListener('input', () => {
      const cleaned = slug.value.replace(/[^a-zA-Z0-9_-]/g, '')
      if (cleaned !== slug.value) slug.value = cleaned
    })
  }

  const showScreenshot = () => {
    const checked = block.querySelector('[data-ferme-theme]:checked')
    block.querySelectorAll('[data-ferme-screenshot]').forEach((image) => {
      image.hidden = !checked || image.dataset.fermeScreenshot !== checked.dataset.fermeTheme
    })
  }

  const access = block.querySelector('[data-ferme-access]')
  const showAccess = () => {
    if (!access) return
    const chosen = block.querySelector('input[name="yeswiki-farm-acls"]:checked') || block.querySelector('input[type="hidden"][name="yeswiki-farm-acls"]')
    const needed = Boolean(chosen && chosen.hasAttribute('data-ferme-create-user'))
    access.hidden = !needed
    access.querySelectorAll('input').forEach((input) => { input.disabled = !needed })
  }

  const consent = () => {
    if (!form) return
    const refused = form.querySelector('input[type="radio"][name="bf_accord"][value="non"]')
    const agreed = form.querySelector('input[type="radio"][name="bf_accord"][value="oui"]')
    if (!refused || !agreed) return
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((submit) => {
      submit.disabled = !agreed.checked
    })
  }

  block.addEventListener('change', () => {
    showScreenshot()
    showAccess()
  })
  if (form) form.addEventListener('change', consent)
  showScreenshot()
  showAccess()
  consent()
})
