import { watch, type Ref } from 'vue'

/**
 * After a miss on a four-choice card the feedback sheet slides up over the bottom of the screen (kit Muhrla 05) and may cover the right
 * option when the sheet is tall (the example, the hooks) or the window is short. The right option (`data-state="reveal"`) is then scrolled
 * into view above the sheet: `--app-actions-h` (the height StickyActions publishes) is its bottom margin. Nothing moves when it is in
 * view already, nor after a right pick (the picked option is the right one and the sheet goes by itself).
 *
 * @param root the element of the card (the options are inside it)
 * @param isCorrect the verdict of the server; null until it comes
 */
export function useRevealInView(root: Ref<HTMLElement | null>, isCorrect: Ref<boolean | null>): void {
  watch(
    isCorrect,
    (right) => {
      if (right !== false) return
      const option = root.value?.querySelector<HTMLElement>('[role=radio][data-state=reveal]')
      if (!option || typeof option.scrollIntoView !== 'function') return
      option.style.scrollMarginBottom = 'calc(var(--app-actions-h, 0px) + 12px)'
      const still = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
      // On the next frame: the sheet has its height then, and the page has grown by it.
      const scroll = (): void => option.scrollIntoView({ block: 'nearest', behavior: still ? 'auto' : 'smooth' })
      if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(scroll)
      else scroll()
    },
    { flush: 'post' },
  )
}
