export interface Student {
  id: number
  first_name: string
  username?: string | null
  telegram_group_id: number
}

export interface AuthResponse {
  token: string
  expires_in: number
  student: Student
}

export interface MeResponse {
  student: Pick<Student, 'id' | 'first_name' | 'username'>
  group: { id: number; title: string } | null
  stats: {
    words_learned: number
    words_due_today: number
  }
}

export interface LessonHeader {
  stage: number | null
  lesson: number
  title: string | null
}

export interface TrainingStartResponse {
  session_id: number
  lesson: LessonHeader
  total_words: number
}

export type CardKind = 'due' | 'new'

export interface Card {
  word_id: number
  word: string
  translation: string
  example: string | null
  transcription: string | null
  card_kind: CardKind
}

export interface NextCardResponse {
  card: Card | null
  progress: { done: number; total: number }
}

export type Quality = 2 | 3 | 5

export interface ReviewResponse {
  next_review_at: string | null
  new_interval_days: number
  easiness_factor: number
}

export interface ApiError {
  error: {
    code: string
    message: string
    details?: Record<string, unknown>
  }
}
