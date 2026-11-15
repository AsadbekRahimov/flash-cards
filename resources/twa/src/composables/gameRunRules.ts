/**
 * The rules of a run of Karvon as the learner is told them: the server's map does not send them (`flasks_max` comes only with a
 * run), so they stand in one place for every text that names them ("How to play", the sheet of a stop).
 */
export const GAME_RUN_RULES = { tasksMin: 8, tasksMax: 10, flasks: 3 } as const
