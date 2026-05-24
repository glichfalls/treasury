/**
 * Minimal, safe markdown renderer for AI-generated content (no markdown
 * dependency in the app): HTML-escape first, then render headings, [links](url),
 * **bold**, bullet lists, and paragraphs. Safe to use with v-html on trusted AI
 * output.
 */
export function renderMarkdown(md: string): string {
  const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  // [text](url) → anchor, http(s) only (the URL is already HTML-escaped, so a
  // stray scheme or quote can't break out). Unsafe URLs fall back to plain text.
  const link = (s: string) =>
    s.replace(/\[([^\]]+)\]\(([^)\s"]+)\)/g, (_m, text: string, url: string) =>
      /^https?:\/\//i.test(url)
        ? `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-[var(--color-accent)] underline underline-offset-2 hover:opacity-80">${text}</a>`
        : text,
    )
  const inline = (s: string) => link(esc(s)).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')

  let html = ''
  let inList = false
  const closeList = () => {
    if (inList) {
      html += '</ul>'
      inList = false
    }
  }

  for (const raw of md.split(/\r?\n/)) {
    const line = raw.trim()
    if (line === '') {
      closeList()
      continue
    }
    const heading = /^#{1,4}\s+(.*)$/.exec(line)
    const bullet = /^[-*]\s+(.*)$/.exec(line)
    if (heading) {
      closeList()
      html += `<h4 class="font-semibold mt-3 mb-1">${inline(heading[1] ?? '')}</h4>`
    } else if (bullet) {
      if (!inList) {
        html += '<ul class="list-disc pl-5 space-y-1">'
        inList = true
      }
      html += `<li>${inline(bullet[1] ?? '')}</li>`
    } else {
      closeList()
      html += `<p>${inline(line)}</p>`
    }
  }
  closeList()
  return html
}
