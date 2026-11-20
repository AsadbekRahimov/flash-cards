/**
 * "Reduce motion" of the device (`prefers-reduced-motion: reduce`): the CSS of the animations switches itself off with the
 * media query; this is for the motion driven by script (the smooth scroll of the error hunt board). A browser without
 * `matchMedia` has no such setting.
 */
export function prefersReducedMotion(): boolean {
  try {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
  } catch {
    return false
  }
}
