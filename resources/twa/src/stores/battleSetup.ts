import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import { incomingInvitation } from '@/stores/battle'
import { useCoursesStore } from '@/stores/courses'
import { useFriendsStore } from '@/stores/friends'
import type { BattleModeOption, BattleOptionsResponse, Course, PracticeMode } from '@/api/types'

export type SetupOptionsStatus = 'idle' | 'loading' | 'ready' | 'error'

/** Why the setup cannot go on at all: the friend is not a friend any more, battles are off, practice is not open to the learner. */
export type SetupBlock = 'friend' | 'off' | 'unavailable'

/** A lesson of the chosen course as the picker lists it: "Module 2 · Lesson 4 · Food". */
export interface SetupLesson {
  id: number
  stage: number
  number: number
  title: string | null
}

interface SetupState {
  friendshipId: number | null
  /** Slug of the chosen course. */
  course: string | null
  /** The chosen lesson; null: the whole course. */
  lessonId: number | null
  mode: PracticeMode | null
  /** Slugs of the learner's own decks: a deck is private and has no battles. The catalog lists one only as the main course. */
  deckSlugs: string[]
  /** Slugs the server refused for a battle (`course_not_found`): they are not offered any more. */
  refusedCourses: string[]
  options: BattleOptionsResponse | null
  optionsStatus: SetupOptionsStatus
  optionsError: string
  blocked: SetupBlock | null
  /** Number of the newest options request: an older answer that comes late is dropped. */
  optionsSeq: number
  inviting: boolean
  inviteError: string
  /** The learner's battle that is in the way (409 `battle_busy`): a link leads to it. */
  busyBattleId: number | null
  /** Show the paywall (`practice_pro_required`). */
  paywall: boolean
  /** Counts the setups the store has been reset for: a reply that comes back for an earlier one is dropped. */
  generation: number
}

/**
 * The setup of a battle (docs/16 §11.2): the friend (from the address), the course, the lesson, the format; then
 * "Invite". The catalog and the lessons are the ones of the courses store; the formats come from
 * `GET /practice/battles/options`, which says for each of them whether it can be played with this friend on this
 * material, and why not.
 */
export const useBattleSetupStore = defineStore('battleSetup', {
  state: (): SetupState => ({
    friendshipId: null,
    course: null,
    lessonId: null,
    mode: null,
    deckSlugs: [],
    refusedCourses: [],
    options: null,
    optionsStatus: 'idle',
    optionsError: '',
    blocked: null,
    optionsSeq: 0,
    inviting: false,
    inviteError: '',
    busyBattleId: null,
    paywall: false,
    generation: 0,
  }),

  getters: {
    /** The courses a battle can be played on: no decks, nothing the server refused, nothing without words. */
    courses(state): Course[] {
      return useCoursesStore().courses.filter(
        (course) => course.words_count > 0 && !state.deckSlugs.includes(course.slug) && !state.refusedCourses.includes(course.slug),
      )
    },

    /** The lessons of the chosen course, in the order of the modules. */
    lessons(state): SetupLesson[] {
      const detail = useCoursesStore().detail
      if (detail === null || detail.slug !== state.course) return []
      return detail.stages.flatMap((stage) => stage.lessons.map((lesson) => ({ id: lesson.id, stage: stage.number, number: lesson.number, title: lesson.title })))
    },

    /** The name of the friend, as far as it is known: the options carry it, the Friends list may have it earlier. */
    friendName(state): string | null {
      const named = state.options?.friend.first_name
      if (named) return named
      const friend = useFriendsStore().friends?.friends.find((item) => item.id === state.friendshipId)
      return friend?.first_name || null
    },

    selectedMode(state): BattleModeOption | null {
      return state.options?.modes.find((option) => option.mode === state.mode) ?? null
    },

    canInvite(state): boolean {
      const chosen = state.options?.modes.find((option) => option.mode === state.mode)
      return (
        !state.inviting &&
        state.blocked === null &&
        state.friendshipId !== null &&
        state.course !== null &&
        state.optionsStatus === 'ready' &&
        state.options?.friend_access === 'available' &&
        chosen?.state === 'available'
      )
    },
  },

  actions: {
    /** Starts a setup for the friend of the address; `course` and `lessonId` are what the entry (the hub) passed, if anything. */
    async begin(friendshipId: number | null, course: string | null, lessonId: number | null): Promise<void> {
      this.generation += 1
      const generation = this.generation
      this.$patch({
        friendshipId,
        course: null,
        lessonId: null,
        mode: null,
        refusedCourses: [],
        options: null,
        optionsStatus: 'idle',
        optionsError: '',
        blocked: null,
        inviting: false,
        inviteError: '',
        busyBattleId: null,
        paywall: false,
      })
      if (friendshipId === null) return

      const catalog = useCoursesStore()
      await Promise.all([catalog.load(), this.loadDecks()])
      if (generation !== this.generation || catalog.listStatus === 'error') return

      const wanted = this.courses.find((item) => item.slug === course) ?? this.courses.find((item) => item.is_active) ?? this.courses[0]
      if (wanted === undefined) return
      await this.chooseCourse(wanted.slug, wanted.slug === course ? lessonId : null)
    },

    /** The decks are asked for only to hide them; a failure leaves them in the list, and the server refuses a deck later. */
    async loadDecks(): Promise<void> {
      try {
        const res = await api.decks.list()
        this.deckSlugs = res.decks.map((deck) => deck.slug)
      } catch {
        this.deckSlugs = []
      }
    },

    /** The catalog could not be read: the screen asks again. */
    async reloadCatalog(): Promise<void> {
      await this.begin(this.friendshipId, this.course, this.lessonId)
    },

    async selectCourse(slug: string): Promise<void> {
      if (slug === this.course) return
      await this.chooseCourse(slug, null)
    },

    async selectLesson(lessonId: number | null): Promise<void> {
      if (lessonId === this.lessonId) return
      this.lessonId = lessonId
      await this.loadOptions()
    },

    selectMode(mode: PracticeMode): void {
      const option = this.options?.modes.find((item) => item.mode === mode)
      if (option?.state === 'available') this.mode = mode
    },

    /** The lessons of the course and the formats for it. A lesson from the address is checked against the lessons first. */
    async chooseCourse(slug: string, lessonId: number | null): Promise<void> {
      const generation = this.generation
      const catalog = useCoursesStore()
      this.course = slug
      this.lessonId = lessonId
      this.mode = null
      this.options = null

      if (lessonId === null) {
        await Promise.all([catalog.loadDetail(slug), this.loadOptions()])
        return
      }
      await catalog.loadDetail(slug)
      if (generation !== this.generation || this.course !== slug) return
      if (!this.lessons.some((lesson) => lesson.id === lessonId)) this.lessonId = null
      await this.loadOptions()
    },

    /** The lessons of the chosen course could not be read: ask again. */
    async reloadLessons(): Promise<void> {
      if (this.course !== null) await useCoursesStore().loadDetail(this.course)
    },

    async loadOptions(): Promise<void> {
      const friendshipId = this.friendshipId
      const course = this.course
      if (friendshipId === null || course === null) return

      const seq = ++this.optionsSeq
      const generation = this.generation
      this.optionsStatus = 'loading'
      this.optionsError = ''
      try {
        const options = await api.battles.options(friendshipId, course, this.lessonId)
        if (generation !== this.generation || seq !== this.optionsSeq) return
        this.options = options
        this.optionsStatus = 'ready'
        this.blocked = null
        this.keepMode()
      } catch (err) {
        if (generation !== this.generation || seq !== this.optionsSeq) return
        this.options = null
        this.optionsStatus = 'error'
        this.optionsError = errorMessage(err, 'battle')
        await this.explain(err, course)
      }
    },

    /** The mode stays chosen while it can be played, else the first one that can; none that can: none chosen. */
    keepMode(): void {
      const modes = this.options?.modes ?? []
      const current = modes.find((option) => option.mode === this.mode)
      this.mode = current?.state === 'available' ? current.mode : (modes.find((option) => option.state === 'available')?.mode ?? null)
    },

    /** What a refusal of the options (or of the invitation) means for the screen. */
    async explain(err: unknown, course: string): Promise<void> {
      if (!(err instanceof ApiClientError)) return
      const generation = this.generation
      switch (err.code) {
        case 'friendship_not_found':
        case 'not_friends':
          this.blocked = 'friend'
          break
        case 'battle_disabled':
          this.blocked = 'off'
          break
        case 'practice_unavailable':
          this.blocked = 'unavailable'
          break
        case 'practice_pro_required':
          this.paywall = true
          break
        case 'course_not_found': {
          // A deck, a private or unpublished course: not for a battle. The next course takes its place.
          this.refusedCourses.push(course)
          const next = this.courses.find((item) => item.is_active) ?? this.courses[0]
          if (next !== undefined && generation === this.generation) await this.chooseCourse(next.slug, null)
          break
        }
        case 'lesson_not_found':
        case 'lesson_not_in_course':
          if (this.lessonId !== null) {
            this.lessonId = null
            await this.loadOptions()
          }
          break
      }
    },

    /**
     * "Invite". Returns the id of the battle for the route, or null with the reason in `inviteError` / `paywall` / `blocked`. If the
     * friend has invited the learner at this very moment (409 `battle_busy` names THEIR invitation), the id is that invitation's: the
     * screen leads into it, where the learner accepts it - no refusal is shown.
     */
    async invite(): Promise<number | null> {
      if (!this.canInvite || this.friendshipId === null || this.course === null || this.mode === null) return null

      const generation = this.generation
      this.inviting = true
      this.inviteError = ''
      this.busyBattleId = null
      this.paywall = false
      try {
        const battle = await api.battles.invite({ friendship_id: this.friendshipId, mode: this.mode, course: this.course, lesson_id: this.lessonId })
        return generation === this.generation ? battle.id : null
      } catch (err) {
        if (generation !== this.generation) return null
        const theirs = await incomingInvitation(err)
        if (generation !== this.generation) return null
        if (theirs !== null) return theirs
        this.inviteError = errorMessage(err, 'battle')
        if (err instanceof ApiClientError) {
          if (err.code === 'battle_busy') this.busyBattleId = typeof err.details?.battle_id === 'number' ? err.details.battle_id : null
          // The numbers on the screen are old: read the formats again.
          if (['battle_material_unavailable', 'practice_mode_disabled', 'battle_friend_no_access'].includes(err.code)) await this.loadOptions()
          if (['friendship_not_found', 'not_friends', 'battle_disabled', 'practice_unavailable', 'practice_pro_required'].includes(err.code)) {
            await this.explain(err, this.course)
            if (this.paywall) this.inviteError = ''
          }
        }
        return null
      } finally {
        if (generation === this.generation) this.inviting = false
      }
    },

    dismissPaywall(): void {
      this.paywall = false
    },

    clearInviteError(): void {
      this.inviteError = ''
      this.busyBattleId = null
    },
  },
})
