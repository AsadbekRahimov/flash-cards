import { nextTick, onBeforeUnmount, onMounted, type Ref } from 'vue'

const openDialogs: HTMLElement[] = []
const focusable = 'a[href], button, input, select, textarea, [contenteditable="true"], [tabindex]'

/** Keeps keyboard navigation in the top dialog and returns focus to its opener. */
export function useDialogFocus(dialog: Ref<HTMLElement | null>, dismiss: () => void): void {
  let element: HTMLElement | null = null
  let opener: HTMLElement | null = null

  function onKeydown(event: KeyboardEvent): void {
    if (element === null || openDialogs.at(-1) !== element || event.defaultPrevented) return

    if (event.key === 'Escape') {
      dismiss()
      return
    }
    if (event.key !== 'Tab') return

    const items = Array.from(element.querySelectorAll<HTMLElement>(focusable)).filter(
      (item) => item.tabIndex >= 0 && !item.matches(':disabled') &&
        item.getClientRects().length > 0 && getComputedStyle(item).visibility === 'visible',
    )
    const current = items.indexOf(document.activeElement as HTMLElement)
    const atEdge = event.shiftKey ? current === 0 : current === items.length - 1
    if (current === -1 || atEdge) {
      event.preventDefault()
      const next = (event.shiftKey ? items.at(-1) : items[0]) ?? element
      next.focus()
    }
  }

  onMounted(() => {
    element = dialog.value
    if (element === null) return
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    openDialogs.push(element)
    document.addEventListener('keydown', onKeydown)
    void nextTick(() => {
      if (element !== null && openDialogs.at(-1) === element) element.focus()
    })
  })

  onBeforeUnmount(() => {
    if (element === null) return
    const wasTop = openDialogs.at(-1) === element
    openDialogs.splice(openDialogs.indexOf(element), 1)
    document.removeEventListener('keydown', onKeydown)
    element = null
    if (wasTop && opener?.isConnected) opener.focus()
  })
}
