import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { useExamStore } from '../exam'
import type { ExamAnswerResponse, ExamJoinResponse, ExamQuestionResponse } from '@/api/types'
vi.mock('@/api', () => ({ api: { exam: { join: vi.fn(), question: vi.fn(), answer: vi.fn(), result: vi.fn() } } }))
const question = (id: number): ExamQuestionResponse => ({ question_index: 0, word_id: id, word: `word-${id}`, options: ['a','b'], seconds_left: 10 })
const joined = (id: number): ExamJoinResponse => ({ session_id: id, ends_at: null, total_questions: 2, seconds_per_question: 10, question: question(id) })
function pending<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(yes => { resolve = yes })
  return { promise, resolve }
}
beforeEach(() => { setActivePinia(createPinia()); vi.resetAllMocks() })
describe('exam: delayed responses', () => {
  it('does not resume answering when the overall timer ended during submission', async () => {
    vi.mocked(api.exam.join).mockResolvedValue(joined(1))
    vi.mocked(api.exam.result).mockResolvedValue({ learner_score: 1, rank: 1, total_participants: 1, leaderboard: [] })
    const old = pending<ExamAnswerResponse>()
    vi.mocked(api.exam.answer).mockReturnValue(old.promise)
    const store = useExamStore()
    await store.join(1)
    const answer = store.submitAnswer(0)
    await store.finish()
    old.resolve({ is_correct: true, correct_option: 0, score_earned: 1, total_score: 1, has_next: true, next_question: question(2) })
    await answer
    expect(store.status).toBe('finished')
    expect(store.lastAnswer).toBeNull()
  })
  it('ignores a previous join after a different session has started', async () => {
    const old = pending<ExamJoinResponse>()
    vi.mocked(api.exam.join).mockReturnValueOnce(old.promise).mockResolvedValueOnce(joined(2))
    const store = useExamStore()
    const first = store.join(1)
    await store.join(2)
    old.resolve(joined(1))
    await first
    expect(store.currentQuestion?.word_id).toBe(2)
  })
})
