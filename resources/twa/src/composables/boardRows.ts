/** A row of a rating table: the top and the pages after it share these fields (`pro_emoji` absent on an older server). */
export interface BoardTableRow {
  rank: number
  name: string | null
  points: number
  is_me: boolean
  pro_emoji?: string | null
  long_words?: number
  active_days?: number
}

/** The learner's row as the table needs it: the place (null - none) and the points. */
export interface BoardTableMe {
  rank: number | null
  points: number
  participant: boolean
}

/** Tells the rows apart for "is this neighbour shown already": shared places repeat a rank, so the facts go in too. */
export const boardRowKey = (row: BoardTableRow): string =>
  `${row.rank}|${row.points}|${row.long_words ?? ''}|${row.active_days ?? ''}|${row.name ?? ''}`

/**
 * The place "to" of "To 4th place — 8 points": the server counts `next_gap` to the nearest member above me in the table, who may have as
 * many points as I and win on the long repetitions or the days - the nearest better rank among the rows on screen, whatever their
 * points; null - none on screen ("to the place above").
 */
export function nearestBetterRank(rows: readonly BoardTableRow[], rank: number): number | null {
  let nearest: number | null = null
  for (const row of rows) {
    if (row.rank < rank && (nearest === null || row.rank > nearest)) nearest = row.rank
  }
  return nearest
}
