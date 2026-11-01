/** The CSS variable the toast stands on (`.toast` in style.css): the height of the sticky block of buttons on screen. */
export const ACTIONS_HEIGHT_VAR = '--app-actions-h'

/** The sticky blocks that are on screen now, by block: the tallest one is what the toast has to clear. */
const heights = new Map<HTMLElement, number>()

function publish(): void {
  const style = document.documentElement.style
  const height = Math.max(0, ...heights.values())
  if (height > 0) style.setProperty(ACTIONS_HEIGHT_VAR, `${height}px`)
  else style.removeProperty(ACTIONS_HEIGHT_VAR)
}

/**
 * Publishes the height of `el` (a `StickyActions` block) as `--app-actions-h` on <html> and keeps it right while the block grows or
 * shrinks (a button comes and goes, the window turns, the text wraps). The toast is `fixed` to the bottom edge of the window, which the
 * block covers: with the variable it stands on the block (docs/14 §4.3), as it stands on the tab bar. Returns the function that stops
 * following and takes the variable back; a screen without such a block leaves nothing behind.
 */
export function trackActionsHeight(el: HTMLElement): () => void {
  const measure = (): void => {
    heights.set(el, el.offsetHeight)
    publish()
  }
  measure()

  // Not everywhere (a test environment has none): the height at the moment of showing is what is known then.
  const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure)
  observer?.observe(el)

  return () => {
    observer?.disconnect()
    heights.delete(el)
    publish()
  }
}
