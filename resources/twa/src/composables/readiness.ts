/** Shares of the three-part bar of readiness (docs/04 A.20): learned / in progress / not started, in whole percent that add up to at most 100. */
export interface ReadinessSegments {
  learned: number
  inProgress: number
  notStarted: number
}

export function readinessSegments(words: { total: number; learned: number; in_progress: number }): ReadinessSegments {
  if (words.total <= 0) return { learned: 0, inProgress: 0, notStarted: 100 }
  const learned = Math.min(100, Math.max(0, (words.learned / words.total) * 100))
  const inProgress = Math.min(100 - learned, Math.max(0, (words.in_progress / words.total) * 100))
  return { learned, inProgress, notStarted: Math.max(0, 100 - learned - inProgress) }
}
