/* The farm's wikis screen: selection, the actions run one wiki at a time, the detail of a row, and the search for wikis without an entry. */
ywInitEach('#ferme-wikis', (screen) => {
  const config = JSON.parse(screen.querySelector('.ferme-config').textContent)
  const t = config.i18n
  const modal = screen.querySelector('.ferme-modal') || document.getElementById('ferme-wikis-modal')
  const modalTitle = modal.querySelector('.yw-modal__title')
  const modalBody = modal.querySelector('.ferme-modal__body')
  const modalFooter = modal.querySelector('.ferme-modal__footer')
  const selectionBar = screen.querySelector('.ferme-selection')
  const selected = new Map()
  let reloadOnClose = false

  const fill = (text, values) => Object.keys(values).reduce(
    (result, key) => result.split(`%{${key}}`).join(values[key]).split(`{${key}}`).join(values[key]),
    text
  )

  const element = (tag, attributes = {}, children = []) => {
    const node = document.createElement(tag)
    Object.entries(attributes).forEach(([name, value]) => {
      if (name === 'text') node.textContent = value
      else if (name === 'className') node.className = value
      else node.setAttribute(name, value)
    })
    children.forEach((child) => node.append(child))
    return node
  }

  const button = (label, className, onClick) => {
    const node = element('button', { type: 'button', className: `yw-btn ${className}`, text: label })
    node.addEventListener('click', onClick)
    return node
  }

  const post = async (url, fields) => {
    const body = new URLSearchParams()
    body.append('csrf-token', config.csrf)
    Object.entries(fields).forEach(([name, value]) => {
      if (Array.isArray(value)) value.forEach((item) => body.append(`${name}[]`, item))
      else body.append(name, value)
    })
    const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const data = await response.json().catch(() => ({ error: `HTTP ${response.status}` }))
    if (!response.ok && !data.error) data.error = `HTTP ${response.status}`
    return data
  }

  const withQuery = (url, params) => {
    const query = new URLSearchParams(params).toString()
    if (!query) return url
    return url + (url.includes('?') ? '&' : '?') + query
  }

  const openModal = (title) => {
    modalTitle.textContent = title
    modalBody.replaceChildren()
    modalFooter.replaceChildren()
    modal.classList.add('yw-modal--open')
  }

  const closeModal = () => {
    modal.classList.remove('yw-modal--open')
    if (reloadOnClose) window.location.reload()
  }

  modal.addEventListener('yw-modal-hidden', () => {
    if (reloadOnClose) window.location.reload()
  })

  const footerClose = () => {
    modalFooter.replaceChildren(button(t.close, 'yw-btn--primary', closeModal))
  }

  const rowsOf = (row) => (row
    ? [{ folder: row.dataset.folder, tag: row.dataset.tag, title: row.dataset.title, mail: row.dataset.mail }]
    : [...selected.values()])

  const paintSelection = () => {
    selectionBar.hidden = selected.size === 0
    selectionBar.querySelector('.ferme-selection__count').textContent = fill(t.selected, { n: selected.size })
    screen.querySelectorAll('.ferme-row').forEach((row) => {
      row.querySelector('[data-ferme-check]').checked = selected.has(row.dataset.tag)
    })
    const page = screen.querySelector('[data-ferme-check-page]')
    if (page) {
      const rows = [...screen.querySelectorAll('.ferme-row')]
      page.checked = rows.length > 0 && rows.every((row) => selected.has(row.dataset.tag))
    }
  }

  screen.addEventListener('change', (event) => {
    const box = event.target
    if (box.matches('[data-ferme-check]')) {
      const row = box.closest('.ferme-row')
      if (box.checked) selected.set(row.dataset.tag, rowsOf(row)[0])
      else selected.delete(row.dataset.tag)
      paintSelection()
    } else if (box.matches('[data-ferme-check-page]')) {
      screen.querySelectorAll('.ferme-row').forEach((row) => {
        if (box.checked) selected.set(row.dataset.tag, rowsOf(row)[0])
        else selected.delete(row.dataset.tag)
      })
      paintSelection()
    } else if (box.matches('[data-ferme-autosubmit]')) {
      box.form.requestSubmit()
    }
  })

  const selectAll = async (trigger) => {
    const label = trigger.textContent
    trigger.disabled = true
    trigger.textContent = t.selecting
    try {
      const response = await fetch(withQuery(config.api.select, config.query), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      const data = await response.json()
      ;(data.wikis || []).forEach((wiki) => selected.set(wiki.tag, wiki))
    } finally {
      trigger.disabled = false
      trigger.textContent = label
      paintSelection()
    }
  }

  const resultLine = (target) => {
    const status = element('span', { className: 'ferme-result__status', text: t.pending })
    const details = element('div', { className: 'ferme-result__details' })
    const line = element('li', { className: 'ferme-result' }, [
      element('span', { className: 'ferme-result__name', text: target.title || target.folder || target.tag }),
      status,
      details
    ])
    return { line, status, details }
  }

  const report = (entry, ok, messages, link) => {
    entry.line.classList.add(ok ? 'ferme-result--done' : 'ferme-result--failed')
    entry.status.textContent = ok ? t.done : t.failed
    messages.filter(Boolean).forEach((message) => entry.details.append(element('div', { text: message })))
    if (link) {
      entry.details.append(element('a', { href: link.url, className: 'yw-btn yw-btn--sm', text: `${t.download} ${link.label}` }))
    }
  }

  const describe = (data) => {
    const messages = [].concat(data.messages || [], data.success || [], data.errors || [])
    if (data.error) messages.push(data.error)
    if (Array.isArray(data.pages)) data.pages.forEach((page) => messages.push(`${page.action} ${page.tag}`))
    return messages
  }

  const runOne = async (action, target, extra) => {
    if (action === 'mail') {
      const data = await post(config.api.mail, { tag: target.tag, subject: extra.subject, body: extra.body })
      return { ok: data.status === 'sent', messages: describe(data) }
    }
    if (action === 'lifetime') {
      const data = await post(config.api.lifetime, { tag: target.tag, kind: extra.kind })
      return { ok: data.status === 'changed', messages: describe(data) }
    }
    if (!target.folder) return { ok: false, messages: [t.failed] }
    const data = await post(`${config.api.run}/${action}`, { folder: target.folder })
    const ok = !data.error && data.status !== 'failed' && !(Array.isArray(data.errors) && data.errors.length)
    return { ok, messages: describe(data), link: data.url ? { url: data.url, label: `${data.file || ''} ${data.size || ''}`.trim() } : null }
  }

  const runDelete = async (entries, targets) => {
    let ok = 0
    for (let start = 0; start < targets.length; start += 5) {
      const chunk = targets.slice(start, start + 5)
      chunk.forEach((target) => { entries.get(target.tag).status.textContent = t.running })
      const data = await post(config.api.delete, { tags: chunk.map((target) => target.tag) })
      ;(data.results || []).forEach((result) => {
        const entry = entries.get(result.tag)
        if (!entry) return
        report(entry, result.success, [result.error, result.output])
        if (result.success) ok += 1
      })
      if (data.error) chunk.forEach((target) => report(entries.get(target.tag), false, [data.error]))
    }
    return ok
  }

  const start = async (action, targets, extra) => {
    reloadOnClose = true
    modalFooter.replaceChildren()
    const list = element('ol', { className: 'ferme-results' })
    const entries = new Map()
    targets.forEach((target) => {
      const entry = resultLine(target)
      entries.set(target.tag || target.folder, entry)
      list.append(entry.line)
    })
    modalBody.append(list)
    const progress = element('progress', { className: 'ferme-progress', max: String(targets.length), value: '0' })
    modalBody.prepend(progress)

    let ok = 0
    if (action === 'delete') {
      ok = await runDelete(entries, targets)
      progress.value = targets.length
    } else {
      for (const [index, target] of targets.entries()) {
        const entry = entries.get(target.tag || target.folder)
        entry.status.textContent = t.running
        try {
          const result = await runOne(action, target, extra)
          report(entry, result.ok, result.messages, result.link)
          if (result.ok) ok += 1
        } catch (failure) {
          report(entry, false, [String(failure)])
        }
        progress.value = index + 1
      }
    }
    modalBody.prepend(element('p', { className: 'yw-alert yw-alert--info', text: fill(t.summary, { ok, failed: targets.length - ok }) }))
    footerClose()
  }

  const confirmAndRun = (action, targets, extra = {}) => {
    const definition = t.actions[action]
    openModal(definition.title)
    if (targets.length === 0) {
      modalBody.append(element('p', { className: 'yw-alert yw-alert--warning', text: t.nothingSelected }))
      footerClose()
      return
    }
    modalBody.append(element('p', { className: definition.danger ? 'yw-alert yw-alert--danger' : 'ferme-intro', text: definition.intro }))
    const list = element('ul', { className: 'ferme-targets' })
    targets.forEach((target) => list.append(element('li', { text: target.title || target.folder })))
    modalBody.append(list)

    let form = null
    if (action === 'mail') {
      form = element('form', { className: 'ferme-mail' }, [
        element('label', { className: 'yw-form-label', text: t.mailSubject }),
        element('input', { className: 'yw-input', name: 'subject', required: 'required' }),
        element('label', { className: 'yw-form-label', text: t.mailBody }),
        element('textarea', { className: 'yw-input', name: 'body', rows: '8', required: 'required' }),
        element('p', { className: 'ferme-muted', text: `${t.mailPlaceholders} {title} {url} {referent} {folder} {lastActivity} {entries} {pages} {users} {files}` })
      ])
      modalBody.append(form)
    }

    const go = button(definition.confirm || t.start, definition.danger ? 'yw-btn--danger' : 'yw-btn--primary', () => {
      if (form) {
        const subject = form.elements.subject.value.trim()
        const body = form.elements.body.value.trim()
        if (!subject || !body) {
          form.prepend(element('p', { className: 'yw-alert yw-alert--warning', text: t.mailEmpty }))
          return
        }
        extra.subject = subject
        extra.body = body
        form.remove()
      }
      list.remove()
      start(action, targets, extra)
    })
    modalFooter.replaceChildren(button(t.cancel, '', closeModal), go)
  }

  const toggleDetail = async (trigger) => {
    const row = trigger.closest('.ferme-row')
    const next = row.nextElementSibling
    if (next && next.classList.contains('ferme-detail')) {
      next.remove()
      trigger.setAttribute('aria-expanded', 'false')
      return
    }
    trigger.setAttribute('aria-expanded', 'true')
    const cell = element('td', { colspan: String(row.children.length) }, [element('p', { className: 'ferme-muted', text: t.loading })])
    const detail = element('tr', { className: 'ferme-detail' }, [cell])
    row.after(detail)
    try {
      const response = await fetch(withQuery(config.api.detail, { folder: row.dataset.folder }), { credentials: 'same-origin' })
      if (!response.ok) throw new Error(`HTTP ${response.status}`)
      cell.innerHTML = await response.text()
    } catch (failure) {
      cell.replaceChildren(element('p', { className: 'yw-alert yw-alert--danger', text: `${t.detailError} ${failure.message}` }))
    }
  }

  const approve = async (trigger) => {
    const holder = trigger.closest('[data-ferme-folder]')
    trigger.disabled = true
    const data = await post(config.api.approve, {
      folder: holder.dataset.fermeFolder,
      tag: trigger.dataset.tag,
      ...(trigger.hasAttribute('data-undo') ? { undo: '1' } : {})
    })
    if (data.error) {
      trigger.after(element('span', { className: 'yw-label yw-label--danger', text: data.error }))
      trigger.disabled = false
      return
    }
    trigger.closest('li').classList.add('ferme-approved')
    trigger.replaceWith(element('span', { className: 'yw-label yw-label--success', text: t.done }))
  }

  const searchServer = async () => {
    reloadOnClose = false
    openModal(t.actions.search.title)
    modalBody.append(element('p', { className: 'ferme-muted', text: t.searchScanning }))
    let data
    try {
      const response = await fetch(config.api.search, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      data = await response.json()
      if (data.error) throw new Error(data.error)
    } catch (failure) {
      modalBody.replaceChildren(element('p', { className: 'yw-alert yw-alert--danger', text: `${t.searchError} ${failure.message}` }))
      footerClose()
      return
    }
    modalBody.replaceChildren(element('p', { className: 'ferme-intro', text: t.searchIntro }))
    if (!data.results || data.results.length === 0) {
      modalBody.append(element('p', { className: 'yw-alert yw-alert--info', text: t.searchNone }))
      footerClose()
      return
    }
    const head = element('tr', {}, ['', t.folder, t.pages, t.entries, t.users, t.lastActivity].map((label) => element('th', { text: label })))
    const body = element('tbody')
    data.results.forEach((wiki) => {
      const measured = wiki.pages !== null
      body.append(element('tr', {}, [
        element('td', {}, [element('input', { type: 'checkbox', value: wiki.folder, 'aria-label': wiki.folder })]),
        element('td', {}, wiki.url ? [element('a', { href: wiki.url, target: '_blank', rel: 'noopener', text: wiki.folder })] : [wiki.folder]),
        element('td', { text: measured ? String(wiki.pages) : t.neverMeasured }),
        element('td', { text: measured ? String(wiki.entries) : '' }),
        element('td', { text: measured ? String(wiki.users) : '' }),
        element('td', { text: wiki.lastActivity || '' })
      ]))
    })
    const table = element('table', { className: 'yw-table ferme-search-table' }, [element('thead', {}, [head]), body])
    const email = element('input', { type: 'email', className: 'yw-input', placeholder: t.searchEmail, 'aria-label': t.searchEmail })
    modalBody.append(element('div', { className: 'ferme-table-wrap' }, [table]), email)
    const importButton = button(t.searchImport.replace('%{n}', '0'), 'yw-btn--primary', async () => {
      const folders = [...body.querySelectorAll('input:checked')].map((box) => box.value)
      if (folders.length === 0) return
      importButton.disabled = true
      const result = await post(config.api.import, { folders, email: email.value.trim() })
      reloadOnClose = true
      modalBody.prepend(element('p', {
        className: result.error ? 'yw-alert yw-alert--danger' : 'yw-alert yw-alert--success',
        text: result.error || `${t.searchImported}: ${(result.imported || []).join(', ')}`
      }))
      footerClose()
    })
    body.addEventListener('change', () => {
      importButton.textContent = fill(t.searchImport, { n: body.querySelectorAll('input:checked').length })
    })
    modalFooter.replaceChildren(button(t.cancel, '', closeModal), importButton)
  }

  screen.addEventListener('click', (event) => {
    const action = event.target.closest('[data-ferme-action]')
    if (action) {
      event.preventDefault()
      const row = action.closest('.ferme-row')
      const name = action.dataset.fermeAction
      confirmAndRun(name, rowsOf(row), name === 'lifetime' ? { kind: action.dataset.fermeKind } : {})
      return
    }
    const trigger = event.target.closest('[data-ferme-detail], [data-ferme-approve], [data-ferme-select-all], [data-ferme-select-none], [data-ferme-search]')
    if (!trigger) return
    if (trigger.matches('[data-ferme-detail]')) toggleDetail(trigger)
    else if (trigger.matches('[data-ferme-approve]')) approve(trigger)
    else if (trigger.matches('[data-ferme-select-all]')) selectAll(trigger)
    else if (trigger.matches('[data-ferme-select-none]')) {
      selected.clear()
      paintSelection()
    } else if (trigger.matches('[data-ferme-search]')) searchServer()
  })

  paintSelection()
})
